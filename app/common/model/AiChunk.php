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
 * Class app\common\model\AiChunk
 *
 * @property int $chunk_index 分块序号
 * @property int $created_at 创建时间
 * @property int $document_id 文档 ID
 * @property int $id 主键
 * @property int $token_estimate Token估算
 * @property int $updated_at 更新时间
 * @property mixed $embedding_json 无 pgvector 时可选存本地向量
 * @property mixed $meta_json 元数据JSON
 * @property string $content 内容
 * @property string $content_hash 内容哈希
 * @property-read \app\common\model\Document $document
 */
class AiChunk extends Model
{
    protected $name = 'ai_chunks';

        protected $type = [
        'chunk_index' => 'integer',
        'created_at' => 'integer',
        'document_id' => 'integer',
        'id' => 'integer',
        'token_estimate' => 'integer',
        'updated_at' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

}
