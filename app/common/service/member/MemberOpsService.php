<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\MoneyMath;

use app\common\support\AppTime;
use app\common\support\QueryLimit;

use app\common\support\ServiceResult;
use app\common\service\member\MemberPointService;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberService;
use app\common\model\MemberCancelRequest;

use app\common\model\Role;
use app\common\model\User;
use app\common\model\UserRole;
use app\common\support\ContentSearchService;
use app\common\support\OpsLog;
use think\facade\Db;

class MemberOpsService
{

    public function __construct(
        private readonly MemberService $memberService,
        private readonly MemberLevelService $memberLevelService,
        private readonly MemberPointService $memberPointService,
    ) {
    }

    public function countInactiveMembers(): int
    {
        $roleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($roleId < 1) {
            return 0;
        }

        return (int) UserRole::usersQuery($roleId)
            ->where('u.status', 0)
            ->count('DISTINCT u.id');
    }

    public function countLevelExpiringSoon(int $withinDays = 7): int
    {
        $roleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($roleId < 1 || $withinDays < 1) {
            return 0;
        }
        $until = AppTime::format('Y-m-d H:i:s', time() + $withinDays * 86400);

        return (int) UserRole::usersQuery($roleId)
            ->where('u.status', 1)
            ->whereNotNull('u.member_level_expire_at')
            ->where('u.member_level_expire_at', '<>', '')
            ->where('u.member_level_expire_at', '<=', $until)
            ->where('u.member_level_expire_at', '>=', AppTime::now())
            ->count('DISTINCT u.id');
    }

    public function countPendingCancel(): int
    {
        try {
            return (int) MemberCancelRequest::where('status', MemberCancelService::STATUS_PENDING)->count();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('member_pending_cancel_count_failed', ['msg' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function exportRowsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $memberRoleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($memberRoleId < 1) {
            return [];
        }

        $memberUserIds = UserRole::where('role_id', $memberRoleId)->column('user_id');
        if ($memberUserIds === []) {
            return [];
        }

        $allowedIds = array_values(array_intersect($ids, array_map('intval', $memberUserIds)));
        if ($allowedIds === []) {
            return [];
        }

        $rows = User::with(['memberLevel' => static function ($levelQuery): void {
            $levelQuery->field('id,name');
        }])
            ->whereIn('id', $allowedIds)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return $this->memberService->enrichListRows($rows);
    }

    public function exportRows(array $params): array
    {
        $page  = 1;
        $limit = QueryLimit::ADMIN_UNBOUNDED;
        $rows  = [];
        do {
            $paginator = $this->memberService->listAdmin(
                $page,
                min(500, $limit),
                (string) ($params['keyword'] ?? ''),
                (int) ($params['level_id'] ?? 0),
                (int) ($params['blacklist'] ?? -1),
                (string) ($params['source'] ?? ''),
                (string) ($params['date_from'] ?? ''),
                (string) ($params['date_to'] ?? ''),
                (string) ($params['filter'] ?? ''),
                (string) ($params['mobile'] ?? ''),
                (string) ($params['account_kind'] ?? '')
            );
            $batch = $paginator->items();
            if (!is_array($batch) || $batch === []) {
                break;
            }
            foreach ($batch as $item) {
                $rows[] = is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : (array) $item;
            }
            if (count($rows) >= $limit) {
                break;
            }
            $page++;
        } while ($page <= (int) $paginator->lastPage());

        return $this->memberService->enrichListRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    public function exportTableRows(array $rows): array
    {
        $headers = ['ID', '用户名', '昵称', '类型', '企业名称', '等级', '积分', '余额', '成长值', '状态', '注册时间'];
        $lines   = [];
        foreach ($rows as $row) {
            $lines[] = [
                (int) ($row['id'] ?? 0),
                (string) ($row['username'] ?? ''),
                (string) ($row['nickname'] ?? ''),
                (string) ($row['account_kind_label'] ?? MemberAccountKind::label($row['account_kind'] ?? '')),
                (string) ($row['company_name'] ?? ''),
                (string) ($row['member_level_name'] ?? ''),
                (int) ($row['member_points'] ?? 0),
                MoneyMath::formatPlain((float) ($row['member_balance'] ?? 0)),
                (int) ($row['member_growth'] ?? 0),
                (int) ($row['status'] ?? 0) === 1 ? '正常' : '未激活',
                (string) ($row['created_at'] ?? ''),
            ];
        }

        return [$headers, $lines];
    }

    public function buildCsv(array $rows): string
    {
        $lines   = [];
        $lines[] = implode(',', ['ID', '用户名', '昵称', '类型', '企业名称', '等级', '积分', '余额', '成长值', '状态', '注册时间']);
        foreach ($rows as $row) {
            $lines[] = implode(',', [
                (int) ($row['id'] ?? 0),
                $this->csvCell((string) ($row['username'] ?? '')),
                $this->csvCell((string) ($row['nickname'] ?? '')),
                $this->csvCell((string) ($row['account_kind_label'] ?? MemberAccountKind::label($row['account_kind'] ?? ''))),
                $this->csvCell((string) ($row['company_name'] ?? '')),
                $this->csvCell((string) ($row['member_level_name'] ?? '')),
                (int) ($row['member_points'] ?? 0),
                MoneyMath::formatPlain((float) ($row['member_balance'] ?? 0)),
                (int) ($row['member_growth'] ?? 0),
                (int) ($row['status'] ?? 0) === 1 ? '正常' : '未激活',
                $this->csvCell((string) ($row['created_at'] ?? '')),
            ]);
        }

        return "\xEF\xBB\xBF" . implode("\n", $lines);
    }

    /**
     * @param list<int> $userIds
     * @return ServiceResult
     */
    public function batchAdjust(
        array $userIds,
        int $levelId,
        int $pointDelta,
        string $pointReason,
        int $adminId = 0
    ): ServiceResult {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return ServiceResult::fail('请选择会员');
        }
        if ($levelId < 1 && $pointDelta === 0) {
            return ServiceResult::fail('请指定等级或积分变动');
        }
        if ($levelId > 0 && !$this->memberLevelService->isValidLevelId($levelId)) {
            return ServiceResult::fail('会员等级无效');
        }

        $updated = 0;
        Db::startTrans();
        try {
            foreach ($userIds as $uid) {
                if (!$this->memberService->hasMemberRole($uid)) {
                    continue;
                }
                if ($levelId > 0) {
                    User::where('id', $uid)->update(['member_level_id' => $levelId]);
                    $updated++;
                }
                if ($pointDelta !== 0) {
                    $res = $this->memberPointService->adjust(
                        $uid,
                        $pointDelta,
                        $pointReason !== '' ? $pointReason : '批量调整',
                        $adminId
                    );
                    if (!$res->isOk()) {
                        throw new \RuntimeException((string) ($res->message() ?? '积分调整失败'));
                    }
                    $updated++;
                }
            }
            if ($updated < 1) {
                Db::rollback();

                return ServiceResult::fail('未更新任何会员');
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            OpsLog::businessWarning('member_batch_adjust_failed', [
                'msg' => $e->getMessage(),
            ]);

            return ServiceResult::fail('批量调整失败，已回滚：' . $e->getMessage());
        }

        return ServiceResult::ok(['updated' => $updated], "已处理 {$updated} 项变更");
    }

    private function csvCell(string $value): string
    {
        $value = str_replace(["\r", "\n", '"'], [' ', ' ', '""'], $value);

        return '"' . $value . '"';
    }
}
