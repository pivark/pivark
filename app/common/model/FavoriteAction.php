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
 * L1 文档收藏/点赞动作（表 favorite_actions）
 *
 * @property int $document_id 文档 ID
 * @property int $id 主键
 * @property int $user_id 会员 ID
 * @property string $action like|collect
 * @property string $created_at 创建时间
 * @property string $visitor_key 访客指纹 hash
 * @property-read Document $document
 * @property-read User $user
 */
class FavoriteAction extends Model
{
    protected $name = 'favorite_actions';

    protected $type = [
        'document_id' => 'integer',
        'id'          => 'integer',
        'user_id'     => 'integer',
    ];

    protected $autoWriteTimestamp = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
