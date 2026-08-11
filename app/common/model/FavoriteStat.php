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
 * L1 文档收藏/点赞统计（表 favorite_stats）
 *
 * @property int $collect_count 收藏数
 * @property int $document_id 文档 ID
 * @property int $like_count 点赞数
 * @property string $updated_at 更新时间
 * @property-read Document $document
 */
class FavoriteStat extends Model
{
    protected $name = 'favorite_stats';

    protected $type = [
        'collect_count' => 'integer',
        'document_id'   => 'integer',
        'like_count'    => 'integer',
    ];

    protected $autoWriteTimestamp = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
