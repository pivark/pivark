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
 * 品项规格 pv_item_variants（AD-021 可订货单元）
 *
 * @property int $id 主键
 * @property int $is_default 是否默认规格（每品项至多一个）
 * @property int $item_id 品项 ID（SPU）
 * @property int $sort 排序
 * @property mixed $spec_map 规格轴键值 JSON
 * @property string $created_at 创建时间
 * @property string $spec_label 规格展示文案，如 220V/左进
 * @property string $status active/discontinued
 * @property string $updated_at 更新时间
 * @property string $variant_code 订货编码（全站唯一，对外报号）
 * @property-read \app\common\model\Item $item
 */
class ItemVariant extends Model
{
    protected $name = 'item_variants';

    protected $type = [
        'id'         => 'integer',
        'item_id'    => 'integer',
        'is_default' => 'integer',
        'sort'       => 'integer',
    ];

    public $timestamps = false;

    protected $json = ['spec_map'];

    protected $jsonAssoc = true;

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
