<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\support\AppTime;
use think\Model;
use think\model\relation\BelongsTo;

/**
 * 用户角色关联 pv_user_roles
 *
 * @property int $id 主键
 * @property int $role_id 角色ID
 * @property int $user_id 用户ID
 * @property string $created_at 创建时间
 * @property-read \app\common\model\Role $role
 * @property-read \app\common\model\User $user
 */
class UserRole extends Model
{
    protected $name = 'user_roles';

        protected $type = [
        'id' => 'integer',
        'role_id' => 'integer',
        'user_id' => 'integer',
    ];

    public $timestamps = false;

    /**
     * @return list<int>
     */
    public static function getRoleIdsByUserId(int $userId): array
    {
        return self::where('user_id', $userId)->column('role_id');
    }

    public static function countByRoleId(int $roleId): int
    {
        if ($roleId < 1) {
            return 0;
        }

        return (int) self::where('role_id', $roleId)->count();
    }

    /**
     * @param list<int> $roleIds
     */
    public static function syncForUser(int $userId, array $roleIds): void
    {
        self::where('user_id', $userId)->delete();
        $roleIds = array_unique(array_filter(array_map('intval', $roleIds)));
        foreach ($roleIds as $roleId) {
            if ($roleId > 0) {
                self::insert([
                    'user_id'    => $userId,
                    'role_id'    => $roleId,
                    'created_at' => AppTime::now(),
                ]);
            }
        }
    }
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 绑定 role_id 的 users 查询（勿 whereHas('roles')，与 Role 表名冲突）
     *
     * @return \think\db\BaseQuery|\think\Model
     */
    public static function usersQuery(int $roleId)
    {
        if ($roleId < 1) {
            return User::where('id', 0);
        }

        return User::alias('u')
            ->join((new self())->getTable() . ' ur', 'ur.user_id = u.id')
            ->where('ur.role_id', $roleId);
    }

}
