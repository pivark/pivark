<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace weapp\doc_comment\model;


use think\Model;
use think\model\relation\BelongsTo;

/**
 * Class weapp\doc_comment\model\WeappDocCommentLike
 *
 * @property int $comment_id 评论 ID
 * @property int $id 主键
 * @property int $user_id 会员 ID
 * @property string $created_at 创建时间
 *  * @property-read \weapp\doc_comment\model\WeappDocComment $comment
 */
class WeappDocCommentLike extends Model
{
    protected $name = 'weapp_doc_comment_likes';

        protected $type = [
        'comment_id' => 'integer',
        'id' => 'integer',
        'user_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function comment(): BelongsTo
    {
        return $this->belongsTo(WeappDocComment::class, 'comment_id');
    }
}
