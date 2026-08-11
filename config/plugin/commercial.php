<?php
/**
 * 商业插件加密与离线授权（本地化，不依赖 Pivark 云端验票）
 */
return [
    // 构建：pivark 内置编码 | ioncube（需配置编码器路径）
    'encode_driver' => strtolower(trim((string) env('PIVARK_ENCODE_DRIVER', 'pivark'))),

    // 编码密钥（构建机与站点须一致；仅用于 .pve 载荷，不替代 Entitlement）
    'encode_key' => trim((string) env('PIVARK_ENCODE_KEY', '')),

    // 离线授权文件 HMAC（导出 .pivark-license 用）
    'license_secret' => trim((string) env('PIVARK_PLUGIN_LICENSE_SECRET', '')),

    /** 离线授权默认最长有效天数（非 perpetual 且未指定 expire_at 时） */
    'offline_license_max_days' => max(1, (int) env('PIVARK_PLUGIN_OFFLINE_LICENSE_MAX_DAYS', 365)),

    // 插件 zip HMAC 验签（与 encode/license 独立配置）
    'sign_key' => trim((string) env('PIVARK_PLUGIN_SIGN_KEY', '')),

    // 生产默认要求官方包签名（APP_ENV=production 且未显式配置时）
    'sign_required' => filter_var(
        env('PIVARK_PLUGIN_SIGN_REQUIRED', in_array(strtolower(trim((string) env('APP_ENV', ''))), ['production', 'prod'], true) ? '1' : '0'),
        FILTER_VALIDATE_BOOLEAN
    ),

    // ionCube 编码器可执行文件（Windows/Linux 构建机）
    'ioncube_encoder' => trim((string) env('PIVARK_IONCUBE_ENCODER', '')),

    // 唯一标装：明文 source_open，不构建 .pve（见 PluginCommercialPackageService::sourceOpenIdentifier）
    'source_open_identifier' => '',

    // 安装 subscription 插件时自动 grant 试用（生产默认开；PIVARK_ENV=dev 亦开）
    'auto_grant_trial_on_install' => filter_var(
        env('PIVARK_AUTO_GRANT_PLUGIN_TRIAL', '1'),
        FILTER_VALIDATE_BOOLEAN
    ),

    // 市场「限时免费」默认试用天数
    'default_trial_days' => max(1, (int) env('PIVARK_PLUGIN_TRIAL_DAYS', 90)),

    // 开源版 是否允许插件市场站内下单购买（order: 履约 grant）
    'in_site_purchase' => filter_var(env('PIVARK_PLUGIN_IN_SITE_PURCHASE', '1'), FILTER_VALIDATE_BOOLEAN),

    // 插件授权失效后投递静态页增量重建
    'rebuild_static_on_entitlement_lost' => filter_var(env('PIVARK_PLUGIN_REBUILD_STATIC_ON_LOST', '1'), FILTER_VALIDATE_BOOLEAN),

    // 走加密轨的 identifier：* = weapp/* 除标装外全部；或逗号列表
    // 运行时解析见 PluginCommercialPackageService::listEncodedIdentifiers()
    'encoded_identifiers' => array_values(array_filter(array_map(
        static fn (string $id): string => strtolower(trim($id)),
        explode(',', (string) env('PIVARK_ENCODED_PLUGINS', '*'))
    ))),

    // 业务连续性：导入离线授权时是否允许覆盖已有 active 授权
    'license_import_overwrite' => true,

    // Cron 扫描即将到期的 subscription 授权（仅提醒或按策略续费）
    'auto_renew_enabled' => filter_var(env('PIVARK_PLUGIN_AUTO_RENEW_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN),

    // 为 true 且支付演示模式时：自动建单+markPaid+fulfill；否则仅写审计待办
    'auto_renew_apply' => filter_var(env('PIVARK_PLUGIN_AUTO_RENEW_APPLY', '0'), FILTER_VALIDATE_BOOLEAN),

    // 到期前 N 天内纳入续费扫描
    'auto_renew_days_before' => max(1, (int) env('PIVARK_PLUGIN_AUTO_RENEW_DAYS_BEFORE', 3)),

    'auto_renew_prefer_balance' => filter_var(env('PIVARK_PLUGIN_AUTO_RENEW_PREFER_BALANCE', '1'), FILTER_VALIDATE_BOOLEAN),
];
