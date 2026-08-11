<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\home\controller;

use app\common\service\infra\BreadcrumbService;
use app\common\service\config\ConfigService;
use app\common\service\hook\HookService;
use app\common\service\product\ProductSmartSearchService;
use app\common\service\template\ListPageTemplateContextService;
use app\common\service\infra\PaginationService;
use app\common\service\search\SearchPublicGateway;
use app\common\service\search\SmartSearchAnswerFormatter;
use app\common\service\theme\ThemeTemplateCatalogService;
use app\common\support\SiteUrl;
use think\facade\Request;

class Search extends Base
{
    public function index()
    {
        $search = app(SearchPublicGateway::class);
        $keyword = $search->prepareKeyword((string) Request::get('q', ''));
        $blocked = $search->guardKeyword($keyword);
        if ($blocked !== null) {
            $hintCtx = $search->buildPageContext('', true);

            return $this->render(app(ThemeTemplateCatalogService::class)->systemSearchTpl(), app(ListPageTemplateContextService::class)->mark([
                'search_keyword'  => $keyword,
                'list'            => [],
                'tags'            => [],
                'pages'           => [],
                'page'            => 1,
                'total'           => 0,
                'limit'           => 12,
                'page_title'      => '搜索受限',
                'seo_title'       => '搜索受限',
                'seo_keywords'    => '',
                'seo_description' => $blocked->message(),
                'breadcrumbs'     => app(BreadcrumbService::class)->forSearch($keyword),
                'smart_search_on' => 0,
                'smart_answer'    => $blocked->message(),
                'smart_answer_html' => app(SmartSearchAnswerFormatter::class)->toHtml($blocked->message()),
                'smart_mode'      => '',
                'smart_sources'   => [],
                'search_error'    => $blocked->message(),
                'search_examples'    => is_array($hintCtx['search_examples'] ?? null) ? $hintCtx['search_examples'] : [],
                'search_suggestions' => is_array($hintCtx['search_suggestions'] ?? null) ? $hintCtx['search_suggestions'] : [],
            ]));
        }
        $page = max(1, (int) Request::get('page', 1));
        $pp   = max(1, (int) Request::get('pp', 1));
        $smartCtx = $search->buildPageContext($keyword, true, $pp);
        $productParsed = is_array($smartCtx['product_parsed'] ?? null) ? $smartCtx['product_parsed'] : [];
        $conditionalProductUi = $keyword !== ''
            && class_exists(ProductSmartSearchService::class)
            && ProductSmartSearchService::isAvailable()
            && ProductSmartSearchService::parsedHasConditions($productParsed);

        // 无参数全量 / 有参数精准：文档侧均正常召回，不做主文档排除去重
        $result   = $search->searchPublicDocuments($keyword, $page, 12);
        $articles = $result['documents'];

        $filterChips = [];
        $catalogUrl  = '';
        $smart       = [
            'answer'  => '',
            'enabled' => 0,
            'mode'    => '',
            'sources' => [],
        ];
        if ($keyword !== '' && $conditionalProductUi) {
            $hookPayload = [
                'keyword' => $keyword,
                'smart'   => array_merge($smart, [
                    'answer'           => (string) ($smartCtx['smart_answer'] ?? ''),
                    'enabled'          => (int) ($smartCtx['smart_search_on'] ?? 0),
                    'mode'             => (string) ($smartCtx['smart_mode'] ?? ''),
                    'sources'          => is_array($smartCtx['smart_sources'] ?? null) ? $smartCtx['smart_sources'] : [],
                    'sources_grouped'  => is_array($smartCtx['smart_sources_grouped'] ?? null) ? $smartCtx['smart_sources_grouped'] : [],
                    'parsed'           => is_array($smartCtx['smart_parsed'] ?? null) ? $smartCtx['smart_parsed'] : [],
                    'filter_chips'     => is_array($smartCtx['smart_filter_chips'] ?? null) ? $smartCtx['smart_filter_chips'] : [],
                    'catalog_url'      => (string) ($smartCtx['smart_catalog_url'] ?? ''),
                    'fallback'         => (int) ($smartCtx['smart_fallback'] ?? 0),
                    'chunk_citations'  => is_array($smartCtx['smart_chunk_citations'] ?? null) ? $smartCtx['smart_chunk_citations'] : [],
                ]),
            ];
            $hooks = app(HookService::class);
            $hookPayload = $hooks->fire('search.enhance.ai', $hookPayload);
            $hookPayload = $hooks->fire('search.enhance.product', $hookPayload);
            $smart       = $hookPayload['smart'];
        }

        $filterChips = is_array($smart['filter_chips'] ?? null) ? $smart['filter_chips'] : [];
        if ($filterChips === [] && $conditionalProductUi && is_array($smartCtx['product_filter_chips'] ?? null)) {
            $filterChips = $smartCtx['product_filter_chips'];
        }
        $catalogUrl = (string) ($smart['catalog_url'] ?? '');
        if ($catalogUrl === '' && $conditionalProductUi) {
            $catalogUrl = (string) ($smartCtx['product_catalog_url'] ?? '');
        }

        $productList  = is_array($smartCtx['product_list'] ?? null) ? $smartCtx['product_list'] : [];
        $productTotal = (int) ($smartCtx['product_total'] ?? 0);
        $productPage  = max(1, (int) ($smartCtx['product_page'] ?? $pp));
        $productLimit = max(1, (int) ($smartCtx['product_limit'] ?? 12));
        $productSearchOn = $productTotal > 0 ? 1 : 0;

        if ($keyword !== '') {
            $search->recordPageOutcome(
                $keyword,
                (int) $articles['total'],
                $productTotal,
                is_array($smart) ? $smart : [],
            );
        }

        $pageTitle = $keyword !== '' ? '搜索：' . $keyword : '站内搜索';
        $siteCfg   = app(ConfigService::class)->getAll();
        $searchNoResults = $keyword !== '' && (int) $articles['total'] === 0 && $productSearchOn !== 1;

        $docPagination = app(PaginationService::class)->build(
            $articles['page'],
            $articles['total'],
            $articles['limit'],
            static fn (int $p): string => SiteUrl::search($keyword, $p, $productPage)
        );
        $productPagination = app(PaginationService::class)->build(
            $productPage,
            $productTotal,
            $productLimit,
            static fn (int $p): string => SiteUrl::search($keyword, $page, $p)
        );
        $productPaginationVars = [];
        foreach ($productPagination as $k => $v) {
            $productPaginationVars['product_' . $k] = $v;
        }

        return $this->render(app(ThemeTemplateCatalogService::class)->systemSearchTpl(), app(ListPageTemplateContextService::class)->mark(array_merge([
            'search_keyword'        => $keyword,
            'search_no_results'     => $searchNoResults ? 1 : 0,
            'list'                  => $articles['list'],
            'tags'                  => $result['tags'],
            'pages'                 => $result['pages'],
            'page'                  => $articles['page'],
            'total'                 => $articles['total'],
            'limit'                 => $articles['limit'],
            'page_title'            => $pageTitle,
            'seo_title'             => $pageTitle,
            'seo_keywords'          => (string) ($siteCfg['site_keywords'] ?? ''),
            'seo_description'       => $keyword !== ''
                ? '搜索「' . $keyword . '」的相关内容'
                : (string) ($siteCfg['site_description'] ?? ''),
            'breadcrumbs'           => app(BreadcrumbService::class)->forSearch($keyword),
            'smart_search_on'       => $conditionalProductUi ? (int) ($smart['enabled'] ?? 0) : 0,
            'smart_answer'          => $conditionalProductUi ? (string) ($smart['answer'] ?? '') : '',
            'smart_answer_html'     => $conditionalProductUi
                ? ((string) ($smartCtx['smart_answer_html'] ?? '') !== ''
                    ? (string) $smartCtx['smart_answer_html']
                    : app(SmartSearchAnswerFormatter::class)->toHtml((string) ($smart['answer'] ?? '')))
                : '',
            'smart_mode'            => $conditionalProductUi ? (string) ($smart['mode'] ?? '') : '',
            'smart_sources'         => $conditionalProductUi && is_array($smart['sources'] ?? null) ? $smart['sources'] : [],
            'smart_sources_grouped' => $conditionalProductUi && is_array($smart['sources_grouped'] ?? null) ? $smart['sources_grouped'] : [],
            'smart_parsed'          => $conditionalProductUi && is_array($smart['parsed'] ?? null) ? $smart['parsed'] : [],
            'smart_filter_chips'    => $conditionalProductUi ? $filterChips : [],
            'smart_catalog_url'     => $conditionalProductUi ? $catalogUrl : '',
            'smart_fallback'        => $conditionalProductUi ? (int) ($smart['fallback'] ?? 0) : 0,
            'smart_chunk_citations' => $conditionalProductUi && is_array($smart['chunk_citations'] ?? null) ? $smart['chunk_citations'] : [],
            'search_examples'       => is_array($smartCtx['search_examples'] ?? null) ? $smartCtx['search_examples'] : [],
            'search_suggestions'    => is_array($smartCtx['search_suggestions'] ?? null) ? $smartCtx['search_suggestions'] : [],
            'product_list'          => $productList,
            'product_total'         => $productTotal,
            'product_page'          => $productPage,
            'product_limit'         => $productLimit,
            'product_search_on'     => $productSearchOn,
            'product_filter_chips'  => $conditionalProductUi && is_array($smartCtx['product_filter_chips'] ?? null) ? $smartCtx['product_filter_chips'] : [],
            'product_catalog_url'   => $conditionalProductUi ? (string) ($smartCtx['product_catalog_url'] ?? '') : '',
            'search_session_bar'    => $conditionalProductUi && is_array($smartCtx['search_session_bar'] ?? null)
                ? $smartCtx['search_session_bar']
                : ['show' => 0],
            'search_append_hint'    => $conditionalProductUi ? (string) ($smartCtx['search_append_hint'] ?? '') : '',
        ], $docPagination, $productPaginationVars)));
    }
}
