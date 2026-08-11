<?php
declare(strict_types=1);

use app\common\service\ai\AiProviderCatalog;

/** 敏感配置键 SSOT（阶段 G · 与 AdminSensitiveConfirm / 模板 / config_secrets 对齐） */
$aiCredentialKeys = [];
foreach (array_keys((new AiProviderCatalog())->all()) as $providerId) {
    $aiCredentialKeys[] = $providerId . '_api_key';
}
$aiCredentialKeys[] = 'ai_ocr_baidu_api_key';
$aiCredentialKeys[] = 'ai_ocr_baidu_secret_key';

return [
    /** 后台保存/支付等操作须二次确认（含 CacheConfigService 缓存键） */
    'admin_confirm' => [
        'cache_driver',
        'redis_cache_host',
        'redis_cache_port',
        'redis_cache_password',
        'redis_cache_select',
        'redis_cache_prefix',
        'site_key',
        'upload_direct_enabled',
        'upload_direct_sign_secret',
        'upload_direct_oss_access_key',
        'upload_direct_oss_secret_key',
        'upload_direct_cos_secret_id',
        'upload_direct_cos_secret_key',
        'payment_alipay_app_id',
        'payment_alipay_private_key',
        'payment_alipay_public_key',
        'payment_wechat_app_id',
        'payment_wechat_mch_id',
        'payment_wechat_api_v3_key',
        'payment_wechat_serial_no',
        'payment_wechat_private_key',
        'payment_pay_mode',
        'admin_entry_alias',
        'mail_smtp_pass',
        'sms_secret_key',
        'cron_webhook_token',
        'ip_access_mode',
        'ip_allowlist',
        'ip_blocklist',
    ],

    /** 须存 config_secrets（加密）· 禁止出现在 configs 明文 · 禁止 {pv:config} */
    'credentials' => array_values(array_unique(array_merge(
        [
            'redis_cache_password',
            'upload_direct_sign_secret',
            'upload_direct_oss_access_key',
            'upload_direct_oss_secret_key',
            'upload_direct_cos_secret_id',
            'upload_direct_cos_secret_key',
            'payment_alipay_private_key',
            'payment_alipay_public_key',
            'payment_wechat_api_v3_key',
            'payment_wechat_private_key',
            'mail_smtp_pass',
            'sms_secret_key',
            'cron_webhook_token',
        ],
        $aiCredentialKeys,
    ))),

    /** {pv:config key="..."} 禁止读取（credentials + 高敏 admin 键） */
    'template_forbidden_extra' => [
        'site_key',
        'payment_alipay_app_id',
        'payment_alipay_public_key',
        'payment_wechat_app_id',
        'payment_wechat_mch_id',
        'payment_wechat_serial_no',
    ],
];
