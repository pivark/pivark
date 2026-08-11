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
 * Class app\common\model\MemberBalanceLog
 *
 * @property float $balance 变动后余额
 * @property float $delta 变动金额（正负，元）
 * @property int $admin_id 操作管理员ID
 * @property int $id 主键
 * @property int $user_id 会员用户ID
 * @property string $created_at 创建时间
 * @property string $reason 说明
 * @property string $ref 幂等引用（如 pay:订单号）
 * @property-read \app\common\model\User $admin
 * @property-read \app\common\model\User $user
 */
class MemberBalanceLog extends Model
{
    protected $name = 'member_balance_logs';

        protected $type = [
        'admin_id' => 'integer',
        'balance' => 'float',
        'delta' => 'float',
        'id' => 'integer',
        'user_id' => 'integer',
    ];

    /** 支付入账 ref 前缀（MemberBalanceService::payRef） */
    public const REF_PREFIX_PAY = 'pay:';

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
