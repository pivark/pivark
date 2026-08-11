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
 * 站点插件授权 pv_site_plugin_entitlements
 *
 * @property int $id 主键
 * @property string $capability_snapshot_json granted_features/SKU 快照 JSON
 * @property string $created_at 创建时间
 * @property string $expire_at 到期时间，NULL=永久
 * @property string $granted_by 授权来源：install/manual/order
 * @property string $license_type 授权类型：free/trial/paid/bundled
 * @property string $plugin_identifier 插件标识
 * @property string $status 状态：active有效 expired过期 disabled禁用
 * @property string $updated_at 更新时间
 */
class SitePluginEntitlement extends Model
{
    protected $name = 'site_plugin_entitlements';

        protected $type = [
        'id' => 'integer',
    ];

    public $timestamps = false;
}
