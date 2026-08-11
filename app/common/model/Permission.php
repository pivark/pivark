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
 * Class app\common\model\Permission
 *
 * @property int $id 主键
 * @property int $parent_id 父级权限ID
 * @property int $sort 排序
 * @property int $status 状态：0禁用 1正常
 * @property string $code 权限标识（如 admin.user.create）
 * @property string $created_at 创建时间
 * @property string $icon 图标
 * @property string $module 所属模块（admin/api/plugin）
 * @property string $name 权限名称
 * @property-read \app\common\model\Permission $parent
 */
class Permission extends Model
{
    protected $name = 'permissions';

        protected $type = [
        'id' => 'integer',
        'parent_id' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];
    public $timestamps = false;

    public static function getAllActive()
    {
        return self::where('status', 1)->order('sort', 'asc')->select();
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

        return self::whereIn('id', array_unique($ids))->where('status', 1)->column('code');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}