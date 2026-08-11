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
 * Class app\common\model\MemberRechargeOrder
 *
 * @property float $amount 实付金额
 * @property int $days 顺延天数
 * @property int $id 主键
 * @property int $level_id 升级等级
 * @property int $package_id 套餐 ID
 * @property int $points 赠送积分
 * @property int $user_id 会员用户 ID
 * @property string $created_at 购买时间
 * @property string $package_title 套餐名称快照
 * @property string $pay_order_no 关联支付单号 payment_orders.order_no
 * @property-read \app\common\model\MemberLevel $level
 * @property-read \app\common\model\MemberRechargePackage $package
 * @property-read \app\common\model\User $user
 */
class MemberRechargeOrder extends Model
{
    protected $name = 'member_recharge_orders';

        protected $type = [
        'amount' => 'float',
        'days' => 'integer',
        'id' => 'integer',
        'level_id' => 'integer',
        'package_id' => 'integer',
        'points' => 'integer',
        'user_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function level(): BelongsTo
    {
        return $this->belongsTo(MemberLevel::class, 'level_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(MemberRechargePackage::class, 'package_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}
