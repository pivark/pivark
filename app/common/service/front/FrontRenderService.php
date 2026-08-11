<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\front;

use app\common\service\plugin\extension\PluginPortalInvoke;

/** 前台页面 HTML 渲染（供静态生成与 HTTP 共用变量逻辑） */

use function app;

use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\plugin\registry\HubCapabilityRegistry;
use app\common\support\AppTime;

use app\common\service\auth\CaptchaService;
use app\common\service\front\FrontCsrfService;
use app\common\service\infra\BreadcrumbService;
use app\common\service\infra\PaginationService;
use app\common\service\site\SitePageService;
use app\common\service\template\ListPageTemplateContextService;
use app\common\service\template\TemplateEngine;
use app\common\service\theme\ThemePageDataContractService;
use app\common\service\theme\ThemeTemplateCatalogService;
use app\common\service\tag\TagService;
use app\common\service\document\DocumentAttrHelper;
use app\common\service\document\DocumentFormatService;
use app\common\service\document\DocumentPublicService;
use app\common\service\document\satellite\DocumentPluginAvailabilityService;
use app\common\service\document\satellite\DocumentQrService;
use app\common\service\config\ConfigService;
use app\common\service\product\ProductCatalogListPageService;
use app\common\service\product\ProductCatalogSidebarService;
use app\common\service\site\SiteNavService;
use app\common\service\seo\SeoStaticConfigService;
use think\facade\Request;
use app\common\service\item\ItemPublicViewService;
use app\common\service\theme\ThemeService;
use app\common\support\SiteUrl;
use app\common\support\ProjectPaths;

class FrontRenderService
{

    /** Hub：其余依赖仍 lazy app() 解析，避免 boot 链 OOM。 */
    public function __construct()
    {
    }

    /**
     * @return mixed
     */
    public function htmlHome(): string
    {
        $vars = app(ThemePageDataContractService::class)->mergeTplVars('home', [
            'page_title'      => '首页',
            'seo_title'       => '首页',
            'seo_description' => '',
            'seo_keywords'    => '',
        ]);

        return app(TemplateEngine::class)->render('home', $vars);
    }

