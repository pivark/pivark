<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * Enterprise 应用插件标识 + 可选 pack 安装钩子。
 * 权限码：PluginPermissionSeedService::syncFromManifest（plugin.json）
 * 跨插件边：CrossPluginAclRegistry（weapp pack）· 本 config 仅 system_admin_id 占位
 */
declare(strict_types=1);

namespace app\common\service\plugin\seed;

use app\common\model\Permission;
use app\common\model\Plugin;
use app\common\model\Role;
use app\common\model\RolePermission;
use app\common\service\release\PivarkEditionService;
use app\common\support\AppTime;
use app\common\service\user\RoleService;

class EnterprisePermissionSeedService
{
    public function __construct(
        private readonly PivarkEditionService $pivarkEdition,
        private readonly RoleService $role,
    ) {
    }

    /** @var list<string> */
    public const APPLICATION_IDENTIFIERS = ['oa', 'crm', 'erp', 'plm', 'mes'];

    /** @var array<string, string> roles.code => data_scope */
    private const DEFAULT_ROLE_DATA_SCOPES = [
        'enterprise_sales'     => 'self',
        'enterprise_purchase'  => 'dept_tree',
        'enterprise_warehouse' => 'dept',
        'enterprise_planner'   => 'dept_tree',
        'enterprise_rd'        => 'dept',
        'enterprise_workshop'  => 'self',
        'enterprise_finance'   => 'all',
        'enterprise_admin'     => 'all',
    ];

    public function isEnterpriseApplication(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));

        return $identifier !== '' && in_array($identifier, self::APPLICATION_IDENTIFIERS, true);
    }

    /** @return list<string> Enterprise 岗位模板角色 code（仅 Enterprise 版灌入） */
    public function enterpriseTemplateRoleCodes(): array
    {
        return array_keys(self::DEFAULT_ROLE_DATA_SCOPES);
    }

    public function isEnterpriseTemplateRoleCode(string $code): bool
    {
        $code = strtolower(trim($code));

        return $code !== '' && isset(self::DEFAULT_ROLE_DATA_SCOPES[$code]);
    }

    /** 已安装任一 Enterprise 应用插件（oa/crm/erp/plm/mes） */
    public function hasInstalledEnterpriseApplication(): bool
    {
        return Plugin::where('installed', 1)
            ->whereIn('identifier', self::APPLICATION_IDENTIFIERS)
            ->count() > 0;
    }

    /** Community 或未装 Enterprise 应用时不展示岗位模板角色 */
    public function shouldExposeEnterpriseTemplateRoles(): bool
    {
        if ($this->pivarkEdition->isCommunity()) {
            return false;
        }

        return $this->hasInstalledEnterpriseApplication();
    }

    /**
     * @return list<string>
     */
    public function enterpriseApplicationIdentifiers(): array
    {
        return self::APPLICATION_IDENTIFIERS;
    }

    /**
     * 安装/升级后：仅处理插件自带 pack（若有）；不从内核注册表 bulk 灌码
     *
     * @return array{roles_created:list<string>,roles_bindings_added:int}
     */
    public function afterEnterprisePluginInstall(string $identifier): array
    {
        if (!$this->isEnterpriseApplication($identifier)) {
            return ['roles_created' => [], 'roles_bindings_added' => 0];
        }

        return $this->seedDefaultRolesFromPluginPack($identifier);
    }

    /**
     * weapp/{id}/config/enterprise_pack.php 可选返回 default_roles[]
     *
     * @return array{roles_created:list<string>,roles_bindings_added:int}
     */
    public function seedDefaultRolesFromPluginPack(string $identifier): array
    {
        if ($this->pivarkEdition->isCommunity()) {
            return ['roles_created' => [], 'roles_bindings_added' => 0];
        }

        $identifier = strtolower(trim($identifier));
        $packPath   = dirname(__DIR__, 4) . '/weapp/' . $identifier . '/config/enterprise_pack.php';
        if (!is_readable($packPath)) {
            return ['roles_created' => [], 'roles_bindings_added' => 0];
        }

        /** @var array<string, mixed> $pack */
        $pack = require $packPath;
        $defaultRoles = $pack['default_roles'] ?? [];
        if (!is_array($defaultRoles) || $defaultRoles === []) {
            return ['roles_created' => [], 'roles_bindings_added' => 0];
        }

        $created = [];
        $added   = 0;
        $now     = AppTime::now();

        foreach ($defaultRoles as $roleCode => $row) {
            if (!is_string($roleCode) || !is_array($row)) {
                continue;
            }
            $roleCode = strtolower(trim($roleCode));
            if ($roleCode === '') {
                continue;
            }

            $role = Role::where('code', $roleCode)->find();
            if (!$role instanceof Role) {
                $role = Role::create([
                    'name'        => (string) ($row['label'] ?? $roleCode),
                    'code'        => $roleCode,
                    'description' => 'Enterprise 模板角色（插件 pack · ' . $identifier . '）',
                    'is_system'   => 1,
                    'status'      => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
                $created[] = $roleCode;
            }

            $roleId       = (int) $role->getAttr('id');
            $scopeDefault = (string) ($row['data_scope'] ?? self::DEFAULT_ROLE_DATA_SCOPES[$roleCode] ?? 'self');
            $permCodes    = $row['permissions'] ?? [];
            if (!is_array($permCodes)) {
                continue;
            }

            $existingIds = RolePermission::where('role_id', $roleId)->column('permission_id');
            $existingSet = [];
            foreach ($existingIds as $pid) {
                $existingSet[(int) $pid] = true;
            }

            foreach ($permCodes as $permCode) {
                $permCode = strtolower(trim((string) $permCode));
                if ($permCode === '') {
                    continue;
                }
                $permId = (int) (Permission::where('code', $permCode)->value('id') ?? 0);
                if ($permId < 1 || isset($existingSet[$permId])) {
                    continue;
                }
                RolePermission::insert([
                    'role_id'       => $roleId,
                    'permission_id' => $permId,
                    'data_scope'    => $this->normalizeDataScope($scopeDefault),
                ]);
                $existingSet[$permId] = true;
                $added++;
            }
        }

        if ($created !== [] || $added > 0) {
            $this->role->bustActiveRolesCachePublic();
        }

        return ['roles_created' => $created, 'roles_bindings_added' => $added];
    }

    private function normalizeDataScope(string $scope): string
    {
        if (!in_array($scope, ['self', 'dept', 'dept_tree', 'all'], true)) {
            return 'self';
        }

        return $scope;
    }
}
