<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\model\AdminUserNavScope;
use app\common\model\AdminUserTagScope;
use app\common\model\Tag;
use app\common\service\site\SiteNavService;
use app\common\service\tag\TagService;
use app\common\service\user\PermissionService;
use think\facade\Session;

/**
 * 后台管理员数据范围：栏目（发文/品项）+ Tag（聚合管理）。
 * 内容可管范围认 site_nav；Tag 范围只限标签管理。
 */
class AdminTagScopeService
{

    public function __construct(
        private readonly TagService $tagService,
    ) {
    }

    /** @var list<array{id:int,parent_id:int}>|null */
    private static ?array $tagRowsCache = null;

    /** @var array<string, list<int>> */
    private static array $expandCache = [];
    /**
     * @return list<int>
     */
    public function scopedTagIdsForUser(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }

        return array_values(array_map('intval', AdminUserTagScope::where('user_id', $userId)
            ->column('tag_id')));
    }

    public function isRestricted(int $userId): bool
    {
        if ($userId < 1 || app(PermissionService::class)->isSuperAdmin($userId)) {
            return false;
        }

        return $this->scopedTagIdsForUser($userId) !== [];
    }

    /**
     * @return list<int> 含子栏目的可管理标签 ID
     */
    public function allowedTagIds(int $userId): array
    {
        if ($userId < 1 || !$this->isRestricted($userId)) {
            return [];
        }

        return $this->expandWithDescendants($this->scopedTagIdsForUser($userId));
    }

    public function canManageTag(int $userId, int $tagId): bool
    {
        if ($tagId < 1) {
            return true;
        }
        if ($userId < 1 || !$this->isRestricted($userId)) {
            return true;
        }

        return in_array($tagId, $this->allowedTagIds($userId), true);
    }

    public function assertCanManageTag(int $userId, int $tagId): ?ServiceResult
    {
        if ($this->canManageTag($userId, $tagId)) {
            return null;
        }

        return ServiceResult::fail('无权管理该标签');
    }

    public function currentUserId(): int
    {
        $admin = Session::get('admin_user');

        return is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;
    }

    public function assertCurrentCanManageTag(int $tagId): ?ServiceResult
    {
        return $this->assertCanManageTag($this->currentUserId(), $tagId);
    }

    /**
     * @param \think\db\Query|\think\Model $query 别名为 t 的 Tag 查询
     */
    public function applyTagQueryScope($query, string $alias = 't'): void
    {
        $userId = $this->currentUserId();
        if ($userId < 1 || !$this->isRestricted($userId)) {
            return;
        }
        $ids = $this->allowedTagIds($userId);
        if ($ids === []) {
            $query->where($alias . '.id', 0);

            return;
        }
        $query->whereIn($alias . '.id', $ids);
    }

    /**
     * @param list<int> $tagIds
     * @return list<int>
     */
    public function expandWithDescendants(array $tagIds): array
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds))));
        if ($tagIds === []) {
            return [];
        }
        $cacheKey = implode(',', $tagIds);
        if (isset(self::$expandCache[$cacheKey])) {
            return self::$expandCache[$cacheKey];
        }

        $rows = $this->tagRowsForExpand();
        $out  = array_fill_keys($tagIds, true);
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($rows as $row) {
                $id = $row['id'];
                $pid = $row['parent_id'];
                if ($id < 1 || $pid < 1 || isset($out[$id])) {
                    continue;
                }
                if (isset($out[$pid])) {
                    $out[$id] = true;
                    $changed = true;
                }
            }
        }

        /** @var list<int> $result */
        $result = array_map('intval', array_keys($out));
        self::$expandCache[$cacheKey] = $result;

        return $result;
    }

    /** @return list<array{id:int,parent_id:int}> */
    private function tagRowsForExpand(): array
    {
        if (self::$tagRowsCache !== null) {
            return self::$tagRowsCache;
        }
        $rows = [];
        foreach (Tag::field('id,parent_id')->select()->toArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'id'        => (int) ($row['id'] ?? 0),
                'parent_id' => (int) ($row['parent_id'] ?? 0),
            ];
        }
        self::$tagRowsCache = $rows;

        return self::$tagRowsCache;
    }

    public function clearTagScopeCache(): void
    {
        self::$tagRowsCache = null;
        self::$expandCache  = [];
    }

    /**
     * @param list<int> $tagIds
     */
    public function saveForUser(int $userId, array $tagIds): void
    {
        if ($userId < 1) {
            return;
        }
        AdminUserTagScope::where('user_id', $userId)->delete();
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds))));
        if ($tagIds === []) {
            return;
        }
        $now = AppTime::now();
        foreach ($tagIds as $tagId) {
            if ($tagId > 0) {
                AdminUserTagScope::insert([
                    'user_id'    => $userId,
                    'tag_id'     => $tagId,
                    'created_at' => $now,
                ]);
            }
        }
    }

    /**
     * @return list<int>
     */
    public function scopedNavIdsForUser(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }

        return array_values(array_map('intval', AdminUserNavScope::where('user_id', $userId)
            ->column('nav_id')));
    }

    public function isNavRestricted(int $userId): bool
    {
        if ($userId < 1 || app(PermissionService::class)->isSuperAdmin($userId)) {
            return false;
        }

        return $this->scopedNavIdsForUser($userId) !== [];
    }

    /**
     * @return list<int> 含子栏目
     */
    public function allowedNavIds(int $userId): array
    {
        if ($userId < 1 || !$this->isNavRestricted($userId)) {
            return [];
        }
        $navSvc = app(SiteNavService::class);
        $out = [];
        foreach ($this->scopedNavIdsForUser($userId) as $navId) {
            foreach ($navSvc->contentCategorySelfAndDescendantIds($navId) as $id) {
                $out[$id] = true;
            }
        }

        return array_map('intval', array_keys($out));
    }

    public function canManageNav(int $userId, int $navId): bool
    {
        if ($navId < 1) {
            return true;
        }
        if ($userId < 1 || !$this->isNavRestricted($userId)) {
            return true;
        }

        return in_array($navId, $this->allowedNavIds($userId), true);
    }

    public function assertCanManageNav(int $userId, int $navId): ?ServiceResult
    {
        if ($this->canManageNav($userId, $navId)) {
            return null;
        }

        return ServiceResult::fail('无权管理该栏目下的内容');
    }

    public function assertCurrentCanManageNav(int $navId): ?ServiceResult
    {
        return $this->assertCanManageNav($this->currentUserId(), $navId);
    }

    /**
     * @param \think\db\Query|\think\Model $query documents/items 查询（含 nav_id 列）
     * @param 'document'|'item'|'' $entity 空则仅主栏目列；传 entity 时附加栏目也算在范围内
     */
    public function applyNavQueryScope($query, string $column = 'nav_id', string $entity = ''): void
    {
        $userId = $this->currentUserId();
        if ($userId < 1 || !$this->isNavRestricted($userId)) {
            return;
        }
        $ids = $this->allowedNavIds($userId);
        if ($ids === []) {
            $query->where($column, 0);

            return;
        }
        if ($entity === 'document' || $entity === 'item') {
            app(\app\common\service\site\SiteNavService::class)
                ->applyPrimaryOrExtraNavFilter($query, $ids, $entity);

            return;
        }
        $query->whereIn($column, $ids);
    }

    /**
     * @param list<int> $navIds
     */
    public function saveNavForUser(int $userId, array $navIds): void
    {
        if ($userId < 1) {
            return;
        }
        AdminUserNavScope::where('user_id', $userId)->delete();
        $navIds = array_values(array_unique(array_filter(array_map('intval', $navIds))));
        if ($navIds === []) {
            return;
        }
        $now = AppTime::now();
        foreach ($navIds as $navId) {
            if ($navId > 0) {
                AdminUserNavScope::insert([
                    'user_id'    => $userId,
                    'nav_id'     => $navId,
                    'created_at' => $now,
                ]);
            }
        }
    }

    /**
     * @return list<array{id:int,name:string,group_name:string}>
     */
    public function listTagsForAdminPicker(): array
    {
        $rows = \app\common\support\ModelRelationLoad::mapBelongsTo(
            Tag::with(['tagGroup' => static function ($groupQuery): void {
                $groupQuery->field('id,name');
            }])->field('id,name,group_id,parent_id,nav_sort')
                ->order('nav_sort', 'asc')
                ->order('id', 'asc')
                ->select(),
            'tagGroup',
            ['name' => 'group_name'],
        );
        $forest = $this->tagService->buildTagForest($rows);
        $flat   = $this->tagService->flattenNavTree($forest);
        $out    = [];
        foreach ($flat as $row) {
            $out[] = [
                'id'         => (int) ($row['id'] ?? 0),
                'name'       => (string) ($row['name_display'] ?? $row['name'] ?? ''),
                'group_name' => '',
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public function listNavsForAdminPicker(): array
    {
        $out = [];
        foreach (app(SiteNavService::class)->listContentCategoryOptionsForPublish() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $out[] = [
                'id'   => $id,
                'name' => (string) ($row['label'] ?? $row['title'] ?? ''),
            ];
        }

        return $out;
    }
}
