<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\common\model;

use think\Model;
use think\model\relation\BelongsTo;

/**
 * Class app\common\model\RolePermission
 *
 * @property int $id 主键
 * @property int $permission_id 权限ID
 * @property int $role_id 角色ID
 * @property string $created_at 创建时间
 * @property-read \app\common\model\Permission $permission
 * @property-read \app\common\model\Role $role
 */
class RolePermission extends Model
{
    protected $name = 'role_permissions';

        protected $type = [
        'id' => 'integer',
        'permission_id' => 'integer',
        'role_id' => 'integer',
    ];
    public $timestamps = false;

    public static function deleteByRoleId(int $roleId): void
    {
        self::where('role_id', $roleId)->delete();
    }

    public static function getPermIdsByRoleId(int $roleId): array
    {
        return self::where('role_id', $roleId)->column('permission_id');
    }

    public static function saveBatch(int $roleId, array $permIds): void
    {
        self::where('role_id', $roleId)->delete();
        $rows = [];
        foreach ($permIds as $pid) {
            $rows[] = ['role_id' => $roleId, 'permission_id' => (int)$pid];
        }
        if ($rows) {
            self::insertAll($rows);
        }
    }

    /**
     * @param list<array{permission_id:int,data_scope?:string}> $bindings
     */
    public static function saveBatchWithScopes(int $roleId, array $bindings): void
    {
        self::where('role_id', $roleId)->delete();
        $rows = [];
        foreach ($bindings as $binding) {
            $permId = (int) ($binding['permission_id'] ?? 0);
            if ($permId < 1) {
                continue;
            }
            $scope = (string) ($binding['data_scope'] ?? 'self');
            if (!in_array($scope, ['self', 'dept', 'dept_tree', 'all'], true)) {
                $scope = 'self';
            }
            $rows[] = [
                'role_id'       => $roleId,
                'permission_id' => $permId,
                'data_scope'    => $scope,
            ];
        }
        if ($rows !== []) {
            self::insertAll($rows);
        }
    }
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

}