    /**
     * @param array<string, mixed> $page site_pages 格式化行
     *
     * @return array{template: string, vars: array<string, mixed>}|null
     */
    public function sitePagePayload(array $page): ?array
    {
        $tpl     = app(SitePageService::class)->normalizeTpl((string) ($page['tpl_name'] ?? ''));
        $tplFile = app(SitePageService::class)->resolveThemeTemplateFile($tpl);
        $title   = (string) ($page['title'] ?? '');
        $url     = (string) ($page['url'] ?? '/');
        $vars    = array_merge(app(SitePageService::class)->buildPublicViewVars($page), [
            'breadcrumbs' => app(BreadcrumbService::class)->forPage($title, $url),
            'page_url'    => $url,
            'seo_og_url'  => $url,
        ]);
        if ($tpl === 'list_page_products') {
            if (!\app\common\service\product\ProductCenterGateService::publicSurfaceOpen()) {
                return null;
            }
            $tagQ = trim((string) Request::get('tag', ''));
            $vars = array_merge($vars, app(ProductCatalogSidebarService::class)->sidebarVars($tagQ));
            $vars = array_merge($vars, $this->channelListTopFromRow($page, true));
            $vars = array_merge($vars, app(ProductCatalogListPageService::class)->listPageVars($tagQ));
            $vars = app(ListPageTemplateContextService::class)->mark($vars);
        }
        if (PluginPortalInvoke::portalFileExists('DocsSectionPagesService')) {
            $docsTpl = PluginPortalInvoke::portalConstClass('DocsSectionPagesService', 'TPL');
            if ($docsTpl !== null && $tpl === $docsTpl) {
                $extra = PluginPortalInvoke::portalInvoke('DocsSectionPagesService', 'viewVarsForPage', [$page]);
                if (is_array($extra)) {
                    $vars = array_merge($vars, $extra);
                }
            }
            $hubTpl = PluginPortalInvoke::portalConstClass('DocsSectionPagesService', 'HUB_TPL');
            if ($hubTpl !== null && $tpl === $hubTpl) {
                $extra = PluginPortalInvoke::portalInvoke('DocsSectionPagesService', 'viewVarsForDocsHub', []);
                if (is_array($extra)) {
                    $vars = array_merge($vars, $extra);
                }
            }
        }
        if ($tpl === 'list_page_faq_post') {
            $faqUrl = \app\common\support\SiteUrl::pageByTpl('faq');
            $postUrl = '/faq/ask';
            $hubId = app(HubCapabilityRegistry::class)->resolve(HubCapabilityRegistry::SLOT_COMMUNITY_HUB);
            $threadApi = is_string($hubId) && $hubId !== ''
                ? '/api/v1/plugins/' . rawurlencode($hubId) . '/thread'
                : '';
            $vars = array_merge($vars, [
                'front_csrf_token'   => app(FrontCsrfService::class)->token(),
                'front_csrf_field'   => app(FrontCsrfService::class)->fieldName(),
                'member_login_url'   => $vars['member_login_url'] ?? '/member/login',
                'url_faq'            => $faqUrl,
                'url_faq_post'       => $postUrl,
                'url_faq_post_login' => \app\common\support\SiteUrl::memberLogin($postUrl),
                'url_community_thread_api' => $threadApi,
            ]);
        }
        if ($tpl === 'list_page_faq') {
            $vars = array_merge($vars, $this->askHubFrontVars());
        }
        if ($tpl === 'list_page_careers') {
            $vars = array_merge($vars, $this->talentJobsFrontVars());
        }
        $vars = app(ThemePageDataContractService::class)->mergeTplVars($tpl, $vars);
        if ($tpl === 'list_page') {
            $desc = trim((string) ($vars['seo_description'] ?? ''));
            $vars = array_merge($vars, [
                'page_head_desc' => $desc,
            ]);
        }

        return ['template' => $tplFile, 'vars' => $vars];
    }

    /**
     * @param array<string, mixed> $page site_pages 格式化行
     * @return mixed
     */
    public function htmlSitePage(array $page): string
    {
        $payload = $this->sitePagePayload($page);
        if ($payload === null) {
            return '';
        }

        return app(TemplateEngine::class)->render($payload['template'], $payload['vars']);
    }

    private function isProductChannelTpl(string $tplName): bool
    {
        $tplName = strtolower(trim($tplName));

        return str_contains($tplName, 'list_item')
            || str_contains($tplName, 'list_document_product')
            || str_contains($tplName, 'lists_product')
            || str_contains($tplName, 'list_page_product');
    }

