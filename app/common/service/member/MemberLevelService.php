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

use app\common\support\QueryLimit;
use app\common\support\ServiceResult;
use app\common\model\Role;
use app\common\model\User;
use app\common\model\Document;
use app\common\model\Tag;

use app\common\service\audit\AuditLogService;
use app\common\service\admin\AdminSpaMemberFormMetaCacheService;
use app\common\model\MemberLevel;
use app\common\support\AdminListParams;
use think\facade\Db;

/** 会员等级与文档阅读权限 */
class MemberLevelService
{

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly AdminSpaMemberFormMetaCacheService $memberFormMetaCache,
    ) {
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public function listActiveOptions(): array
    {
        $rows = MemberLevel::where('status', 1)
            ->order('rank', 'asc')
            ->order('id', 'asc')
            ->field('id,name')
            ->select()
            ->toArray();

        return array_values(array_map(static function (array $row): array {
            return [
                'id'   => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
            ];
        }, $rows));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(): array
    {
        return MemberLevel::where('status', 1)
            ->order('rank', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAdmin(): array
    {
        return $this->listAdminPaged(['limit' => QueryLimit::ADMIN_UNBOUNDED])['list'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listAdminPaged(array $params = []): array
    {
        $p     = AdminListParams::parse($params);
        $query = MemberLevel::order('rank', 'asc')->order('id', 'asc');
        AdminListParams::applyKeyword($query, $p['keyword'], 'name');
        $total = (int) $query->count();
        $list  = $query->page($p['page'], $p['limit'])->select()->toArray();
        $list  = $this->attachMemberCounts($list);

        return ['list' => $list, 'total' => $total, 'page' => $p['page'], 'limit' => $p['limit']];
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return list<array<string, mixed>>
     */
    private function attachMemberCounts(array $list): array
    {
        if ($list === []) {
            return $list;
        }

        $levelIds = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $list
        )));
        $memberCounts = $this->countMembersByLevelIds($levelIds);

        foreach ($list as &$row) {
            $id = (int) ($row['id'] ?? 0);
            $row['member_count'] = $memberCounts[$id] ?? 0;
        }
        unset($row);

        return $list;
    }

    /**
     * @param list<int> $levelIds
     * @return array<int, int>
     */
    private function countMembersByLevelIds(array $levelIds): array
    {
        if ($levelIds === []) {
            return [];
        }

        $memberRoleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($memberRoleId < 1) {
            return array_fill_keys($levelIds, 0);
        }

        $counts = array_fill_keys($levelIds, 0);

        $rows = User::alias('u')
            ->join('user_roles ur', 'ur.user_id = u.id')
            ->where('ur.role_id', $memberRoleId)
            ->whereIn('u.member_level_id', $levelIds)
            ->field('u.member_level_id, COUNT(DISTINCT u.id) as cnt')
            ->group('u.member_level_id')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $levelId = (int) ($row['member_level_id'] ?? 0);
            if (isset($counts[$levelId])) {
                $counts[$levelId] = (int) ($row['cnt'] ?? 0);
            }
        }

        $defaultId = $this->defaultLevelId();
        if ($defaultId > 0 && in_array($defaultId, $levelIds, true)) {
            $counts[$defaultId] += (int) User::alias('u')
                ->join('user_roles ur', 'ur.user_id = u.id')
                ->where('ur.role_id', $memberRoleId)
                ->where('u.member_level_id', 0)
                ->count('DISTINCT u.id');
        }

        return $counts;
    }

    /** @return array<string, mixed>|null */
    public function findAdmin(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = MemberLevel::find($id);

        return $row ? $row->toArray() : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id = max(0, (int) ($data['id'] ?? 0));
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) {
            return ServiceResult::fail('等级名称须为 1~50 字');
        }

        $rank = (int) ($data['rank'] ?? 0);
        if ($rank < 0 || $rank > 99999) {
            return ServiceResult::fail('权限权重须在 0~99999');
        }

        $status = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
        $isDefault = (int) ($data['is_default'] ?? 0) === 1 ? 1 : 0;
        if ($status === 0) {
            $isDefault = 0;
        }
        $now = AppTime::now();
        $isEdit = $id > 0;

        $payload = [
            'name'       => $name,
            'rank'       => $rank,
            'status'     => $status,
            'is_default' => $isDefault,
            'updated_at' => $now,
        ];

        if ($id > 0 && !$this->findAdmin($id)) {
            return ServiceResult::fail('等级不存在');
        }

        Db::transaction(function () use (&$id, $payload, $isDefault, $now): void {
            if ($id > 0) {
                MemberLevel::where('id', $id)->update($payload);
            } else {
                $payload['created_at'] = $now;
                $id = (int) MemberLevel::insertGetId($payload);
            }

            if ($isDefault === 1) {
                MemberLevel::where('id', '<>', $id)->update(['is_default' => 0, 'updated_at' => $now]);
            } elseif (MemberLevel::where('is_default', 1)->where('status', 1)->count() < 1) {
                MemberLevel::where('id', $id)->update(['is_default' => 1, 'updated_at' => $now]);
            }
        });

        $this->auditLog->operate(
            $isEdit ? '保存会员等级' : '新增会员等级',
            'admin.member.level',
            ['level_id' => $id, 'name' => $name]
        );
        $this->memberFormMetaCache->bust();

        return ServiceResult::ok(['id' => $id], '保存成功');
    }

    /**
     * @return ServiceResult
     */
    public function deleteAdmin(int $id): ServiceResult
    {
        if ($id < 1 || !$this->findAdmin($id)) {
            return ServiceResult::fail('等级不存在');
        }

        if ((int) MemberLevel::count() <= 1) {
            return ServiceResult::fail('至少保留一个会员等级');
        }

        $userCount = (int) User::where('member_level_id', $id)->count();
        if ($userCount > 0) {
            return ServiceResult::fail("仍有 {$userCount} 名会员使用该等级，请改等级或禁用而非删除");
        }

        $docCount = (int) Document::where('read_level_id', $id)->whereNull('deleted_at')->count();
        if ($docCount > 0) {
            return ServiceResult::fail("仍有 {$docCount} 篇文档引用该等级，请调整阅读权限或禁用而非删除");
        }

        $wasDefault = (int) (MemberLevel::where('id', $id)->value('is_default') ?? 0) === 1;
        Db::transaction(function () use ($id, $wasDefault): void {
            MemberLevel::where('id', $id)->delete();
            if (!$wasDefault) {
                return;
            }
            $fallback = MemberLevel::where('status', 1)->order('rank', 'asc')->order('id', 'asc')->find();
            if ($fallback instanceof MemberLevel) {
                MemberLevel::where('id', (int) $fallback->id)->update([
                    'is_default' => 1,
                    'updated_at' => AppTime::now(),
                ]);
            }
        });

        $this->auditLog->operate('删除会员等级', 'admin.member.level', ['level_id' => $id]);
        $this->memberFormMetaCache->bust();

        return ServiceResult::ok(null, '删除成功');
    }

    public function defaultLevelId(): int
    {
        $id = (int) MemberLevel::where('is_default', 1)->where('status', 1)->value('id');

        return $id > 0 ? $id : (int) (MemberLevel::where('status', 1)->order('rank', 'asc')->value('id') ?? 0);
    }

    public function getRank(int $levelId): int
    {
        if ($levelId < 1) {
            return 0;
        }

        return (int) (MemberLevel::where('id', $levelId)->value('rank') ?? 0);
    }

    public function getName(int $levelId): string
    {
        if ($levelId < 1) {
            return '';
        }

        return (string) (MemberLevel::where('id', $levelId)->value('name') ?? '');
    }

    public function isValidLevelId(int $levelId): bool
    {
        if ($levelId < 1) {
            return false;
        }

        return MemberLevel::where('id', $levelId)->where('status', 1)->count() > 0;
    }

    /**
     * @param array<string, mixed>|Document $document
     * @param array<string, mixed>|null $member
     */
    public function canReadDocument(array|Document $document, ?array $member = null): bool
    {
        if ($document instanceof Document) {
            $document = $document->toArray();
        }

        return $this->canReadRestrictedRow($document, $member);
    }

    /**
     * @param array<string, mixed>|Tag $tag
     * @param array<string, mixed>|null $member
     */
    public function canReadTag(array|Tag $tag, ?array $member = null): bool
    {
        if ($tag instanceof Tag) {
            $tag = $tag->toArray();
        }

        return $this->canReadRestrictedRow($tag, $member);
    }

    /**
     * @param array<string, mixed> $row 含 read_perm / read_level_id
     * @param array<string, mixed>|null $member
     */
    public function canReadRestrictedRow(array $row, ?array $member = null): bool
    {
        if ((int) ($row['read_perm'] ?? 0) === 0) {
            return true;
        }
        if ($member === null || empty($member['id'])) {
            return false;
        }

        $requiredLevelId = (int) ($row['read_level_id'] ?? 0);
        if ($requiredLevelId < 1) {
            return true;
        }

        $memberLevelId = (int) ($member['member_level_id'] ?? 0);
        if ($memberLevelId < 1) {
            $memberLevelId = $this->defaultLevelId();
        }

        return $this->getRank($memberLevelId) >= $this->getRank($requiredLevelId);
    }

    /**
     * @return array{read_perm:int,read_level_id:int}
     */
    public function parseReadAccess(string $value): array
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return ['read_perm' => 0, 'read_level_id' => 0];
        }
        if ($value === 'login' || $value === '1') {
            return ['read_perm' => 1, 'read_level_id' => 0];
        }
        if (str_starts_with($value, 'level:')) {
            $levelId = (int) substr($value, 6);

            return [
                'read_perm'      => 1,
                'read_level_id'  => $this->isValidLevelId($levelId) ? $levelId : 0,
            ];
        }

        return ['read_perm' => 0, 'read_level_id' => 0];
    }

    public function encodeReadAccess(int $readPerm, int $readLevelId): string
    {
        if ($readPerm === 0) {
            return '0';
        }
        if ($readLevelId > 0) {
            return 'level:' . $readLevelId;
        }

        return 'login';
    }

    /**
     * @param array<string, mixed> $data
     * @return array{read_perm:int,read_level_id:int}
     */
    public function readAccessFromPost(array $data): array
    {
        if (isset($data['read_access']) && is_string($data['read_access'])) {
            return $this->parseReadAccess($data['read_access']);
        }

        $readPerm = (int) ($data['read_perm'] ?? 0) === 1 ? 1 : 0;
        $readLevelId = max(0, (int) ($data['read_level_id'] ?? 0));
        if ($readPerm === 1 && $readLevelId > 0 && !$this->isValidLevelId($readLevelId)) {
            $readLevelId = 0;
        }

        return ['read_perm' => $readPerm, 'read_level_id' => $readPerm === 1 ? $readLevelId : 0];
    }

    public function resolveMemberLevelId(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }
        $levelId = (int) User::where('id', $userId)->value('member_level_id');

        return $levelId > 0 ? $levelId : $this->defaultLevelId();
    }
}
