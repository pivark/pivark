<?php
/**
 * 后台 REST 产品中心 + 运维路由（products|cron|upgrade|access-stats|channel-configs…）
 */
declare(strict_types=1);

use app\admin\controller\Index;
use app\admin\controller\plugin\AccessStats;
use app\admin\controller\product\Product;
use app\admin\controller\site\FloatContact;
use app\admin\controller\system\AiConfig;
use app\admin\controller\system\CaptchaConfig;
use app\admin\controller\system\ChannelsConfig;
use app\admin\controller\system\Cron;
use app\admin\controller\system\DataRetention;
use app\admin\controller\system\RateLimit;
use app\admin\controller\system\LoginNoticeConfig;
use app\admin\controller\system\MailConfig;
use app\admin\controller\system\MiniprogramConfig;
use app\admin\controller\system\MiniprogramPage;
use app\admin\controller\system\SmsConfig;
use app\admin\controller\system\Upgrade;
use app\admin\controller\system\WatermarkConfig;
use app\admin\controller\system\SqlConsole;

/** @param array{0:class-string,1:string} $handler */
$op = static function (
    string $method,
    string $path,
    array $handler,
    ?string $permissionController = null,
    ?string $permissionAction = null,
): array {
    $controller = $permissionController ?? strtolower(
        preg_replace('/^.*\\\\/', '', $handler[0]) ?: 'ops',
    );

    return [
        'method'  => $method,
        'path'    => $path,
        'handler' => $handler,
        'options' => [
            'permission_controller' => $controller,
            'permission_action'     => $permissionAction ?? strtolower((string) $handler[1]),
        ],
    ];
};

