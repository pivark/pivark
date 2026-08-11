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
 * Class app\common\model\ItemTag
 *
 * @property int $id 主键
 * @property int $item_id 品项 ID
 * @property int $tag_id 标签 ID
 * @property string $created_at 创建时间
 * @property-read \app\common\model\Item $item
 * @property-read \app\common\model\Tag $tag
 */
class ItemTag extends Model
{
    protected $name = 'item_tags';

        protected $type = [
        'id' => 'integer',
        'item_id' => 'integer',
        'tag_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }

}
