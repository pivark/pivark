<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 标签分组 pv_tag_groups
 *
 * @property int $id 主键
 * @property int $sort 排序（越小越靠前）
 * @property int $status 0禁用 1启用
 * @property mixed $requires_entitlement 启用本站托管域时须已授权的插件 identifier 列表
 * @property string $created_at 创建时间
 * @property string $name 分组名称
 * @property string $updated_at 更新时间
 */
class TagGroup extends Model
{
    protected $name = 'tag_groups';

        protected $type = [
        'id' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];

    public $timestamps = false;
}