    /**
     * 真分类列表页（门牌归 site_nav；同 path 渲染，不做 301）。
     *
     * @param array<string, mixed> $navRow
     * @return array{template:string,vars:array<string,mixed>}|null
     */
    public function categoryListPayload(array $navRow, int $page): ?array
    {
        $navSvc = app(SiteNavService::class);
        $navId  = (int) ($navRow['id'] ?? 0);
        if ($navId < 1 || !$navSvc->isContentCategoryId($navId)) {
            return null;
        }
        $kind = strtolower(trim((string) ($navRow['content_kind'] ?? '')));
        if ($kind === SiteNavService::KIND_PRODUCT
            && !\app\common\service\product\ProductCenterGateService::publicSurfaceOpen()) {
            return null;
        }
        $page    = max(1, $page);
        $title   = trim((string) ($navRow['title'] ?? ''));
        $path    = $navSvc->publicPathForContentCategory($navRow);
        if ($path === '') {
            return null;
        }
        // 列表模板/TDK/封面真源 = site_nav；分类与 Tag 脱钩
        $slug = $path;
        $rawTpl = trim((string) ($navRow['tpl_name'] ?? ''));
        if ($rawTpl === '') {
            $rawTpl = $kind === SiteNavService::KIND_PRODUCT
                ? 'list_document_product.php'
                : 'list_document.php';
        }
        $catalog  = app(ThemeTemplateCatalogService::class);
        $tpl      = $catalog->resolveTagListTpl($rawTpl);
        $viewMeta = $navSvc->buildPublicCategoryListViewVars($navRow);
        if ($title !== '') {
            $viewMeta['page_title'] = $title;
            if (trim((string) ($viewMeta['seo_title'] ?? '')) === '') {
                $viewMeta['seo_title'] = $title;
            }
        }
        $extra = $this->channelListTopFromRow($navRow, true);
        $listUrlFn = function (int $p) use ($path): string {
            $url = $p > 1
                ? app(FrontUrlRuleService::class)->buildTagListPage($path, $p)
                : app(FrontUrlRuleService::class)->buildChannelHome($path, 1);

            return app(SeoStaticConfigService::class)->applyStaticStoragePrefix($url);
        };
        $documentsUrl = $listUrlFn($page);
        $crumbs = app(BreadcrumbService::class)->forPage(
            $title !== '' ? $title : $path,
            $navSvc->resolveUrl($navRow)
        );

        if ($catalog->isTagProductShowcaseTpl($rawTpl) || $catalog->isTagProductShowcaseTpl($tpl . '.php')) {
            $tplBase = $catalog->toCanonicalBasename($tpl !== '' ? $tpl : $rawTpl);
            $tagCtx  = [
                'tag_id'   => 0,
                'tag_slug' => strtolower($slug),
                'nav_id'   => $navId,
            ];
            $facetFilters = $navRow['_catalog_facet_filters'] ?? null;
            if (is_array($facetFilters) && $facetFilters !== []) {
                $tagCtx['filters'] = $facetFilters;
            }
            $vars = app(ThemePageDataContractService::class)->mergeTplVars(
                $tplBase,
                array_merge($viewMeta, $extra, [
                    'breadcrumbs'    => $crumbs,
                    'tags_nav'       => [],
                    'all_nav_class'  => '',
                    'documents_url'  => $documentsUrl,
                    'channel_nav_id' => $navId,
                ]),
                $tagCtx
            );

            return ['template' => $tplBase, 'vars' => $vars];
        }

        if ($this->isProductChannelTpl($rawTpl)) {
            $extra = array_merge(
                $extra,
                app(ProductCatalogSidebarService::class)->sidebarVars($slug),
                app(ProductCatalogListPageService::class)->listPageVars($slug, $page, $navId)
            );
            $vars = app(ThemePageDataContractService::class)->mergeTplVars(
                $tpl,
                app(ListPageTemplateContextService::class)->mark(array_merge($viewMeta, $extra, [
                    'list'           => [],
                    'breadcrumbs'    => $crumbs,
                    'tags_nav'       => [],
                    'all_nav_class'  => '',
                    'documents_url'  => $documentsUrl,
                    'channel_nav_id' => $navId,
                ]))
            );

            return ['template' => $tpl, 'vars' => $vars];
        }

        $listLimit = app(PaginationService::class)->frontListPageSize($tpl);
        $listSort  = $slug === 'fangan' ? 'id_asc' : 'id_desc';
        $result    = app(DocumentPublicService::class)->listPublic([
            'page'  => $page,
            'limit' => $listLimit,
            'nav_id'=> $navId,
            'sort'  => $listSort,
        ]);
        $list = app(DocumentFormatService::class)->enrichListForView($result['list']);
        $vars = app(ThemePageDataContractService::class)->mergeTplVars(
            $tpl,
            app(ListPageTemplateContextService::class)->mark(array_merge($viewMeta, $extra, [
                'list'           => $list,
                'breadcrumbs'    => $crumbs,
                'tags_nav'       => [],
                'all_nav_class'  => '',
                'page'           => $page,
                'total'          => $result['total'],
                'limit'          => $result['limit'],
                'channel_nav_id' => $navId,
                'documents_url'  => $documentsUrl,
            ], app(PaginationService::class)->build(
                $page,
                $result['total'],
                $result['limit'],
                $listUrlFn
            )))
        );

        return ['template' => $tpl, 'vars' => $vars];
    }