return [
    // products
    $op('GET', 'products', [Product::class, 'index'], 'product', 'index'),
    $op('GET', 'products/config', [Product::class, 'config'], 'product', 'index'),
    $op('POST', 'products/config', [Product::class, 'configSave'], 'product', 'configsave'),
    $op('POST', 'products/groups', [Product::class, 'groupSave']),
    $op('POST', 'products/groups/delete', [Product::class, 'groupDelete']),
    $op('POST', 'products/groups/batch-status', [Product::class, 'groupBatchStatus']),
    $op('POST', 'products/groups/batch-delete', [Product::class, 'groupBatchDelete']),
    $op('POST', 'products/params', [Product::class, 'paramSave']),
    $op('POST', 'products/params/delete', [Product::class, 'paramDelete']),
    $op('POST', 'products/params/sort', [Product::class, 'paramSort']),

    // access-stats (plugin)
    $op('GET', 'access-stats', [AccessStats::class, 'index'], 'accessstats', 'index'),
    $op('POST', 'access-stats/config', [AccessStats::class, 'configSave']),
    $op('GET', 'access-stats/dead-links', [AccessStats::class, 'deadLinks']),
    $op('POST', 'access-stats/dead-links', [AccessStats::class, 'deadLinks']),

    // float-contact (L1)
    $op('POST', 'float-contact/config', [FloatContact::class, 'configSave'], 'float_contact', 'configsave'),
    $op('POST', 'float-contact', [FloatContact::class, 'save'], 'float_contact', 'save'),
    $op('POST', 'float-contact/delete', [FloatContact::class, 'delete']),
    $op('POST', 'float-contact/status', [FloatContact::class, 'status']),
    $op('POST', 'float-contact/sort', [FloatContact::class, 'sort']),

    // ai-config (L1)
    $op('POST', 'ai-config/config', [AiConfig::class, 'configSave'], 'ai_config', 'configsave'),
    $op('POST', 'ai-config/test-connection', [AiConfig::class, 'testConnection']),
    $op('POST', 'ai-config/generate-meta', [AiConfig::class, 'generateMeta']),
    $op('POST', 'ai-config/process-document', [AiConfig::class, 'processDocument']),
    $op('GET', 'ai-config/process-status', [AiConfig::class, 'processStatus']),
    $op('POST', 'ai-config/test-extract', [AiConfig::class, 'testExtract']),
    $op('POST', 'ai-config/test-ocr', [AiConfig::class, 'testOcr']),

    // channel configs
    $op('GET', 'captcha/config', [CaptchaConfig::class, 'index'], 'captchaconfig', 'index'),
    $op('POST', 'captcha/config', [CaptchaConfig::class, 'save'], 'captcha', 'configsave'),
    $op('GET', 'channels/config', [ChannelsConfig::class, 'index'], 'channelsconfig', 'index'),
    $op('GET', 'mail/config', [MailConfig::class, 'index'], 'mailconfig', 'index'),
    $op('POST', 'mail/config', [MailConfig::class, 'save'], 'mail', 'configsave'),
    $op('POST', 'mail/apply-ethereal', [MailConfig::class, 'applyEthereal']),
    $op('POST', 'mail/test-send', [MailConfig::class, 'testSend']),
    $op('GET', 'sms/config', [SmsConfig::class, 'index'], 'smsconfig', 'index'),
    $op('POST', 'sms/config', [SmsConfig::class, 'save'], 'sms', 'configsave'),
    $op('POST', 'sms/test-send', [SmsConfig::class, 'testSend']),
    $op('GET', 'login-notice/config', [LoginNoticeConfig::class, 'index'], 'loginnoticeconfig', 'index'),
    $op('POST', 'login-notice/config', [LoginNoticeConfig::class, 'save'], 'login_notice', 'configsave'),
    $op('POST', 'watermark/config', [WatermarkConfig::class, 'save'], 'watermark', 'configsave'),
    $op('GET', 'miniprogram/config', [MiniprogramConfig::class, 'index'], 'miniprogramconfig', 'index'),
    $op('POST', 'miniprogram/config', [MiniprogramConfig::class, 'save'], 'miniprogram', 'configsave'),
    $op('GET', 'miniprogram/pages', [MiniprogramPage::class, 'index']),
    $op('GET', 'miniprogram/guide', [MiniprogramConfig::class, 'guide']),
    $op('POST', 'miniprogram/pages', [MiniprogramPage::class, 'save']),
    $op('POST', 'miniprogram/pages/preview', [MiniprogramPage::class, 'preview'], 'miniprogram', 'pagepreview'),
    $op('POST', 'miniprogram/pages/layout', [MiniprogramPage::class, 'layout'], 'miniprogram', 'pagesave'),
    $op('GET', 'miniprogram/wechat-sdk', [MiniprogramPage::class, 'wechatSdkDownload'], 'miniprogram', 'wechatsdkdownload'),

    // site license（原 Spa::licenseActivate|licenseSync）
    $op('POST', 'license/activate', [Upgrade::class, 'licenseActivate'], 'upgrade', 'licenseactivate'),
    $op('POST', 'license/sync', [Upgrade::class, 'licenseSync'], 'upgrade', 'licensesync'),

    // audit（原 Spa::reportDataAccessDenied）
    $op('POST', 'audit/data-access-denied', [Index::class, 'reportDataAccessDenied'], 'index', 'reportdataaccessdenied'),

    // upgrade
    $op('GET', 'upgrade/meta', [Upgrade::class, 'meta'], 'upgrade', 'meta'),
    $op('GET', 'upgrade/check', [Upgrade::class, 'check'], 'upgrade', 'check'),
    $op('GET', 'upgrade/files-compare', [Upgrade::class, 'filesCompare'], 'upgrade', 'check'),
    $op('POST', 'upgrade/files-compare/apply', [Upgrade::class, 'filesCompareApply'], 'upgrade', 'check'),
    $op('GET', 'upgrade/impact-preview', [Upgrade::class, 'impactPreview'], 'upgrade', 'check'),
    $op('POST', 'upgrade/core-apply', [Upgrade::class, 'coreApply']),
    $op('POST', 'upgrade/core-ladder-apply', [Upgrade::class, 'coreLadderApply']),
    $op('GET', 'upgrade/core-step/status', [Upgrade::class, 'coreStepStatus']),
    $op('POST', 'upgrade/core-step/prepare', [Upgrade::class, 'coreStepPrepare']),
    $op('POST', 'upgrade/core-step/download', [Upgrade::class, 'coreStepDownload']),
    $op('POST', 'upgrade/core-step/backup', [Upgrade::class, 'coreStepBackup']),
    $op('POST', 'upgrade/core-step/apply', [Upgrade::class, 'coreStepApply']),
    $op('POST', 'upgrade/core-step/migrate', [Upgrade::class, 'coreStepMigrate']),
    $op('POST', 'upgrade/core-step/finalize', [Upgrade::class, 'coreStepFinalize']),
    $op('POST', 'upgrade/core-step/abort', [Upgrade::class, 'coreStepAbort']),
    $op('POST', 'upgrade/core-restore', [Upgrade::class, 'coreRestore']),

    // ops cache（原 Spa::clearCache）
    $op('GET', 'ops/cache/clear', [Index::class, 'clearCache'], 'index', 'clearcache'),

    // sql-console（高危：须 admin.sql_console.run + 敏感确认）
    $op('GET', 'sql-console', [SqlConsole::class, 'meta'], 'sqlconsole', 'meta'),
    $op('POST', 'sql-console/execute', [SqlConsole::class, 'execute'], 'sqlconsole', 'execute'),
    $op('POST', 'sql-console/import', [SqlConsole::class, 'import'], 'sqlconsole', 'import'),

    // data-retention
    $op('GET', 'data-retention', [DataRetention::class, 'index'], 'data_retention', 'index'),
    $op('POST', 'data-retention', [DataRetention::class, 'save'], 'data_retention', 'save'),

    // rate-limit
    $op('GET', 'rate-limit', [RateLimit::class, 'index'], 'data_retention', 'index'),
    $op('POST', 'rate-limit', [RateLimit::class, 'save'], 'data_retention', 'save'),
    $op('POST', 'rate-limit/bust', [RateLimit::class, 'bust'], 'data_retention', 'save'),

    // cron
    $op('GET', 'cron-jobs', [Cron::class, 'index'], 'cron', 'index'),
    $op('POST', 'cron-jobs', [Cron::class, 'save'], 'cron', 'save'),
    $op('POST', 'cron-jobs/status', [Cron::class, 'status']),
    $op('POST', 'cron-jobs/delete', [Cron::class, 'delete']),
    $op('POST', 'cron-jobs/run', [Cron::class, 'run']),
    $op('GET', 'cron-jobs/event-panel', [Cron::class, 'eventPanel']),
    $op('POST', 'cron-jobs/event-async', [Cron::class, 'eventAsync']),
    $op('GET', 'cron-jobs/event-dlq', [Cron::class, 'eventDlq']),
    $op('POST', 'cron-jobs/event-dlq/delete', [Cron::class, 'eventDlqDelete']),
    $op('POST', 'cron-jobs/event-dlq/replay', [Cron::class, 'eventDlqReplay']),
    $op('POST', 'cron-jobs/event-drain-queue', [Cron::class, 'eventDrainQueue']),
];
