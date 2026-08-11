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
 * Class app\common\model\DocumentItemRef
 *
 * @property int $document_id 文档 ID
 * @property int $id 主键
 * @property int $item_id 品项 ID
 * @property int $sort 排序
 * @property string $created_at 创建时间
 * @property string $role primary/drawing/gallery/cert…
 * @property-read \app\common\model\Document $document
 * @property-read \app\common\model\Item $item
 */
class DocumentItemRef extends Model
{
    protected $name = 'document_item_refs';

        protected $type = [
        'document_id' => 'integer',
        'id' => 'integer',
        'item_id' => 'integer',
        'sort' => 'integer',
    ];

    protected $autoWriteTimestamp = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
