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
 * 插件钱包流水 pv_site_plugin_wallet_ledger
 *
 * @property int $balance_after 变动后余额，NULL=不限
 * @property int $delta 变动（正=充值，负=扣减）
 * @property int $id 主键
 * @property string $created_at 创建时间
 * @property string $detail 说明
 * @property string $plugin_identifier 插件标识
 * @property string $reason grant_trial/order/consume/subscription_quota 等
 * @property string $ref 关联单号/项目ID
 */
class SitePluginWalletLedger extends Model
{
    protected $name = 'site_plugin_wallet_ledger';

    protected $type = [
        'id'            => 'integer',
        'delta'         => 'integer',
        'balance_after' => 'integer',
    ];

    public $timestamps = false;
}
