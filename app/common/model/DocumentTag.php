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
 * 文档-标签关联 pv_document_tags
 *
 * @property int $document_id 文档 ID
 * @property int $id 主键
 * @property int $tag_id 标签 ID
 * @property string $created_at 创建时间
 * @property-read \app\common\model\Document $document
 * @property-read \app\common\model\Tag $tag
 */
class DocumentTag extends Model
{
    protected $name = 'document_tags';

        protected $type = [
        'document_id' => 'integer',
        'id' => 'integer',
        'tag_id' => 'integer',
    ];

    public $timestamps = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }
}