    /**
     * @param array<string, mixed> $navRow
     */
    public function htmlContentCategory(array $navRow, int $page): string
    {
        $payload = $this->categoryListPayload($navRow, $page);
        if ($payload === null) {
            return '';
        }

        return app(TemplateEngine::class)->render($payload['template'], $payload['vars']);
    }

    /**
     * 纯聚合 Tag 列表（非栏目门牌）。栏目门牌只走 site_nav / htmlContentCategory。
     *
     * @param array<string, mixed> $tagRow
     * @return mixed
     */
    public function htmlTag(array $tagRow, int $page): string
    {
        $slug  = (string) ($tagRow['slug'] ?? '');
        $page  = max(1, $page);

        $viewMeta = app(TagService::class)->buildPublicListViewVars($tagRow);
        $rawTpl   = (string) ($tagRow['tpl_name'] ?? '');
        $catalog  = app(ThemeTemplateCatalogService::class);
        $tpl      = $catalog->resolveTagListTpl($rawTpl);
        $extra    = $this->channelListTopFromRow($tagRow, true);

        if ($catalog->isTagProductShowcaseTpl($rawTpl) || $catalog->isTagProductShowcaseTpl($tpl . '.php')) {
            $tplBase = $catalog->toCanonicalBasename($tpl !== '' ? $tpl : $rawTpl);
            $vars    = app(ThemePageDataContractService::class)->mergeTplVars($tplBase, array_merge($viewMeta, $extra, [
                'breadcrumbs'   => app(BreadcrumbService::class)->forTag($tagRow),
                'tags_nav'      => app(DocumentPublicService::class)->buildTagsNav($slug, 20),
                'all_nav_class' => '',
                'documents_url' => SiteUrl::tag($slug, $page),
            ]));

            return app(TemplateEngine::class)->render($tplBase, $vars);
        }

        if ($this->isProductChannelTpl($rawTpl)) {
            $extra = array_merge(
                $extra,
                app(ProductCatalogSidebarService::class)->sidebarVars($slug),
                app(ProductCatalogListPageService::class)->listPageVars($slug, $page)
            );

            return app(TemplateEngine::class)->render($tpl, app(ListPageTemplateContextService::class)->mark(array_merge($viewMeta, $extra, [
                'list'          => [],
                'breadcrumbs'   => app(BreadcrumbService::class)->forTag($tagRow),
                'tags_nav'      => app(DocumentPublicService::class)->buildTagsNav($slug, 20),
                'all_nav_class' => '',
                'documents_url' => SiteUrl::tag($slug, $page),
            ])));
        }

        $result = app(DocumentPublicService::class)->listPublic([
            'page'                => $page,
            'limit'               => app(PaginationService::class)->frontListPageSize($tpl),
            'tags'                => $slug,
            'tag_match'           => 'any',
            'include_descendants' => true,
            'sort'                => 'id_desc',
        ]);
        $list = app(DocumentFormatService::class)->enrichListForView($result['list']);

        return app(TemplateEngine::class)->render($tpl, app(ListPageTemplateContextService::class)->mark(array_merge($viewMeta, $extra, [
            'list'          => $list,
            'breadcrumbs'   => app(BreadcrumbService::class)->forTag($tagRow),
            'tags_nav'      => app(DocumentPublicService::class)->buildTagsNav($slug, 20),
            'all_nav_class' => '',
            'page'          => $page,
            'total'         => $result['total'],
            'limit'         => $result['limit'],
            'channel_nav_id'=> 0,
            'documents_url' => SiteUrl::tag($slug, $page),
        ], app(PaginationService::class)->build(
            $page,
            $result['total'],
            $result['limit'],
            static fn (int $p): string => SiteUrl::tag($slug, $p)
        ))));
    }

