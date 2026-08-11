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
use think\model\relation\BelongsTo;

/**
 * Class app\common\model\ProductParamDef
 *
 * @property int $filterable 是否参与前台筛选
 * @property int $group_id 参数组 ID
 * @property int $id 主键
 * @property int $sort 排序
 * @property mixed $options_json select 选项
 * @property string $created_at 创建时间
 * @property string $default_value 默认值
 * @property string $input_type text/select/number
 * @property string $label 显示名
 * @property string $param_key 参数键 如 color
 * @property string $updated_at 更新时间
 * @property-read \app\common\model\ProductParamGroup $param_group
 */
class ProductParamDef extends Model
{
    protected $name = 'product_param_defs';

    protected $type = [
        'filterable' => 'integer',
        'group_id'   => 'integer',
        'id'         => 'integer',
        'sort'       => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function paramGroup(): BelongsTo
    {
        return $this->belongsTo(ProductParamGroup::class, 'group_id');
    }

}
