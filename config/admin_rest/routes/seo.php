<?php
/**
 * 后台 REST SEO 域路由（/api/v1/admin/seo/*）
 */
declare(strict_types=1);

use app\admin\controller\seo\Seo;

/**
 * @param array{0:class-string,1:string} $handler
 * @return array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>}
 */
$seo = static function (
    string $method,
    string $path,
    array $handler,
    ?string $permissionController = null,
    ?string $permissionAction = null,
): array {
    $controller = $permissionController ?? 'seo';

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
    $seo('GET', 'seo/url', [Seo::class, 'url'], 'seo', 'url'),
    $seo('POST', 'seo/url', [Seo::class, 'urlSave'], 'seo', 'urlsave'),
    $seo('GET', 'seo/static', [Seo::class, 'static'], 'seo', 'static'),
    $seo('POST', 'seo/static/generate', [Seo::class, 'staticGenerate']),
    $seo('POST', 'seo/static/batch/start', [Seo::class, 'staticBatchStart']),
    $seo('POST', 'seo/static/batch/step', [Seo::class, 'staticBatchStep']),
    $seo('GET', 'seo/sitemap', [Seo::class, 'sitemap'], 'seo', 'sitemap'),
    $seo('POST', 'seo/sitemap', [Seo::class, 'sitemapSave']),
    $seo('POST', 'seo/sitemap/rebuild', [Seo::class, 'sitemapRebuild']),
    $seo('GET', 'seo/robots', [Seo::class, 'robots'], 'seo', 'robots'),
    $seo('POST', 'seo/robots', [Seo::class, 'robotsSave']),
    $seo('POST', 'seo/robots/reset', [Seo::class, 'robotsReset']),
    $seo('POST', 'seo/baidu-push/batch', [Seo::class, 'baiduPushBatch']),
];
