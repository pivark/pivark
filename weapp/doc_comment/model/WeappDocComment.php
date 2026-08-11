<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace weapp\doc_comment\model;


use app\common\model\Document;
use think\Model;
use think\model\relation\BelongsTo;
use think\model\relation\HasMany;

/**
 * Class weapp\doc_comment\model\WeappDocComment
 *
 * @property int $document_id 文档 ID
 * @property int $id 主键
 * @property int $like_count 点赞数
 * @property int $parent_id 父评论 ID，0=顶级
 * @property int $status 0待审核 1已通过
 * @property int $user_id 会员 ID，0=游客
 * @property string $content 评论内容
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string $user_ip IP
 * @property string $username 显示名
 *  *  * @property-read \weapp\doc_comment\model\WeappDocComment $parent
 * @property-read \weapp\doc_comment\model\WeappDocComment[] $replies
 */
class WeappDocComment extends Model
{
    protected $name = 'weapp_doc_comment_comments';

        protected $type = [
        'document_id' => 'integer',
        'id' => 'integer',
        'like_count' => 'integer',
        'parent_id' => 'integer',
        'status' => 'integer',
        'user_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
