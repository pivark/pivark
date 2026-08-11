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
 * Class app\common\model\MemberCancelRequest
 *
 * @property int $handled_by 处理管理员ID
 * @property int $id 主键
 * @property int $status 状态：0待审 1已通过 2已驳回
 * @property int $user_id 申请会员ID
 * @property string $admin_remark 审核备注
 * @property string $created_at 申请时间
 * @property string $handled_at 处理时间
 * @property string $reason 注销原因
 * @property-read \app\common\model\User $user
 */
class MemberCancelRequest extends Model
{
    protected $name = 'member_cancel_requests';

        protected $type = [
        'handled_by' => 'integer',
        'id' => 'integer',
        'status' => 'integer',
        'user_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}