    /**
     * @return mixed
     * @param mixed $id
     */
    public function htmlDocument(int $id): ?string
    {
        if ($id < 1) {
            return null;
        }

        $detail = app(DocumentPublicService::class)->getPublicViewData($id, false, false, true);
        if ($detail === null) {
            return null;
        }

        if (app(DocumentAttrHelper::class)->resolveExternalRedirectUrl($detail) !== null) {
            return null;
        }

        $adjacent = app(DocumentPublicService::class)->getAdjacentPublic($id);
        $prev     = $adjacent['prev'];
        $next     = $adjacent['next'];
        if ($prev) {
            $prev['url'] = SiteUrl::documentFromRow($prev);
        }
        if ($next) {
            $next['url'] = SiteUrl::documentFromRow($next);
        }

        $related      = app(DocumentPublicService::class)->getRelatedPublic($id, 5);
        $primaryTag   = app(TagService::class)->primaryTagFromDocument($detail);
        $primaryListTpl = '';
        if (is_array($primaryTag)) {
            $slug = trim((string) ($primaryTag['slug'] ?? ''));
            if ($slug !== '') {
                $tagRow = app(TagService::class)->findRowBySlug($slug);
                $primaryListTpl = (string) (($tagRow ?? $primaryTag)['tpl_name'] ?? '');
            } else {
                $primaryListTpl = (string) ($primaryTag['tpl_name'] ?? '');
            }
        }
        $tpl          = app(ThemeTemplateCatalogService::class)->resolveDocumentViewTplForDetail($detail, $primaryListTpl);
        $publishedRaw = $detail['published_at'] ?? $detail['created_at'] ?? '';
        $docShareUrl  = app(DocumentQrService::class)->publicDocumentUrl($id);
        $docOutUrl    = SiteUrl::documentFromRow($detail);

        return app(TemplateEngine::class)->render($tpl, array_merge([
            'document_id'             => $id,
            'breadcrumbs'            => app(BreadcrumbService::class)->forDocument($detail),
            'document_title'          => $detail['title'],
            'document_subtitle'       => $detail['subtitle'] ?? '',
            'document_author'         => $detail['author_name'] ?? '',
            'document_source'         => $detail['source'] ?? '',
            'document_content'        => $detail['content'] ?? '',
            'document_summary'        => $detail['summary'] ?? '',
            'document_litpic'         => $detail['litpic'] ?? '',
            'document_qr_url'         => app(DocumentQrService::class)->publicCacheUrl($id),
            'document_share_url'      => $docShareUrl !== '' ? $docShareUrl : $docOutUrl,
            'document_url'            => $docOutUrl,
            'page_url'                => $docOutUrl,
            'seo_og_url'              => $docOutUrl,
            'document_click'          => (int) ($detail['click'] ?? 0),
            'document_date'           => $publishedRaw !== ''
                ? AppTime::format('Y-m-d', strtotime((string) $publishedRaw)) : '',
            'document_tags'           => $detail['tags'] ?? [],
            'document_login_required' => !empty($detail['login_required']),
            'document_read_level_name' => (string) ($detail['read_level_name'] ?? ''),
            'seo_title'              => ($detail['seo_title'] ?? '') ?: $detail['title'],
            'seo_title_context'      => 'document',
            'seo_keywords'           => $detail['seo_keywords'] ?? '',
            'seo_description'        => $detail['seo_description'] ?? '',
            'prev_document'           => $prev,
            'next_document'           => $next,
            'related_documents'       => $related,
        ], app(DocumentPluginAvailabilityService::class)->templateVars(
            $id,
            (string) ($detail['content'] ?? ''),
            (string) ($detail['summary'] ?? '')
        ), $this->documentChannelHeaderVars($detail)));
    }

