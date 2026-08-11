<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\common\service\user;

use app\common\support\ServiceResult;
use app\common\service\user\PermissionLabelService;

use app\common\service\audit\AuditLogService;
use app\common\service\content\ContentSearchService;
use app\common\model\Role;
use app\common\model\RolePermission;
use app\common\model\Permission;
use app\common\model\UserRole;
use app\common\model\Plugin;
use app\common\service\plugin\seed\PluginPermissionSeedService;
use app\common\service\plugin\seed\EnterprisePermissionSeedService;
use think\facade\Db;
use think\facade\Cache;

/** 角色与权限分配 */
class RoleService
{

    public function __construct(
        private readonly ContentSearchService $contentSearchService,
        private readonly AuditLogService $auditLogService,
        private readonly PermissionLabelService $permissionLabelService,
        private readonly PluginPermissionSeedService $pluginPermissionSeedService,
    ) {
    }

    /** @var list<string> */
    private const ALLOWED_FIELDS = ['name', 'code', 'description', 'status'];

    private const ACTIVE_ROLES_CACHE_KEY = 'pivark:roles:active_v1';

    private const ACTIVE_ROLES_CACHE_TTL = 600;

    /**
     * @param int    $page
     * @param int    $limit
     * @param string $keyword
     * @return \think\Paginator
     */
    public function getList(int $page = 1, int $limit = 15, string $keyword = '')
    {
        $query = Role::order('id', 'asc');
        $this->applyEnterpriseTemplateRoleListScope($query);
        if ($keyword) {
            $query->whereLike('name|code', $this->contentSearchService->likePattern($keyword));
        }
        return $query->paginate($limit, false, ['page' => $page]);
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function create(array $data): ServiceResult
    {
        $exists = Role::where('code', $data['code'] ?? '')->find();
        if ($exists) {
            return ServiceResult::fail('角色编码已存在');
        }
        $permissions = $data['permissions'] ?? [];
        $payload     = $this->pickRoleFields($data);
        if ($payload === []) {
            return ServiceResult::fail('参数无效');
        }

        Db::startTrans();
        try {
            $role = Role::create($payload);
            if (!empty($permissions)) {
                RolePermission::saveBatch((int) $role->id, $permissions);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ServiceResult::fail('创建失败');
        }

        $this->auditLogService->operate('创建角色', 'admin.role', ['role_id' => (int) $role->id, 'code' => $role->code]);
        $this->bustActiveRolesCache();

        return ServiceResult::ok(null, '创建成功');
    }

    /**
     * @param int                $id
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function update(int $id, array $data): ServiceResult
    {
        $role = Role::find($id);
        if (!$role) {
            return ServiceResult::fail('角色不存在');
        }
        $permissions = $data['permissions'] ?? null;
        $payload     = $this->pickRoleFields($data);
        $fields      = $role->is_system
            ? ['name', 'description', 'status']
            : self::ALLOWED_FIELDS;

        Db::startTrans();
        try {
            if ($payload !== []) {
                $role->allowField($fields)->save($payload);
            }
            if ($permissions !== null) {
                RolePermission::saveBatch($id, $permissions);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ServiceResult::fail('更新失败');
        }

        $this->auditLogService->operate('更新角色', 'admin.role', ['role_id' => $id]);
        $this->bustActiveRolesCache();

        return ServiceResult::ok(null, '更新成功');
    }

    /**
     * @param int $id
     * @return ServiceResult
     */
    public function delete(int $id): ServiceResult
    {
        $role = Role::find($id);
        if (!$role) {
            return ServiceResult::fail('角色不存在');
        }
        if ($role->is_system && !$this->isEnterpriseTemplateRoleDeletable($role)) {
            return ServiceResult::fail('系统角色不可删除');
        }
        $bound = UserRole::countByRoleId($id);
        if ($bound > 0) {
            return ServiceResult::fail("该角色仍关联 {$bound} 个用户，无法删除");
        }
        $code = (string) $role->code;

        Db::startTrans();
        try {
            RolePermission::deleteByRoleId($id);
            $role->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ServiceResult::fail('删除失败');
        }

        $this->auditLogService->operate('删除角色', 'admin.role', ['role_id' => $id, 'code' => $code]);
        $this->bustActiveRolesCache();

        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @return \think\Collection
     */
    public function getAll()
    {
        /** @var \think\Collection $rows */
        $rows = Cache::remember(self::ACTIVE_ROLES_CACHE_KEY, function () {
            $query = Role::where('status', 1);
            $this->applyEnterpriseTemplateRoleListScope($query);

            return $query->select();
        }, self::ACTIVE_ROLES_CACHE_TTL);

        return $rows;
    }

    private function bustActiveRolesCache(): void
    {
        Cache::delete(self::ACTIVE_ROLES_CACHE_KEY);
    }

    /** 供 Enterprise 模板角色 seed 等内核流程刷新角色缓存 */
    public function bustActiveRolesCachePublic(): void
    {
        $this->bustActiveRolesCache();
    }

    /**
     * @param int $roleId
     * @return list<int>
     */
    public function getPermissionIds(int $roleId): array
    {
        return RolePermission::getPermIdsByRoleId($roleId);
    }

    /**
     * @param list<int> $roleIds
     * @return array<int, int> role_id => permission_count
     */
    public function countPermissionsByRoleIds(array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));
        if ($roleIds === []) {
            return [];
        }

        $rows = Db::name('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->field('role_id, COUNT(*) AS cnt')
            ->group('role_id')
            ->select()
            ->toArray();

        $map = array_fill_keys($roleIds, 0);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rid = (int) ($row['role_id'] ?? 0);
            if ($rid > 0) {
                $map[$rid] = (int) ($row['cnt'] ?? 0);
            }
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAllForUserForm(): array
    {
        $rows = [];
        foreach ($this->getAll() as $role) {
            $row = is_object($role) && method_exists($role, 'toArray')
                ? $role->toArray()
                : (array) $role;
            $rows[] = $row;
        }

        return $this->enrichRowsWithPermissionCounts($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function enrichRowsWithPermissionCounts(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $roleIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows,
        ))));
        $counts = $this->countPermissionsByRoleIds($roleIds);

        foreach ($rows as &$row) {
            $rid = (int) ($row['id'] ?? 0);
            $row['permission_count'] = $counts[$rid] ?? 0;
        }
        unset($row);

        return $rows;
    }

    public function findForForm(int $id): ?Role
    {
        if ($id < 1) {
            return null;
        }
        $role = Role::find($id);

        return $role instanceof Role ? $role : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAllPermissions()
    {
        return Permission::getAllActive();
    }

    /**
     * 角色表单：按模块分组，展示中文模块名（不暴露 admin.user 等技术 code）
     *
     * @return list<array{title:string,root:?array,items:list<array>}>
     */
    public function permissionsGroupedForForm(): array
    {
        $permissions = $this->filterPermissionsForRoleForm(Permission::getAllActive());
        $byCode      = [];
        foreach ($permissions as $p) {
            $row = is_array($p) ? $p : $p->toArray();
            $byCode[$row['code']] = $row;
        }

        $buckets = [];
        foreach ($byCode as $row) {
            $code  = (string) $row['code'];
            $parts = explode('.', $code);
            $group = count($parts) >= 2 ? $parts[0] . '.' . $parts[1] : $parts[0];
            $buckets[$group][] = $row;
        }

        $groups = [];
        foreach ($buckets as $groupKey => $perms) {
            $root  = $byCode[$groupKey] ?? null;
            $title = $this->permissionLabelService->groupLabel($groupKey);
            if ($title === '' && $root) {
                $title = $this->permissionLabelService->labelForCode((string) $root['code'], (string) ($root['name'] ?? ''));
            }

            $items = [];
            foreach ($perms as $p) {
                if ($p['code'] === $groupKey) {
                    continue;
                }
                $p['name'] = $this->permissionLabelService->labelForCode(
                    (string) $p['code'],
                    (string) ($p['name'] ?? ''),
                );
                $items[] = $p;
            }

            $sort = $root ? (int) ($root['sort'] ?? 0) : (int) ($items[0]['sort'] ?? 999);
            $groups[] = [
                'title' => $title,
                'root'  => $root,
                'items' => $items,
                'sort'  => $sort,
            ];
        }

        usort($groups, static fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return $groups;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function pickRoleFields(array $data): array
    {
        $out = [];
        foreach (self::ALLOWED_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $out[$field] = $data[$field];
            }
        }
        return $out;
    }

    /** @param \think\db\Query|\think\Model $query */
    private function applyEnterpriseTemplateRoleListScope($query): void
    {
        if (app(EnterprisePermissionSeedService::class)->shouldExposeEnterpriseTemplateRoles()) {
            return;
        }
        $codes = app(EnterprisePermissionSeedService::class)->enterpriseTemplateRoleCodes();
        if ($codes !== []) {
            $query->whereNotIn('code', $codes);
        }
    }

    /** 供角色列表 API 标注是否可删（无 Enterprise 应用时不锁 Enterprise 模板角色） */
    public function isRoleDeletableForAdmin(array $row): bool
    {
        if ((int) ($row['is_system'] ?? 0) !== 1) {
            return true;
        }

        return app(EnterprisePermissionSeedService::class)->isEnterpriseTemplateRoleCode((string) ($row['code'] ?? ''))
            && !app(EnterprisePermissionSeedService::class)->shouldExposeEnterpriseTemplateRoles();
    }

    private function isEnterpriseTemplateRoleDeletable(Role $role): bool
    {
        return app(EnterprisePermissionSeedService::class)->isEnterpriseTemplateRoleCode((string) $role->code)
            && !app(EnterprisePermissionSeedService::class)->shouldExposeEnterpriseTemplateRoles();
    }

    /** 角色表单：每插件仅展示 plugin.{id}.use */
    private const PLUGIN_ROLE_FORM_SUFFIXES = ['use'];

    /**
     *
     * @param \think\Collection|list<mixed> $permissions
     * @return list<array<string, mixed>>
     */
    private function filterPermissionsForRoleForm($permissions): array
    {
        $installed = array_flip(array_map(
            static fn ($id) => strtolower((string) $id),
            Plugin::where('installed', 1)->column('identifier'),
        ));

        $rows = [];
        foreach ($permissions as $p) {
            $row  = is_array($p) ? $p : $p->toArray();
            $code = (string) ($row['code'] ?? '');
            if ($this->isLegacyAdminPluginPermission($code)) {
                continue;
            }
            if (!str_starts_with($code, 'plugin.')) {
                $rows[] = $row;
                continue;
            }
            $pluginId = $this->pluginIdentifierFromPermissionCode($code);
            if ($pluginId === null) {
                continue;
            }
            if ($this->pluginPermissionSeedService->isDevScaffoldIdentifier($pluginId)) {
                continue;
            }
            if (!isset($installed[$pluginId])) {
                continue;
            }
            if (!$this->isPluginPermissionForRoleForm($code)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function pluginIdentifierFromPermissionCode(string $code): ?string
    {
        if (!str_starts_with($code, 'plugin.')) {
            return null;
        }
        $rest = substr($code, 7);
        if ($rest === '') {
            return null;
        }
        $dot = strpos($rest, '.');

        return strtolower($dot === false ? $rest : substr($rest, 0, $dot));
    }

    private function isPluginPermissionForRoleForm(string $code): bool
    {
        if (!str_starts_with($code, 'plugin.')) {
            return true;
        }
        $parts = explode('.', $code);
        if (count($parts) === 3) {
            return in_array($parts[2], self::PLUGIN_ROLE_FORM_SUFFIXES, true);
        }
        if (count($parts) >= 4) {
            return app(EnterprisePermissionSeedService::class)->isEnterpriseApplication(strtolower($parts[1]));
        }

        return false;
    }

    /** 已废弃的 admin.plugin.{id} 三档权限，由 plugin.{id}.use 替代 */
    private function isLegacyAdminPluginPermission(string $code): bool
    {
        if (!str_starts_with($code, 'admin.plugin.')) {
            return false;
        }
        $parts = explode('.', $code);
        if (count($parts) < 3) {
            return false;
        }
        $third = $parts[2];

        return !in_array($third, ['list', 'manage'], true);
    }
}
