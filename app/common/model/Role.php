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

/**
 * Class app\common\model\Role
 *
 * @property int $id 主键
 * @property int $is_system 是否系统预置（不可删除）
 * @property int $status 状态：0禁用 1正常
 * @property string $code 角色编码（唯一）
 * @property string $created_at 创建时间
 * @property string $description 角色描述
 * @property string $name 角色名称
 * @property string $updated_at 更新时间
 */
class Role extends Model
{
    protected $name = 'roles';

        protected $type = [
        'id' => 'integer',
        'is_system' => 'integer',
        'status' => 'integer',
    ];

    /** 表字段为 created_at / updated_at，由业务层写入 */
    protected $autoWriteTimestamp = false;

    public static function activeIdByCode(string $code): int
    {
        if ($code === '') {
            return 0;
        }

        return (int) (self::where('code', $code)->where('status', 1)->value('id') ?? 0);
    }

    /**
     * @param list<int> $ids
     * @return list<string>
     */
    public static function activeCodesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return self::whereIn('id', $ids)->where('status', 1)->column('code');
    }
}