<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\home\controller;

use app\common\service\plugin\boot\PluginBootService;
use app\common\service\document\DocumentFormatService;
use app\common\service\document\DocumentPublicService;
use app\common\service\infra\BreadcrumbService;
use app\common\service\front\FrontRenderService;
use app\common\service\front\FrontUrlRuleService;
use app\common\service\product\ProductCatalogListPageService;
use app\common\service\product\ProductCatalogSidebarService;
use app\common\service\catalog\CatalogFacetPathService;
use app\common\service\front\FrontUrlResolver;
use app\common\service\site\SiteUrlModeService;
use app\common\service\template\ListPageTemplateContextService;
use app\common\service\infra\PaginationService;
use app\common\service\site\SiteNavService;
use app\common\service\site\SitePageService;
use app\common\service\tag\TagService;
use app\common\service\theme\ThemeService;
use app\common\service\theme\ThemeTemplateCatalogService;
use app\common\support\SiteUrl;
use think\facade\Request;
use think\Response;

/** 统一前台路径分发：/guanyu、/xinwen、/my-article 等 */
class Front extends Base
{
    public function path(string $path = ''): Response
    {
        app(PluginBootService::class)->bootstrapEnabled();
        $early = app(\app\common\service\weapp\WeappFrontGateway::class)->frontTryEarlyResponse();
        if ($early !== null) {
            return $early;
        }

        $raw    = (string) ($path ?: Request::param('path', ''));
        $queryPage = max(1, (int) Request::get('page', 1));
        $resolved = app(FrontUrlResolver::class)->resolve($raw, $queryPage);
        if ($resolved === null) {
            return $this->error('页面不存在');
        }

        return $this->dispatch($resolved);
    }

    public function pathPaged(string $path = '', string $page = '1'): Response
    {
        app(PluginBootService::class)->bootstrapEnabled();
        $early = app(\app\common\service\weapp\WeappFrontGateway::class)->frontTryEarlyResponse();
        if ($early !== null) {
            return $early;
        }

        $detail = $this->trySystemDetailFromPagedPath($path, $page);
        if ($detail !== null) {
            return $detail;
        }

        $raw       = (string) ($path ?: Request::param('path', ''));
        $queryPage = max(1, (int) Request::get('page', 1));
        $resolved  = app(FrontUrlResolver::class)->resolvePaged($raw, $page, $queryPage);
        if ($resolved === null) {
            return $this->error('页面不存在');
        }

        return $this->dispatch($resolved);
    }

    /**
     * :path/:page 误把「插件 path 前缀 + 段」当成分页时，交给 FrontPluginPathRegistry（各插件经 WeappFrontGateway 自行登记）。
     */
    private function trySystemDetailFromPagedPath(string $path, string $page): ?Response
    {
        if (app(FrontUrlRuleService::class)->parsePageSegment($page) > 0) {
            return null;
        }

        $rawPath = strtolower(trim(app(SiteUrlModeService::class)->stripSuffix(trim((string) ($path ?: Request::param('path', ''))))));
        $segment = strtolower(trim(app(SiteUrlModeService::class)->stripSuffix(trim((string) ($page ?: Request::param('page', ''))))));
        if ($rawPath === '' || $segment === '' || !preg_match('/^[a-z0-9_-]+$/', $segment)) {
            return null;
        }

        app(PluginBootService::class)->bootstrapEnabled();
        $resp = app(\app\common\service\front\FrontPluginPathRegistry::class)->dispatch($rawPath, $segment);
        if ($resp !== null) {
            return $resp;
        }

        return null;
    }

    /**
     * @param array{type:string,page:int,data?:array<string,mixed>,id?:int} $resolved
     */
    private function dispatch(array $resolved): Response
    {
        return match ($resolved['type']) {
            'page'     => $this->renderSitePage($resolved['data'] ?? []),
            'category' => $this->renderCategory($resolved['data'] ?? [], (int) ($resolved['page'] ?? 1)),
            'tag'      => $this->renderTag($resolved['data'] ?? [], (int) ($resolved['page'] ?? 1)),
            'document' => app(Document::class)->viewById((int) ($resolved['id'] ?? 0)),
            'item'     => app(ProductItem::class)->view((string) ($resolved['slug'] ?? '')),
            default    => $this->error('页面不存在'),
        };
    }

