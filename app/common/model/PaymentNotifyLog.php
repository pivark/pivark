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

/**
 * L1 支付回调日志（表 payment_notify_logs）
 *
 * @property int $id 主键
 * @property int $verified 验签是否通过
 * @property string $channel alipay|wechat
 * @property string $created_at 创建时间
 * @property string $order_no 商户订单号
 * @property string $payload_json 原始回调内容
 */
class PaymentNotifyLog extends Model
{
    protected $name = 'payment_notify_logs';

    protected $type = [
        'id'       => 'integer',
        'verified' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