    /**
     * @return mixed
     * @param mixed $page
     * @param mixed $tagSlug
     */
    public function htmlDocumentList(int $page = 1, string $tagSlug = ''): string
    {
        $page    = max(1, $page);
        $tagSlug = trim($tagSlug);
        $tpl     = app(ThemeTemplateCatalogService::class)->defaultListDocumentTpl();
        $params  = ['page' => $page, 'limit' => app(PaginationService::class)->frontListPageSize($tpl), 'sort' => 'id_desc'];
        if ($tagSlug !== '') {
            // 聚合筛选；栏目列表须走 nav_id / 栏目 path，禁 Tag→栏目桥
            $params['tags'] = $tagSlug;
        }
        $result = app(DocumentPublicService::class)->listPublic($params);
        $list   = app(DocumentFormatService::class)->enrichListForView($result['list']);
        $tagRow = null;
        $viewMeta = [
            'tag_slug'        => $tagSlug,
            'tag_name'        => '',
            'tag_description' => '',
            'tag_litpic'      => '',
            'tag_kind'        => TagService::KIND_LABEL,
            'page_title'      => '全部文档',
            'seo_title'       => '全部文档',
            'seo_keywords'    => '',
            'seo_description' => '',
        ];
        if ($tagSlug !== '') {
            $tagRow = app(TagService::class)->findRowBySlug($tagSlug);
            if (is_array($tagRow)) {
                $viewMeta = app(TagService::class)->buildPublicListViewVars($tagRow);
            } else {
                $viewMeta['tag_name']   = $tagSlug;
                $viewMeta['page_title'] = '标签：' . $tagSlug;
                $viewMeta['seo_title']  = $viewMeta['page_title'];
            }
        } else {
            $siteCfg = app(ConfigService::class)->getAll();
            $viewMeta['seo_keywords']    = (string) ($siteCfg['site_keywords'] ?? '');
            $viewMeta['seo_description'] = (string) ($siteCfg['site_description'] ?? '');
        }

        $channelTop = is_array($tagRow) ? $this->channelListTopFromRow($tagRow, true) : $this->channelListTopPageVars();

        return app(TemplateEngine::class)->render($tpl, app(ListPageTemplateContextService::class)->mark(array_merge($viewMeta, $channelTop, [
            'seo_title_context' => $tagSlug !== '' ? 'tag' : 'generic',
            'list'          => $list,
            'breadcrumbs'   => app(BreadcrumbService::class)->forDocumentList(
                (string) $viewMeta['page_title'],
                $tagSlug !== '' ? SiteUrl::tag($tagSlug) : SiteUrl::documents()
            ),
            'tags_nav'      => app(DocumentPublicService::class)->buildTagsNav($tagSlug, 20),
            'all_nav_class' => $tagSlug === '' ? 'active' : '',
            'page'          => $page,
            'total'         => $result['total'],
            'limit'         => $result['limit'],
            'documents_url'  => SiteUrl::documents($page, $tagSlug),
        ], app(PaginationService::class)->build(
            $page,
            $result['total'],
            $result['limit'],
            static fn (int $p): string => $tagSlug !== ''
                ? SiteUrl::tag($tagSlug, $p)
                : SiteUrl::documents($p)
        ))));
    }

    /**
     * 频道列表顶图变量（无标签行时仅返回「无顶图」占位，走文字 Hero）
     *
     * @param array<string, mixed> $tagRow
     * @return array{channel_list_top_empty:string,channel_list_top_image?:string,channel_list_top_subtitle?:string}
     */
    public function channelListTopPageVars(array $tagRow = []): array
    {
        return $this->channelListTopFromRow($tagRow, true);
    }

    /**
     * @param array<string, mixed> $row tags 表行、site_pages 行或已格式化的 tag_* 视图变量
     * @return array{channel_list_top_empty:string,channel_banner_image:string,channel_list_top_image?:string,channel_list_top_subtitle?:string}
     */
    /**
     * 文档 / 品项详情页：与对应标签列表页一致的频道头图变量（page_title 为频道名，非文章标题）
     *
     * @param array<string, mixed> $detail 公开文档详情行
     * @return array<string, mixed>
     */
    public function documentChannelHeaderVars(array $detail): array
    {
        return $this->channelHeaderFromTagRow(app(TagService::class)->primaryTagFromDocument($detail));
    }

