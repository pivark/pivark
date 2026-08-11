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
 * Class app\common\model\ProductParamGroup
 *
 * @property int $id 主键
 * @property int $sort 排序
 * @property int $status 1启用 0停用
 * @property string $created_at 创建时间
 * @property string $description 参数组描述
 * @property string $group_key 组键
 * @property string $label 显示名
 * @property string $updated_at 更新时间
 */
class ProductParamGroup extends Model
{
    protected $name = 'product_param_groups';

    protected $type = [
        'id'     => 'integer',
        'sort'   => 'integer',
        'status' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
