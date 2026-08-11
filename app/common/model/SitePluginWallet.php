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
 * 站点插件计量钱包 pv_site_plugin_wallets
 *
 * @property int $id 主键
 * @property int $quota_remaining 剩余次数，NULL=不限
 * @property int $quota_total 当前周期总额
 * @property string $active_sku_id 最近生效 SKU
 * @property string $created_at 创建时间
 * @property string $period_end 订阅周期止
 * @property string $period_start 订阅周期起
 * @property string $plugin_identifier 插件标识（唯一）
 * @property string $status active/disabled
 * @property string $updated_at 更新时间
 * @property string $wallet_mode quota=计次 unlimited=不限次
 */
class SitePluginWallet extends Model
{
    protected $name = 'site_plugin_wallets';

    protected $type = [
        'id'              => 'integer',
        'quota_remaining' => 'integer',
        'quota_total'     => 'integer',
    ];

    public $timestamps = false;
}