    /**
     * @param array<string, mixed>|null $tagSnippet 文档 tags 项或完整 tags 表行
     * @return array<string, mixed>
     */
    public function channelHeaderFromTagRow(?array $tagSnippet): array
    {
        if ($tagSnippet !== null) {
            $slug = trim((string) ($tagSnippet['slug'] ?? ''));
            if ($slug !== '') {
                $tagRow = app(TagService::class)->findRowBySlug($slug);
                if (is_array($tagRow)) {
                    return array_merge(
                        app(TagService::class)->buildPublicListViewVars($tagRow),
                        $this->channelListTopFromRow($tagRow, true)
                    );
                }
            }
        }

        return array_merge(
            [
                'page_title'        => '资讯',
                'tag_description'   => '',
                'seo_description'   => '',
            ],
            $this->channelListTopPageVars()
        );
    }

    public function channelListTopFromRow(array $row, bool $themeFallback = false): array
    {
        $image = trim((string) ($row['list_top_image'] ?? $row['litpic'] ?? $row['tag_litpic'] ?? ''));
        if ($image === '' && $themeFallback) {
            $image = $this->themeDefaultChannelBannerImage();
        }
        if ($image === '') {
            return [
                'channel_list_top_empty' => '',
                'channel_banner_image'   => '',
            ];
        }

        $subtitle = trim((string) ($row['list_top_subtitle'] ?? $row['description'] ?? $row['tag_description'] ?? ''));

        return [
            'channel_list_top_empty'    => '1',
            'channel_banner_image'      => $image,
            'channel_list_top_image'    => $image,
            'channel_list_top_subtitle' => $subtitle,
        ];
    }

    public function themeDefaultChannelBannerImage(): string
    {
        $theme = app(ThemeService::class)->getCurrentTheme();

        return app(ThemeService::class)->themeAssetUrlPrefix($theme) . '/images/banner/list-top.jpg';
    }

    /**
     * @return array{template: string, vars: array<string, mixed>}|null
     */
    public function productItemPayload(string $slug): ?array
    {
        $vars = app(ItemPublicViewService::class)->buildItemPageVars($slug);
        if ($vars === null) {
            return null;
        }

        $fallback = 'view_item';
        $tagView  = trim((string) ($vars['item_primary_tag_view_tpl'] ?? ''));
        $tpl      = $tagView !== ''
            ? app(ThemeTemplateCatalogService::class)->resolveContentViewTpl($tagView, $fallback)
            : $fallback;

        return ['template' => $tpl, 'vars' => $vars];
    }

    /**
     * 品项独立页 canonical：`/items/{slug}`
     *
     * @return string|null
     */
    public function htmlProductItem(string $slug): ?string
    {
        $payload = $this->productItemPayload($slug);
        if ($payload === null) {
            return null;
        }

        return app(TemplateEngine::class)->render($payload['template'], $payload['vars']);
    }

    /**
     * @return mixed
     * @param mixed $page
     */
    public function htmlTagsCloud(int $page = 1): string
    {
        $page   = max(1, $page);
        $result = app(TagService::class)->listPublic($page, 30);
        $tags   = $result['list'];
        $counts = app(TagService::class)->countPublishedDocumentsByTagIds(array_column($tags, 'id'));
        foreach ($tags as &$tag) {
            $tag['document_count'] = $counts[(int) $tag['id']] ?? 0;
        }
        unset($tag);
        $siteCfg = app(ConfigService::class)->getAll();

        return app(TemplateEngine::class)->render(app(ThemeTemplateCatalogService::class)->systemTagsIndexTpl(), app(ListPageTemplateContextService::class)->markTagCatalog(array_merge([
            'tags'            => $tags,
            'breadcrumbs'     => app(BreadcrumbService::class)->forTagsCloud(),
            'page'            => $page,
            'total'           => $result['total'],
            'limit'           => $result['limit'],
            'page_title'      => '标签云',
            'seo_title'       => '标签云',
            'seo_keywords'    => (string) ($siteCfg['site_keywords'] ?? ''),
            'seo_description' => (string) ($siteCfg['site_description'] ?? ''),
            'tag_description' => '浏览全部内容维度与聚合入口',
        ], $this->channelListTopPageVars(), app(PaginationService::class)->build(
            $page,
            $result['total'],
            $result['limit'],
            static fn (int $p): string => SiteUrl::tags($p)
        ))));
    }

