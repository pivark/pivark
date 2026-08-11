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
 * L1 支付订单（表 payment_orders）
 *
 * @property float $amount 订单金额（元）
 * @property int $id 主键
 * @property int $scene_id 场景关联 ID
 * @property int $user_id 会员 ID
 * @property string $channel alipay|wechat
 * @property string $channel_txn_id 渠道交易号
 * @property string $created_at 创建时间
 * @property string $order_no 商户订单号
 * @property string $paid_at 支付完成时间
 * @property string $payload_json 业务扩展 JSON
 * @property string $scene 业务场景 recharge 等
 * @property string $status pending|paid|failed|closed
 * @property string $updated_at 更新时间
 * @property-read User $user
 */
class PaymentOrder extends Model
{
    protected $name = 'payment_orders';

    protected $type = [
        'amount'   => 'float',
        'id'       => 'integer',
        'scene_id' => 'integer',
        'user_id'  => 'integer',
    ];

    protected $autoWriteTimestamp = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
