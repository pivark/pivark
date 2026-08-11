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
 * Class app\common\model\SearchIndexQueue
 *
 * @property int $attempts 已尝试次数
 * @property int $document_id 文档 ID
 * @property int $id 主键
 * @property string $action upsert|delete
 * @property string $created_at 入队时间
 * @property string $last_error 上次错误摘要
 * @property string $updated_at 更新时间
 * @property-read \app\common\model\Document $document
 */
class SearchIndexQueue extends Model
{
    protected $name = 'search_index_queue';

        protected $type = [
        'attempts' => 'integer',
        'document_id' => 'integer',
        'id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

}