    /**
     * Community / demo FAQ 页：经 ASK_HUB 能力拉独立问答（document_id=0 为主）。
     *
     * @return array<string, mixed>
     */
    private function askHubFrontVars(): array
    {
        $askId = app(HubCapabilityRegistry::class)->resolve(HubCapabilityRegistry::SLOT_ASK_HUB);
        if (!is_string($askId) || $askId === '') {
            return [
                'ask_hub_live'  => 0,
                'ask_hub_items' => [],
                'ask_hub_empty' => 1,
                'ask_hub_total' => 0,
            ];
        }
        $page = max(1, (int) Request::get('page', 1));
        $result = DocumentAddonBridgeAccess::invokeOr(
            ['list' => [], 'total' => 0],
            $askId,
            'listHubPublic',
            [['sort' => 'latest', 'page' => $page, 'limit' => 30]]
        );
        if (!is_array($result)) {
            $result = ['list' => [], 'total' => 0];
        }
        $list = is_array($result['list'] ?? null) ? $result['list'] : [];
        $total = (int) ($result['total'] ?? 0);

        return [
            'ask_hub_live'  => 1,
            'ask_hub_items' => $list,
            'ask_hub_empty' => $list === [] ? 1 : 0,
            'ask_hub_total' => $total,
            'ask_user_ask_open' => !empty($result['user_ask_open']) ? 1 : 0,
            'page'          => $page,
        ];
    }

    /**
     * 招贤页：经 doc_talent bridge 拉独立岗位（document_id=0）。
     *
     * @return array<string, mixed>
     */
    private function talentJobsFrontVars(): array
    {
        $talentId = app(HubCapabilityRegistry::class)->resolve(HubCapabilityRegistry::SLOT_TALENT_HUB);
        if (!is_string($talentId) || $talentId === '') {
            return [
                'talent_jobs_live'             => 0,
                'talent_jobs_items'            => [],
                'talent_jobs_empty'            => 1,
                'talent_jobs_total'            => 0,
                'talent_jobs_filters'          => [],
                'talent_filter_keyword'        => '',
                'talent_filter_location'       => '',
                'talent_filter_employment'     => '',
                'talent_location_options'      => [],
                'talent_employment_options'    => [],
                'page'                        => 1,
            ];
        }
        $page = max(1, (int) Request::get('page', 1));
        $opts = [
            'page'            => $page,
            'limit'           => 30,
            'keyword'         => trim((string) Request::get('q', Request::get('keyword', ''))),
            'location'        => trim((string) Request::get('location', '')),
            'employment_type' => trim((string) Request::get('employment_type', '')),
        ];
        $result = DocumentAddonBridgeAccess::invokeOr(
            ['list' => [], 'total' => 0, 'filters' => []],
            $talentId,
            'listJobsPublic',
            [$opts]
        );
        if (!is_array($result)) {
            $result = ['list' => [], 'total' => 0, 'filters' => []];
        }
        $list = is_array($result['list'] ?? null) ? $result['list'] : [];
        $total = (int) ($result['total'] ?? 0);
        $filters = is_array($result['filters'] ?? null) ? $result['filters'] : [];
        $live = DocumentAddonBridgeAccess::isEnabled($talentId) ? 1 : 0;

        return [
            'talent_jobs_live'             => $live,
            'talent_jobs_items'            => $list,
            'talent_jobs_empty'            => $list === [] ? 1 : 0,
            'talent_jobs_total'            => $total,
            'talent_jobs_filters'          => $filters,
            'talent_filter_keyword'        => (string) ($filters['keyword'] ?? $opts['keyword']),
            'talent_filter_location'       => (string) ($filters['location'] ?? $opts['location']),
            'talent_filter_employment'     => (string) ($filters['employment_type'] ?? $opts['employment_type']),
            'talent_location_options'      => is_array($filters['location_options'] ?? null) ? $filters['location_options'] : [],
            'talent_employment_options'    => is_array($filters['employment_options'] ?? null) ? $filters['employment_options'] : [],
            'page'                        => $page,
        ];
    }
}
