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
 * Class app\common\model\AiConfigProcess
 *
 * @property int $chunk_count 分块数量
 * @property int $document_id 文档 ID
 * @property int $plain_chars 纯文本字符数
 * @property int $updated_at 更新时间
 * @property string $error_msg 错误信息
 * @property string $status pending|processing|ok|extract_failed|chunk_failed|meta_failed
 * @property-read \app\common\model\Document $document
 */
class AiConfigProcess extends Model
{
    protected $name = 'ai_config_process';

    protected $type = [
        'chunk_count' => 'integer',
        'document_id' => 'integer',
        'plain_chars' => 'integer',
        'updated_at'  => 'integer',
    ];

    protected $autoWriteTimestamp = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
