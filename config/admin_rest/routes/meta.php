<?php
/**
 * 后台 REST meta 路由（/api/v1/admin/meta/*）
 *
 * 由 config/admin_rest/dispatch.php 合并；handler 均在 Spa 控制器。
 */
declare(strict_types=1);

use app\admin\controller\Spa;

/** @return array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>,pattern?:array<string,string>,handler_params?:list<string>} */
$meta = static function (string $path, string $action, array $extra = []): array {
    return array_merge([
        'method'  => 'GET',
        'path'    => $path,
        'handler' => [Spa::class, $action],
        'options' => [
            'permission_controller' => 'spa',
            'permission_action'     => strtolower($action),
        ],
    ], $extra);
};

return [
    $meta('meta/tags', 'tagMeta'),
    $meta('meta/documents', 'documentMeta'),
    $meta('meta/logs', 'logMeta'),
    $meta('meta/tags/form', 'tagFormMeta'),
    $meta('meta/config', 'configMeta'),
    $meta('meta/documents/form', 'documentFormMeta'),
    $meta('meta/users/form', 'userFormMeta'),
    $meta('meta/roles/form', 'roleFormMeta'),
    $meta('meta/members/form', 'memberFormMeta'),
    $meta('meta/cron', 'cronMeta'),
    $meta('meta/ai-config', 'aiConfigMeta'),
    $meta('meta/site-nav', 'siteNavMeta'),
    $meta('meta/site-pages/form', 'sitePageFormMeta'),
    $meta('meta/member-center', 'memberCenterConfigMeta'),
    $meta('meta/payment-config', 'paymentConfigMeta'),
    $meta('meta/payment-orders', 'paymentOrdersMeta'),
    $meta('meta/miniprogram-wechat', 'miniprogramWechatMeta'),
    $meta('meta/miniprogram-pages', 'miniprogramPageMeta'),
    $meta('meta/search-config', 'searchConfigMeta'),
    $meta('meta/captcha-config', 'captchaConfigMeta'),
    $meta('meta/login-notice-settings', 'loginNoticeSettingsMeta'),
    $meta('meta/mail-config', 'mailConfigMeta'),
    $meta('meta/sms-config', 'smsConfigMeta'),
    $meta('meta/member-points-config', 'memberPointsConfigMeta'),
    $meta('meta/member-center-fields/form', 'memberCenterFieldMeta'),
    $meta('meta/member-center-recharge/form', 'memberCenterRechargeMeta'),
    $meta('meta/seo-url', 'seoUrlMeta'),
    $meta('meta/seo-sitemap', 'seoSitemapMeta'),
    $meta('meta/seo-robots', 'seoRobotsMeta'),
    $meta('meta/plugin-scaffold', 'pluginScaffoldMeta'),
    $meta('meta/seo-static', 'seoStaticMeta'),
    $meta('meta/float-contact', 'floatContactMeta'),
    $meta('meta/plugins/:plugin', 'pluginMeta', [
        'pattern'        => ['plugin' => '[a-z0-9_-]+'],
        'handler_params' => ['plugin'],
    ]),
];
