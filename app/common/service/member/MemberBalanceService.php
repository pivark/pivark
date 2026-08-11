<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\model\MemberBalanceLog;
use app\common\model\User;

use app\common\support\HtmlSanitizer;
use app\common\support\ModelRelationLoad;
use app\common\service\infra\DistributedLockService;
use think\facade\Db;

/** 会员余额（后台手动调整，支付对接前预留） */
class MemberBalanceService
{

    public function __construct(
        private readonly MemberRoleCheckService $memberRoleCheckService,
        private readonly DistributedLockService $distributedLock,
    ) {
    }

    public function balance(int $userId): float
    {
        if ($userId < 1) {
            return 0.0;
        }

        return round((float) User::where('id', $userId)->value('member_balance'), 2);
    }

    /**
     * @return array{list:list<array>,total:int}
     */
    public function listAdmin(int $page = 1, int $limit = 20, int $userId = 0, string $keyword = ''): array
    {
        $query = MemberBalanceLog::with(['user' => static function ($userQuery): void {
            $userQuery->field('id,username,nickname');
        }])->order('id', 'desc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($keyword !== '') {
            if (ctype_digit($keyword)) {
                $query->where('user_id', (int) $keyword);
            } else {
                $like = '%' . addcslashes($keyword, '%_\\') . '%';
                $query->where(function ($sub) use ($like): void {
                    $sub->whereLike('reason', $like);
                    $sub->whereOr(function ($userScope) use ($like): void {
                        $userScope->whereHas('user', static function ($userQuery) use ($like): void {
                            $userQuery->whereLike('username|nickname', $like);
                        });
                    });
                });
            }
        }
        $paginator = $query->paginate(['list_rows' => $limit, 'page' => $page]);
        $list      = ModelRelationLoad::mapBelongsTo($paginator->items(), 'user', ['username', 'nickname']);

        return ['list' => $list, 'total' => (int) $paginator->total()];
    }

    /**
     * @return array{list:list<array>,total:int}
     */
    public function listForUser(int $userId, int $page = 1, int $limit = 20): array
    {
        if ($userId < 1) {
            return ['list' => [], 'total' => 0];
        }

        return $this->listAdmin($page, $limit, $userId);
    }

    /** 支付订单号 → 余额流水幂等 ref */
    public static function payRef(string $orderNo): string
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return '';
        }

        return MemberBalanceLog::REF_PREFIX_PAY . mb_substr($orderNo, 0, 60);
    }

    /**
     * @return ServiceResult
     */
    public function adjust(int $userId, float $delta, string $reason, int $adminId = 0, string $ref = '', bool $allowNegative = false): ServiceResult
    {
        if ($userId < 1 || !$this->memberRoleCheckService->isMemberAccount($userId)) {
            return ServiceResult::fail('会员不存在');
        }
        if (abs($delta) < 0.01) {
            return ServiceResult::fail('变动金额不能为 0');
        }
        $reason = HtmlSanitizer::cleanPlainText($reason, 200);
        if ($reason === '') {
            return ServiceResult::fail('请填写变动说明');
        }
        $ref = mb_substr(trim($ref), 0, 64);
        if ($ref !== '' && $this->hasRef($ref)) {
            return ServiceResult::ok(null, '已入账');
        }

        $locked = $this->distributedLock->using(
            'member:balance:' . $userId,
            15,
            function () use ($userId, $delta, $reason, $adminId, $ref, $allowNegative): ServiceResult {
                return $this->adjustLocked($userId, $delta, $reason, $adminId, $ref, $allowNegative);
            }
        );
        if ($locked === null) {
            return ServiceResult::fail('余额调整处理中，请稍后重试');
        }

        return $locked;
    }

    /**
     * @return ServiceResult
     */
    private function adjustLocked(int $userId, float $delta, string $reason, int $adminId = 0, string $ref = '', bool $allowNegative = false): ServiceResult
    {
        $ref = mb_substr(trim($ref), 0, 64);
        if ($ref !== '' && $this->hasRef($ref)) {
            return ServiceResult::ok(null, '已入账');
        }

        Db::startTrans();
        try {
            $user = User::where('id', $userId)->lock(true)->find();
            if (!$user) {
                Db::rollback();

                return ServiceResult::fail('会员不存在');
            }
            $balance = round((float) ($user['member_balance'] ?? 0) + $delta, 2);
            if (!$allowNegative && $balance < 0) {
                Db::rollback();

                return ServiceResult::fail('余额不足，无法扣减');
            }
            User::where('id', $userId)->update(['member_balance' => $balance]);
            $log = [
                'user_id'    => $userId,
                'delta'      => round($delta, 2),
                'balance'    => $balance,
                'reason'     => $reason,
                'admin_id'   => max(0, $adminId),
                'created_at' => AppTime::now(),
            ];
            if ($ref !== '') {
                $log['ref'] = $ref;
            }
            MemberBalanceLog::insert($log);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail('操作失败');
        }

        return ServiceResult::ok(null, '余额已调整');
    }

    private function hasRef(string $ref): bool
    {
        $ref = mb_substr(trim($ref), 0, 64);
        if ($ref === '') {
            return false;
        }

        return MemberBalanceLog::where('ref', $ref)->value('id') !== null;
    }
}
