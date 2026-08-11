<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\user;

use app\common\service\admin\AdminPermissionExtensionRegistry;
use app\common\service\plugin\PluginService;
use app\common\support\WeappIdentifierAlias;
use app\common\support\ServiceResult;
use app\common\service\member\MemberProfileService;
use app\common\service\member\MemberService;
use app\common\model\Permission;
use app\common\model\Role;
use app\common\model\RolePermission;
use app\common\model\UserRole;
use think\facade\Request;

/** RBAC 权限校验 */
class PermissionService
{

    public function __construct(
        private readonly MemberProfileService $memberProfileService,
    ) {
    }

    private const SUPER_ROLE_CODE = 'super_admin';

    /**
     * @param int $userId 用户 ID
     * @return list<int> 角色 ID 列表
     */
    public function getRoleIds(int $userId): array
    {
        return UserRole::getRoleIdsByUserId($userId);
    }

    /**
     * @param int $userId 用户 ID
     * @return list<string> 角色 code 列表
     */
    public function getRoleCodes(int $userId): array
    {
        $roleIds = $this->getRoleIds($userId);
        if ($roleIds === []) {
            return [];
        }
        return Role::activeCodesByIds($roleIds);
    }

    /**
     * @param int $userId 用户 ID
     * @return bool
     */
    public function isSuperAdmin(int $userId): bool
    {
        return in_array(self::SUPER_ROLE_CODE, $this->getRoleCodes($userId), true);
    }

    /**
     * @param int        $operatorId 操作者用户 ID
     * @param list<int>  $roleIds    拟分配角色 ID
     * @return ServiceResult|null 不允许时返回错误
     */
    public function denyIfAssigningSuperAdmin(int $operatorId, array $roleIds): ?ServiceResult
    {
        if ($roleIds === []) {
            return null;
        }
        $superId = Role::activeIdByCode(self::SUPER_ROLE_CODE);
        if ($superId < 1 || !in_array($superId, $roleIds, true)) {
            return null;
        }
        if ($this->isSuperAdmin($operatorId)) {
            return null;
        }
        return ServiceResult::fail('无权分配超级管理员角色');
    }

    /**
     * @param int $userId 用户 ID
     * @return list<string> 权限 code，超管为 ['*']
     */
    public function getPermissionCodes(int $userId): array
    {
        if ($this->isSuperAdmin($userId)) {
            return ['*'];
        }
        $roleIds = $this->getRoleIds($userId);
        if ($roleIds === []) {
            return [];
        }
        $permIds = RolePermission::whereIn('role_id', $roleIds)->column('permission_id');
        if ($permIds === []) {
            return [];
        }
        return Permission::activeCodesByIds($permIds);
    }

    /**
     * @param int    $userId         用户 ID
     * @param string $permissionCode 权限码，如 admin.document.edit
     * @return bool
     */
    public function can(int $userId, string $permissionCode): bool
    {
        if ($permissionCode === '') {
            return true;
        }
        $codes = $this->getPermissionCodes($userId);
        if (in_array('*', $codes, true)) {
            return true;
        }
        if (in_array($permissionCode, $codes, true)) {
            return true;
        }
        $parts = explode('.', $permissionCode);
        while (count($parts) > 1) {
            array_pop($parts);
            $parent = implode('.', $parts);
            if (in_array($parent, $codes, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $controller 控制器名（小写）
     * @param string $action     方法名（小写）
     * @return string|null 所需权限码，null 表示仅需登录
     */
    public function resolveRequiredPermission(string $controller, string $action): ?string
    {
        $controller = $this->normalizeControllerKey($controller);
        $action     = strtolower($action);
        $ext = app(AdminPermissionExtensionRegistry::class)->resolve($controller, $action);
        if ($ext !== null) {
            return $ext;
        }
        if ($controller === 'spa' && $action === 'pluginmeta') {
            $pluginPermission = $this->resolveSpaPluginMetaPermission();
            if ($pluginPermission !== null) {
                return $pluginPermission;
            }
        }
        $map        = config('admin.permission');
        if (!is_array($map) || !isset($map[$controller][$action])) {
            return null;
        }
        return $map[$controller][$action];
    }

    /** 路由控制器名与 admin_permission 键对齐 */
    public function normalizeControllerKey(string $controller): string
    {
        $controller = strtolower(str_replace(['-', '\\', '/'], ['_', '.', '.'], $controller));
        if (str_contains($controller, '.')) {
            $controller = substr($controller, strrpos($controller, '.') + 1);
        }

        return match ($controller) {
            'memberpublish'=> 'member_publish',
            default        => $controller,
        };
    }

    private function resolveSpaPluginMetaPermission(): ?string
    {
        $plugin = strtolower(trim((string) Request::param('plugin', '')));
        $plugin = WeappIdentifierAlias::normalize($plugin);
        if ($plugin === '') {
            return null;
        }
        $manifest = app(PluginService::class)->readManifest($plugin);
        if (!is_array($manifest)) {
            return null;
        }
        $permissions = $manifest['permissions'] ?? null;
        if (!is_array($permissions) || $permissions === []) {
            return null;
        }
        $code = trim((string) ($permissions[0] ?? ''));

        return $code !== '' ? $code : null;
    }

    /**
     * @param int $userId 用户 ID
     * @return array<string, mixed> 写入 Session 的权限摘要
     */
    public function buildSessionPayload(int $userId): array
    {
        $roleCodes = $this->getRoleCodes($userId);
        return [
            'role_codes'       => $roleCodes,
            'is_super'         => in_array(self::SUPER_ROLE_CODE, $roleCodes, true),
            'permission_codes' => $this->getPermissionCodes($userId),
        ];
    }

    /** 是否拥有后台运维角色（除 member 外的任一启用角色） */
    public function hasBackofficeRole(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        foreach ($this->getRoleCodes($userId) as $code) {
            if ($code !== '' && $code !== MemberService::ROLE_CODE) {
                return true;
            }
        }

        return false;
    }

    /** 仅前台会员账号（无后台角色） */
    public function isMemberOnlyAccount(int $userId): bool
    {
        return $userId > 0
            && $this->memberProfileService->hasMemberRole($userId)
            && !$this->hasBackofficeRole($userId);
    }

    /**
     * @param list<int> $roleIds
     */
    public function roleIdsIncludeBackoffice(array $roleIds): bool
    {
        if ($roleIds === []) {
            return false;
        }
        $memberRoleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        foreach ($roleIds as $roleId) {
            if ($roleId > 0 && $roleId !== $memberRoleId) {
                return true;
            }
        }

        return false;
    }
}