    /**
     * 真分类栏目列表（同 path 归 site_nav；不做 301）。
     *
     * @param array<string, mixed> $navRow
     */
    private function renderCategory(array $navRow, int $page): Response
    {
        $redirect = $this->maybeRedirectCatalogFacetQuery($navRow, static function (array $row): string {
            return trim((string) app(SiteNavService::class)->publicPathForContentCategory($row), '/');
        });
        if ($redirect !== null) {
            return $redirect;
        }

        $payload = app(FrontRenderService::class)->categoryListPayload($navRow, $page);
        if ($payload === null) {
            return $this->error('页面不存在');
        }
        $tpl = (string) ($payload['template'] ?? '');
        $theme = app(ThemeService::class)->getCurrentTheme();
        if ($tpl === '' || !app(ThemeService::class)->siteTemplateExistsWithFallback($tpl . '.php', $theme)) {
            return $this->error('页面模板不存在：' . $tpl);
        }

        return $this->render($tpl, $payload['vars']);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function renderSitePage(array $page): Response
    {
        $tplName = app(SitePageService::class)->normalizeTpl((string) ($page['tpl_name'] ?? ''));
        $tplFile = app(SitePageService::class)->resolveThemeTemplateFile($tplName);
        $theme = app(ThemeService::class)->getCurrentTheme();
        if (!app(ThemeService::class)->siteTemplateExistsWithFallback($tplFile . '.php', $theme)) {
            return $this->error('页面模板不存在：' . $tplName);
        }

        $payload = app(FrontRenderService::class)->sitePagePayload($page);
        if ($payload === null) {
            return $this->error('页面不存在');
        }

        return $this->render($payload['template'], $payload['vars']);
    }

    /**
     * @param array<string, mixed> $tagRow
     */
    private function renderTag(array $tagRow, int $page): Response
    {
        // 纯聚合专题；分类列表只走 Resolver→category（禁翻栏目兼容）
        $slug = (string) ($tagRow['slug'] ?? '');

        $redirect = $this->maybeRedirectCatalogFacetQuery($tagRow, static function (array $row): string {
            return trim((string) app(TagService::class)->publicPath($row), '/');
        });
        if ($redirect !== null) {
            return $redirect;
        }

        $page     = max(1, $page);
        $viewMeta = app(TagService::class)->buildPublicListViewVars($tagRow);
        $rawTpl   = (string) ($tagRow['tpl_name'] ?? '');
        $catalog  = app(ThemeTemplateCatalogService::class);
        $tpl      = $catalog->resolveTagListTpl($rawTpl);
        $extra    = array_merge(
            app(FrontRenderService::class)->channelListTopPageVars($tagRow),
            $this->productCatalogPageVars($tagRow, $slug, $page)
        );

        // 产品频道挂橱窗单页（插件市场/小程序/模板/定价）：走 data_contract，不当文档列表
        if ($catalog->isTagProductShowcaseTpl($rawTpl) || $catalog->isTagProductShowcaseTpl($tpl . '.php')) {
            $tplBase = $catalog->toCanonicalBasename($tpl !== '' ? $tpl : $rawTpl);
            $tagCtx  = [
                'tag_id'   => (int) ($tagRow['id'] ?? 0),
                'tag_slug' => strtolower(trim((string) ($tagRow['slug'] ?? ''))),
            ];
            $facetFilters = $tagRow['_catalog_facet_filters'] ?? null;
            if (is_array($facetFilters) && $facetFilters !== []) {
                $tagCtx['filters'] = $facetFilters;
            }
            $vars    = app(\app\common\service\theme\ThemePageDataContractService::class)->mergeTplVars(
                $tplBase,
                array_merge($viewMeta, $extra, [
                    'breadcrumbs'   => app(BreadcrumbService::class)->forTag($tagRow),
                    'tags_nav'      => app(DocumentPublicService::class)->buildTagsNav($slug, 20),
                    'all_nav_class' => '',
                    'documents_url' => SiteUrl::tag($slug, $page),
                ]),
                $tagCtx
            );

            return $this->render($tplBase, $vars);
        }

        if (!empty($viewMeta['tag_login_required'])) {
            $vars = array_merge($viewMeta, $extra, [
                'list'          => [],
                'breadcrumbs'   => app(BreadcrumbService::class)->forTag($tagRow),
                'tags_nav'      => app(DocumentPublicService::class)->buildTagsNav($slug, 20),
                'all_nav_class' => '',
                'page'          => 1,
                'total'         => 0,
                'limit'         => app(PaginationService::class)->frontListPageSize($tpl),
                'documents_url' => SiteUrl::tag($slug, $page),
            ], app(PaginationService::class)->build(1, 0, app(PaginationService::class)->frontListPageSize($tpl), static fn (int $p): string => SiteUrl::tag($slug, $p)));
            $vars = array_merge($vars, $this->productCatalogPageVars($tagRow, $slug, $page));
            $vars = app(\app\common\service\theme\ThemePageDataContractService::class)->mergeTplVars($tpl, $vars);

            return $this->render($tpl, app(ListPageTemplateContextService::class)->mark($vars));
        }

        if ($this->isProductChannelTpl((string) ($tagRow['tpl_name'] ?? ''))) {
            $vars = array_merge($viewMeta, $extra, [
                'list'          => [],
                'breadcrumbs'   => app(BreadcrumbService::class)->forTag($tagRow),
                'tags_nav'      => app(DocumentPublicService::class)->buildTagsNav($slug, 20),
                'all_nav_class' => '',
                'documents_url' => SiteUrl::tag($slug, $page),
            ]);
            $vars = app(\app\common\service\theme\ThemePageDataContractService::class)->mergeTplVars($tpl, $vars);

            return $this->render($tpl, app(ListPageTemplateContextService::class)->mark($vars));
        }

        // 纯聚合 Tag：仍按 document_tags 筛（非栏目归属）
        $listLimit = app(PaginationService::class)->frontListPageSize($tpl);
        $result = app(DocumentPublicService::class)->listPublic([
            'page'                => $page,
            'limit'               => $listLimit,
            'tags'                => $slug,
            'tag_match'           => 'any',
            'include_descendants' => true,
            'sort'                => 'id_desc',
        ]);
        $list = app(DocumentFormatService::class)->enrichListForView($result['list']);

        $vars = array_merge($viewMeta, $extra, [
            'list'          => $list,
            'breadcrumbs'   => app(BreadcrumbService::class)->forTag($tagRow),
            'tags_nav'      => app(DocumentPublicService::class)->buildTagsNav($slug, 20),
            'all_nav_class' => '',
            'page'          => $page,
            'total'         => $result['total'],
            'limit'         => $result['limit'],
            'documents_url' => SiteUrl::tag($slug, $page),
        ], app(PaginationService::class)->build(
            $page,
            $result['total'],
            $result['limit'],
            static fn (int $p): string => SiteUrl::tag($slug, $p)
        ));
        $vars = app(\app\common\service\theme\ThemePageDataContractService::class)->mergeTplVars($tpl, $vars);

        return $this->render($tpl, app(ListPageTemplateContextService::class)->mark($vars));
    }

    /**
     * 旧书签 ?filter_* → 参数组伪静态路径（门牌 extra.catalog_facet_group；真源 site_nav）
     *
     * @param array<string, mixed> $doorRow
     * @param callable(array<string, mixed>): string $basePathFn
     */
    private function maybeRedirectCatalogFacetQuery(array $doorRow, callable $basePathFn): ?Response
    {
        if (isset($doorRow['_catalog_facet_filters']) && is_array($doorRow['_catalog_facet_filters'])) {
            return null;
        }
        $facet = app(CatalogFacetPathService::class);
        $groupKey = $facet->groupKeyFromExtra($doorRow);
        if ($groupKey === '') {
            return null;
        }
        $filters = [];
        foreach (Request::get() as $k => $v) {
            $key = (string) $k;
            if (!str_starts_with($key, 'filter_')) {
                continue;
            }
            $val = trim((string) $v);
            if ($val !== '') {
                $filters[$key] = $val;
            }
        }
        if ($filters === []) {
            return null;
        }
        $seg = $facet->encode($groupKey, $filters);
        if ($seg === '') {
            return null;
        }
        $basePath = trim((string) $basePathFn($doorRow), '/');
        if ($basePath === '') {
            return null;
        }
        $pretty = $facet->buildPath($basePath, $groupKey, $filters);
        $keep = [];
        foreach (Request::get() as $k => $v) {
            $key = (string) $k;
            if (str_starts_with($key, 'filter_')) {
                continue;
            }
            if ($key === 'page' && (int) $v <= 1) {
                continue;
            }
            $keep[$key] = $v;
        }
        $url = $pretty;
        if ($keep !== []) {
            $q = http_build_query($keep);
            if ($q !== '') {
                $url .= '?' . $q;
            }
        }

        return redirect($url, 302);
    }

    /**
     * @param array<string, mixed> $tagRow
     * @return array<string, mixed>
     */
    private function productCatalogPageVars(array $tagRow, string $slug, int $page): array
    {
        if (!$this->isProductChannelTpl((string) ($tagRow['tpl_name'] ?? ''))) {
            return [];
        }

        return array_merge(
            app(ProductCatalogSidebarService::class)->sidebarVars($slug),
            app(ProductCatalogListPageService::class)->listPageVars($slug, $page)
        );
    }

    private function isProductChannelTpl(string $tplName): bool
    {
        $t = strtolower(trim($tplName));

        return str_contains($t, 'list_item')
            || str_contains($t, 'list_document_product')
            || str_contains($t, 'lists_product')
            || str_contains($t, 'list_page_product');
    }
}
