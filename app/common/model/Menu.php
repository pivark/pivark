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
 * Class app\common\model\Menu
 *
 * @property int $id 主键
 * @property int $parent_id 父级菜单ID
 * @property int $sort 排序
 * @property int $status 状态：0禁用 1正常
 * @property string $created_at 创建时间
 * @property string $icon 图标
 * @property string $params 路由参数（JSON）
 * @property string $permission_code 关联权限标识
 * @property string $portals 可见门户 external,internal 逗号分隔；空=全部
 * @property string $route 路由路径
 * @property string $title 菜单名称
 * @property-read \app\common\model\Menu $parent
 */
class Menu extends Model
{
    protected $name = 'menus';

        protected $type = [
        'id' => 'integer',
        'parent_id' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];
    public $timestamps = false;

    public static function getAllSorted(): array
    {
        return self::order('sort', 'asc')->order('id', 'asc')->select()->toArray();
    }

    public static function getByParent(int $parentId)
    {
        return self::where('parent_id', $parentId)->order('sort', 'asc')->select()->toArray();
    }

    public static function createMenu(array $data): int
    {
        $menu = self::create([
            'title'     => $data['title'],
            'icon'      => $data['icon'] ?? '',
            'route'     => $data['route'] ?? '',
            'parent_id' => (int)($data['parent_id'] ?? 0),
            'sort'      => (int)($data['sort'] ?? 0),
            'status'    => 1,
        ]);
        return $menu->id;
    }

    public static function updateMenu(int $id, array $data): void
    {
        self::where('id', $id)->update([
            'title'     => $data['title'],
            'icon'      => $data['icon'] ?? '',
            'route'     => $data['route'] ?? '',
            'parent_id' => (int)($data['parent_id'] ?? 0),
            'sort'      => (int)($data['sort'] ?? 0),
        ]);
    }

    public static function deleteWithChildren(int $id): void
    {
        self::where('parent_id', $id)->delete();
        self::where('id', $id)->delete();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}