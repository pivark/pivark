<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace weapp\doc_comment\model;


use app\common\model\MemberLevel;
use think\Model;
use think\model\relation\BelongsTo;

/**
 * Class weapp\doc_comment\model\WeappDocCommentLevel
 *
 * @property int $can_comment 是否允许评论
 * @property int $id 主键
 * @property int $member_level_id 会员等级 ID，0=游客
 * @property int $need_review 是否须审核
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 *  */
class WeappDocCommentLevel extends Model
{
    protected $name = 'weapp_doc_comment_levels';

        protected $type = [
        'can_comment' => 'integer',
        'id' => 'integer',
        'member_level_id' => 'integer',
        'need_review' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function memberLevel(): BelongsTo
    {
        return $this->belongsTo(MemberLevel::class, 'member_level_id');
    }

}
