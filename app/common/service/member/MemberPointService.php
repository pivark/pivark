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
use app\common\model\MemberPointLog;
use app\common\model\User;

use app\common\support\HtmlSanitizer;
use app\common\support\ModelRelationLoad;
use think\facade\Db;

/** 会员积分 */
class MemberPointService
{

    public function __construct(
        private readonly MemberRoleCheckService $memberRoleCheckService,
    ) {
    }

    public function balance(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        return (int) User::where('id', $userId)->value('member_points');
    }

    /**
     * @return array{list:list<array>,total:int}
     */
    public function listAdmin(int $page = 1, int $limit = 20, int $userId = 0, string $keyword = ''): array
    {
        $query = MemberPointLog::with(['user' => static function ($userQuery): void {
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

    /**
     * @return ServiceResult
     */
    public function adjust(int $userId, int $delta, string $reason, int $adminId = 0): ServiceResult
    {
        if ($userId < 1 || !$this->memberRoleCheckService->hasMemberRole($userId)) {
            return ServiceResult::fail('会员不存在');
        }
        if ($delta === 0) {
            return ServiceResult::fail('变动积分不能为 0');
        }
        $reason = HtmlSanitizer::cleanPlainText($reason, 200);
        if ($reason === '') {
            return ServiceResult::fail('请填写变动说明');
        }

        Db::startTrans();
        try {
            $user = User::where('id', $userId)->lock(true)->find();
            if (!$user) {
                Db::rollback();

                return ServiceResult::fail('会员不存在');
            }
            $balance = (int) ($user['member_points'] ?? 0) + $delta;
            if ($balance < 0) {
                Db::rollback();

                return ServiceResult::fail('积分不足，无法扣减');
            }
            User::where('id', $userId)->update(['member_points' => $balance]);
            MemberPointLog::insert([
                'user_id'    => $userId,
                'delta'      => $delta,
                'balance'    => $balance,
                'reason'     => $reason,
                'admin_id'   => max(0, $adminId),
                'created_at' => AppTime::now(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail('操作失败');
        }

        return ServiceResult::ok(null, '积分已调整');
    }

    /** @return ServiceResult */
    public function grant(int $userId, int $points, string $reason): ServiceResult
    {
        if ($points < 1) {
            return ServiceResult::ok(null, 'skip');
        }

        return $this->adjust($userId, $points, $reason, 0);
    }
}
