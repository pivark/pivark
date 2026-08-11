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
 * Class app\common\model\MemberSigninLog
 *
 * @property int $id 主键
 * @property int $points 字段：points
 * @property int $user_id 用户 ID
 * @property string $created_at 创建时间
 * @property string $sign_date 字段：sign_date
 * @property-read \app\common\model\User $user
 */
class MemberSigninLog extends Model
{
    protected $name = 'member_signin_logs';

        protected $type = [
        'id' => 'integer',
        'points' => 'integer',
        'user_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}
