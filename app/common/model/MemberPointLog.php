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
 * Class app\common\model\MemberPointLog
 *
 * @property int $admin_id 操作管理员ID
 * @property int $balance 变动后余额
 * @property int $delta 变动积分（正负）
 * @property int $id 主键
 * @property int $user_id 会员用户ID
 * @property string $created_at 创建时间
 * @property string $reason 说明
 * @property-read \app\common\model\User $admin
 * @property-read \app\common\model\User $user
 */
class MemberPointLog extends Model
{
    protected $name = 'member_point_logs';

        protected $type = [
        'admin_id' => 'integer',
        'balance' => 'integer',
        'delta' => 'integer',
        'id' => 'integer',
        'user_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

}
