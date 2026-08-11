<?php
/**
 * 后台 REST 站点域路由（/api/v1/admin/site-nav|site-pages|site-forms|…）
 */
declare(strict_types=1);

use app\admin\controller\site\Cockpit;
use app\admin\controller\site\Favorite;
use app\admin\controller\site\SiteAdSlot;
use app\admin\controller\site\SiteDomain;
use app\admin\controller\site\SiteForm;
use app\admin\controller\site\SiteLink;
use app\admin\controller\site\SiteNav;
use app\admin\controller\site\SitePage;
use app\admin\controller\site\SiteSlide;

/**
 * @param array{0:class-string,1:string} $handler
 * @return array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>}
 */
$site = static function (
    string $method,
    string $path,
    array $handler,
    ?string $permissionController = null,
    ?string $permissionAction = null,
): array {
    $controller = $permissionController ?? strtolower(
        preg_replace('/^.*\\\\/', '', $handler[0]) ?: 'site',
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
    // site-nav
    $site('GET', 'site-nav', [SiteNav::class, 'index'], 'sitenav', 'index'),
    $site('GET', 'site-nav/options', [SiteNav::class, 'options']),
    $site('POST', 'site-nav', [SiteNav::class, 'save'], 'sitenav', 'save'),
    $site('POST', 'site-nav/sort', [SiteNav::class, 'sort']),
    $site('POST', 'site-nav/status', [SiteNav::class, 'status']),
    $site('POST', 'site-nav/delete', [SiteNav::class, 'delete']),

    // site-pages
    $site('GET', 'site-pages', [SitePage::class, 'index'], 'sitepage', 'index'),
    $site('GET', 'site-pages/form', [SitePage::class, 'form']),
    $site('POST', 'site-pages', [SitePage::class, 'save'], 'sitepage', 'save'),
    $site('POST', 'site-pages/status', [SitePage::class, 'status']),
    $site('POST', 'site-pages/delete', [SitePage::class, 'delete']),

    // site-forms
    $site('GET', 'site-forms', [SiteForm::class, 'index'], 'site_form', 'index'),
    $site('GET', 'site-forms/submissions/detail', [SiteForm::class, 'submissionDetail']),
    $site('GET', 'site-forms/submissions/export', [SiteForm::class, 'submissionExport']),
    $site('GET', 'site-forms/submissions/export-async/start', [SiteForm::class, 'submissionExportAsyncStart']),
    $site('GET', 'site-forms/submissions/export-async/step', [SiteForm::class, 'submissionExportAsyncStep']),
    $site('GET', 'site-forms/submissions/export-async/download', [SiteForm::class, 'submissionExportAsyncDownload']),
    $site('POST', 'site-forms', [SiteForm::class, 'save'], 'site_form', 'save'),
    $site('POST', 'site-forms/delete', [SiteForm::class, 'delete']),
    $site('POST', 'site-forms/batch-delete', [SiteForm::class, 'batchDelete']),
    $site('POST', 'site-forms/submissions/status', [SiteForm::class, 'submissionStatus']),
    $site('POST', 'site-forms/submissions/batch-status', [SiteForm::class, 'submissionBatchStatus']),
    $site('POST', 'site-forms/submissions/delete', [SiteForm::class, 'submissionDelete']),

    // site-ad-slots / site-slides
    $site('GET', 'site-ad-slots', [SiteAdSlot::class, 'index'], 'siteadslot', 'index'),
    $site('GET', 'site-ad-slots/suggest-code', [SiteAdSlot::class, 'suggestCode']),
    $site('POST', 'site-ad-slots', [SiteAdSlot::class, 'save'], 'siteadslot', 'save'),
    $site('POST', 'site-ad-slots/delete', [SiteAdSlot::class, 'delete']),
    $site('GET', 'site-slides', [SiteSlide::class, 'index'], 'siteslide', 'index'),
    $site('POST', 'site-slides', [SiteSlide::class, 'save'], 'siteslide', 'save'),
    $site('POST', 'site-slides/sort', [SiteSlide::class, 'sort']),
    $site('POST', 'site-slides/status', [SiteSlide::class, 'status']),
    $site('POST', 'site-slides/delete', [SiteSlide::class, 'delete']),

    // site-domains
    $site('GET', 'site-domains', [SiteDomain::class, 'index'], 'sitedomain', 'index'),
    $site('GET', 'site-domains/meta', [SiteDomain::class, 'meta']),
    $site('GET', 'site-domains/tags-for-group', [SiteDomain::class, 'tagsForGroup']),
    $site('POST', 'site-domains', [SiteDomain::class, 'save'], 'sitedomain', 'save'),
    $site('POST', 'site-domains/sort', [SiteDomain::class, 'sort']),
    $site('POST', 'site-domains/status', [SiteDomain::class, 'status']),
    $site('POST', 'site-domains/delete', [SiteDomain::class, 'delete']),

    $site('GET', 'site/domain/meta', [SiteDomain::class, 'meta']),
    $site('GET', 'site/domain/tags-for-group', [SiteDomain::class, 'tagsForGroup']),

    // site-links
    $site('GET', 'site-links', [SiteLink::class, 'index'], 'sitelink', 'index'),
    $site('POST', 'site-links', [SiteLink::class, 'save'], 'sitelink', 'save'),
    $site('POST', 'site-links/sort', [SiteLink::class, 'sort']),
    $site('POST', 'site-links/status', [SiteLink::class, 'status']),
    $site('POST', 'site-links/delete', [SiteLink::class, 'delete']),
    $site('POST', 'site-links/batch-delete', [SiteLink::class, 'batchDelete']),

    // site-favorites (L1)
    $site('GET', 'site-favorites', [Favorite::class, 'index'], 'site_favorite', 'index'),
    $site('POST', 'site-favorites/config', [Favorite::class, 'configSave'], 'site_favorite', 'configsave'),

    // cockpit
    $site('GET', 'site-cockpit', [Cockpit::class, 'index'], 'cockpit', 'index'),
];
