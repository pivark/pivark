<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\support\AppTime;

use app\common\service\site\SiteModeService;
use app\common\service\tag\TagService;
use app\common\service\document\DocumentAttrHelper;
use app\common\service\document\DocumentPublicService;
use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\config\ConfigService;
use app\common\service\site\SiteSlideService;
use app\common\service\theme\ThemeService;
use app\common\support\SiteDomainContext;
use app\common\support\SiteUrl;

/**
 * 前台 {pv:*} 标签模板引擎（纯标签解析，不使用 eval）
 */

/** 内置标签块渲染（列表、面包屑等） */
class TemplateBlockRenderer
{

public function renderInclude(array $m, array $pageVars): string
    {
        $attrs = app(TemplateTagParser::class)->parseAttrs($m[1]);
        $file  = trim((string) ($attrs['file'] ?? ''));
        if ($file === '' || str_contains($file, '..')) {
            return '';
        }
        $theme = TemplateEngineState::$activeTheme ?? app(\app\common\service\theme\ThemeService::class)->getCurrentTheme();
        if (TemplateEngineState::$memberTemplateRender) {
            $path = app(\app\common\service\theme\ThemeService::class)->resolveMemberIncludePath($file);
        } else {
            $path = app(\app\common\service\theme\ThemeService::class)->resolveSiteTemplatePathWithFallback($file . '.php', $theme);
        }
        if ($path === '' || !is_file($path)) {
            return '<!-- include not found: ' . htmlspecialchars($file) . ' -->';
        }

        $memoKey = app(TemplateIncludeMemoService::class)->buildKey($theme, $path, $attrs, $pageVars);

        if (!app(TemplateIncludeDepthService::class)->enter()) {
            return app(SiteModeService::class)->isDev()
                ? '<!-- pv:include max depth -->'
                : '';
        }
        try {
            return app(TemplateIncludeMemoService::class)->remember($memoKey, function () use ($path, $attrs, $pageVars, $file): string {
                $raw = app(\app\common\service\template\TemplateMetaService::class)->stripLeadMeta((string) file_get_contents($path));
                if (str_contains($raw, '<?php')) {
                    throw new \RuntimeException('模板片段禁止 PHP：' . $file);
                }
                $chunk = app(TemplateCompileCacheService::class)->remember($path, $raw);
                $localVars = $pageVars;
                foreach ($attrs as $key => $value) {
                    if ($key === 'file') {
                        continue;
                    }
                    $localVars[$key] = app(TemplateTagParser::class)->resolveAttrValue($value, $pageVars);
                }

                return app(TemplateTagParser::class)->parseTags($chunk, $localVars, null, true);
            });
        } finally {
            app(TemplateIncludeDepthService::class)->leave();
        }
    }

public function renderDocumentList(array $m, array $pageVars): string
    {
        $ctx = app(ListPageTemplateContextService::class);
        if (!$ctx->isActive($pageVars)) {
            return $ctx->rejectListTag($pageVars);
        }

        return app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
            'list',
                '块标签 item=field，须 list_page=1；例：<span>{$field.title}</span>'
        );
    }

public function renderListBlock(array $m, array $pageVars): string
    {
        $attrs = app(TemplateTagParser::class)->parseAttrs($m[1]);
        $tpl   = $m[2] ?? '';

        return app(DocumentListTemplateTagService::class)->renderBlock($attrs, $tpl, $pageVars);
    }

public function renderConfig(array $m): string
    {
        $attrs = app(TemplateTagParser::class)->parseAttrs($m[1]);
        $key   = trim((string) ($attrs['key'] ?? ''));
        if ($key === '') {
            return '';
        }

        $invokeKey = app(TemplateTagInvokeCacheService::class)->key('config', ['key' => $key]);

        return app(TemplateTagInvokeCacheService::class)->remember($invokeKey, static function () use ($key): string {
            return htmlspecialchars((string) app(\app\common\service\config\ConfigService::class)->getForTemplate($key, ''));
        });
    }

public function renderCustomVar(array $m): string
    {
        $attrs = app(TemplateTagParser::class)->parseAttrs($m[1]);
        $name  = trim((string) ($attrs['name'] ?? ''));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            return '';
        }

        $invokeKey = app(TemplateTagInvokeCacheService::class)->key('var', ['name' => $name]);

        return app(TemplateTagInvokeCacheService::class)->remember($invokeKey, static function () use ($name): string {
            return htmlspecialchars((string) app(\app\common\service\config\ConfigService::class)->get('cv_' . $name . '_value', ''));
        });
    }

public function renderTagArticles(array $m, array $pageVars = []): string
    {
        $attrs    = app(TemplateTagParser::class)->parseAttrs($m[1]);
        $tpl      = $m[2];
        $cacheKey = app(TemplateBlockCacheService::class)->keyForTagdocuments($attrs, $tpl);
        $invokeKey = app(TemplateTagInvokeCacheService::class)->key('tagdocuments', ['block' => $cacheKey]);

        return app(TemplateTagInvokeCacheService::class)->remember($invokeKey, function () use ($attrs, $tpl, $cacheKey, $pageVars): string {
            $cached = app(TemplateBlockCacheService::class)->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
            $entity = strtolower(trim((string) ($attrs['entity'] ?? 'document')));
            if (in_array($entity, ['product', 'products', 'item', 'items'], true)) {
                $list = $this->listArclistProductFields($attrs, $pageVars);
            } else {
                $params = $this->listPublicParamsFromTagAttrs($attrs, $pageVars);
                $result = app(DocumentPublicService::class)->listPublic($params);
                $list   = [];
                foreach ($result['list'] as $row) {
                    if (is_array($row)) {
                        $field = $this->normalizeArticleField($row);
                        $field['entity'] = 'document';
                        $list[] = $field;
                    }
                }
            }
            $out = app(DocumentListTemplateTagService::class)->renderLoop(
                $attrs,
                $tpl,
                $list,
                $pageVars,
                'field',
                false,
            );
            app(TemplateBlockCacheService::class)->set($cacheKey, $out);

            return $out;
        });
    }

    /**
     * arclist entity=product：复用 ItemListTagAttrsService + ItemPublicGateway（栏目/Tag 品项列表真源）。
     *
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     * @return list<array<string, mixed>>
     */
    private function listArclistProductFields(array $attrs, array $pageVars): array
    {
        if (!\app\common\service\product\ProductCenterGateService::publicSurfaceOpen()) {
            return [];
        }
        $attrsService = app(\app\common\service\item\ItemListTagAttrsService::class);
        if (\app\common\service\product\ProductService::preferInjectedProductList($attrs, $pageVars)) {
            $raw = is_array($pageVars['product_list'] ?? null) ? $pageVars['product_list'] : [];
        } else {
            $result = app(\app\common\service\item\ItemPublicGateway::class)->listPublic(
                $attrsService->listParamsFromAttrs($attrs, $pageVars)
            );
            $raw = is_array($result['list'] ?? null) ? $result['list'] : [];
        }
        $list = $attrsService->normalizeItemList($raw);
        if (class_exists(\app\common\service\product\OfferBridgeFacade::class)
            && class_exists(\app\common\service\product\ProductCenterGateService::class)
            && \app\common\service\product\ProductCenterGateService::requirePublicApi() === null) {
            app(\app\common\service\product\OfferBridgeFacade::class)->attachOfferSummariesToItems($list);
        }
        foreach ($list as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $row['entity'] = 'product';
        }
        unset($row);

        return array_values(array_filter($list, static fn ($r): bool => is_array($r)));
    }

public function mapTagArticlesSort(array $attrs): string
    {
        if (isset($attrs['sort']) && $attrs['sort'] !== '') {
            return (string) $attrs['sort'];
        }
        $orderby = strtolower(trim((string) ($attrs['orderby'] ?? '')));
        return match ($orderby) {
            'add_time', 'new', 'update_time' => 'published_at_desc',
            'click', 'hot'                   => 'click_desc',
            'like', 'favorite'               => 'favorite_desc',
            'collect', 'fav'                 => 'collect_desc',
            'aid'                            => 'id_desc',
            default                          => 'id_desc',
        };
    }

public function mapTagArticlesAttr(array $attrs): string
    {
        return $this->mapDocumentAttrToken((string) ($attrs['attr'] ?? $attrs['flag'] ?? ''));
    }

    /** noattr / noflag：排除带指定文档属性的条目 */
    public function mapTagArticlesNoAttr(array $attrs): string
    {
        return $this->mapDocumentAttrToken((string) ($attrs['noattr'] ?? $attrs['noflag'] ?? ''));
    }

    private function mapDocumentAttrToken(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (in_array($raw, DocumentAttrHelper::ATTR_FLAGS, true)) {
            return $raw;
        }
        $letter = strtolower(trim(explode(',', $raw)[0] ?? ''));

        return match ($letter) {
            'c'     => 'recommend',
            'h'     => 'headline',
            'p'     => 'has_image',
            'j'     => 'external',
            'b'     => 'bold',
            't'     => 'push',
            default => in_array($letter, DocumentAttrHelper::ATTR_FLAGS, true) ? $letter : '',
        };
    }

    /**
     * @param array<string, mixed>  $attrs tagdocuments / tagarticles 属性
     * @param array<string, mixed>  $pageVars 当前页变量（nav="page" 等）
     * @return array<string, mixed> DocumentPublicService::listPublic 参数
     */
    public function listPublicParamsFromTagAttrs(array $attrs, array $pageVars = []): array
    {
        $limit  = (int) ($attrs['row'] ?? $attrs['loop'] ?? 10);
        $offset = 0;
        if (isset($attrs['limit']) && preg_match('/^(\d+)\s*,\s*(\d+)$/', trim((string) $attrs['limit']), $lm)) {
            $offset = (int) $lm[1];
            $limit  = (int) $lm[2];
        } elseif (isset($attrs['limit']) && preg_match('/^\d+$/', trim((string) $attrs['limit']))) {
            $limit = (int) trim((string) $attrs['limit']);
        } elseif (isset($attrs['offset'])) {
            $offset = (int) $attrs['offset'];
        }

        $filter = app(ArclistTagResolveService::class)->resolveDocumentFilterParams($attrs, $pageVars);
        $tags   = ((int) ($filter['nav_id'] ?? 0) > 0)
            ? ''
            : app(ArclistTagResolveService::class)->resolveTagsParam($attrs, $pageVars);

        return array_merge([
            'page'    => max(1, (int) ($attrs['page'] ?? 1)),
            'limit'   => $limit,
            'offset'  => $offset,
            'tags'    => $tags,
            'keyword' => (string) ($attrs['keyword'] ?? ''),
            'sort'    => $this->mapTagArticlesSort($attrs),
            'attr'    => $this->mapTagArticlesAttr($attrs),
            'noattr'  => $this->mapTagArticlesNoAttr($attrs),
            'has'     => DocumentAddonBridgeAccess::resolveAvailabilitySlot(
                (string) ($attrs['has'] ?? '')
            ),
            'nohas'   => DocumentAddonBridgeAccess::resolveAvailabilitySlot(
                (string) ($attrs['nohas'] ?? '')
            ),
            'id'      => (int) ($attrs['id'] ?? $attrs['aid'] ?? 0),
            'ids'     => (string) ($attrs['ids'] ?? $attrs['idlist'] ?? ''),
            'period'  => (string) ($attrs['period'] ?? ''),
            'since'   => (string) ($attrs['since'] ?? $attrs['published_since'] ?? ''),
            'until'   => (string) ($attrs['until'] ?? $attrs['published_until'] ?? ''),
        ], $filter);
    }

    /** @param array<string, string> $attrs */
    public function tagCatalogParamsFromAttrs(array $attrs): array
    {
        $limit = (int) ($attrs['row'] ?? $attrs['loop'] ?? 20);
        $sort  = strtolower(trim((string) ($attrs['sort'] ?? $attrs['orderby'] ?? '')));
        if ($sort === '' && isset($attrs['mode'])) {
            $sort = match (strtolower(trim((string) $attrs['mode']))) {
                'new', 'latest' => 'new',
                'nav', 'navigation' => 'nav',
                'name', 'alpha' => 'name',
                default => 'hot',
            };
        }
        if ($sort === '') {
            $sort = 'hot';
        }

        $catalogFilter = app(ArclistTagResolveService::class)->resolveCatalogFilterParams($attrs);

        return array_merge([
            'page'        => max(1, (int) ($attrs['page'] ?? 1)),
            'limit'       => $limit,
            'sort'        => $sort,
            'kind'        => (string) ($attrs['kind'] ?? ''),
            'keyword'     => (string) ($attrs['keyword'] ?? ''),
            'group_id'    => (int) ($attrs['group_id'] ?? 0),
            'period'      => (string) ($attrs['period'] ?? ''),
            'since'       => (string) ($attrs['since'] ?? ''),
            'has_documents' => (string) ($attrs['has_documents'] ?? ''),
        ], [
            'include_tag_ids'       => $catalogFilter['include_tag_ids'],
            'exclude_tag_ids'       => $catalogFilter['exclude_tag_ids'],
            'tag_group_ids'         => $catalogFilter['tag_group_ids'],
            'exclude_tag_group_ids' => $catalogFilter['exclude_tag_group_ids'],
            'parent_id'             => $catalogFilter['parent_id'],
            'min_document_count'    => $catalogFilter['min_document_count'],
        ]);
    }

public function renderTagCloud(array $m, array $pageVars = []): string
    {
        $attrs     = app(TemplateTagParser::class)->parseAttrs($m[1]);
        $tpl       = $m[2];
        $sortRaw   = strtolower(trim((string) ($attrs['sort'] ?? $attrs['orderby'] ?? $attrs['mode'] ?? '')));
        $isRandom  = in_array($sortRaw, ['rand', 'random', 'shuffle'], true);
        $cacheKey  = app(TemplateBlockCacheService::class)->keyForTagcloud($attrs, $tpl);
        $invokeKey = app(TemplateTagInvokeCacheService::class)->key('tagcloud', ['block' => $cacheKey]);

        $render = function () use ($attrs, $tpl, $cacheKey, $pageVars, $isRandom): string {
            if (!$isRandom) {
                $cached = app(TemplateBlockCacheService::class)->get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }

            $params = $this->tagCatalogParamsFromAttrs($attrs);
            $result = app(\app\common\service\tag\TagService::class)->listPublicQuery($params);
            $counts = app(\app\common\service\tag\TagService::class)->countPublishedDocumentsByTagIds(
                array_column($result['list'], 'id')
            );
            $limit  = max(1, (int) ($result['limit'] ?? 1));
            $total  = (int) ($result['total'] ?? 0);
            $loopVars = array_merge($pageVars, [
                'tag_catalog_total'        => $total,
                'tag_catalog_page'         => (int) ($result['page'] ?? 1),
                'tag_catalog_limit'        => $limit,
                'tag_catalog_total_pages'  => (int) max(1, (int) ceil($total / $limit)),
            ]);
            $out = '';
            foreach ($result['list'] as $field) {
                $tid = (int) ($field['id'] ?? 0);
                if ($tid > 0) {
                    $field['document_count'] = $counts[$tid] ?? (int) ($field['document_count'] ?? 0);
                }
                $out .= $this->renderLoop($tpl, $field, $loopVars);
            }
            if (!$isRandom) {
                app(TemplateBlockCacheService::class)->set($cacheKey, $out);
            }

            return $out;
        };

        // 随机标签不缓存块 HTML，避免整站共用同一批结果
        return $isRandom
            ? $render()
            : app(TemplateTagInvokeCacheService::class)->remember($invokeKey, $render);
    }

public function renderFriendLinks(array $m, array $pageVars = []): string
    {
        $attrs = app(TemplateTagParser::class)->parseAttrs($m[1]);
        $tpl   = $m[2];
        $rows  = app(\app\common\service\site\SiteLinkService::class)->listPublic((int) ($attrs['row'] ?? 20));
        $out   = '';
        foreach ($rows as $field) {
            $out .= $this->renderLoop($tpl, $field, $pageVars);
        }

        return $out;
    }

public function renderSiteAds(array $m, array $pageVars = []): string
    {
        $attrs = app(TemplateTagParser::class)->parseAttrs($m[1]);
        $slot  = trim((string) ($attrs['slot'] ?? $attrs['code'] ?? ''));
        if ($slot === '') {
            return '';
        }
        $tpl = trim((string) ($m[2] ?? ''));
        if ($tpl === '') {
            return app(TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                'siteads',
                '块标签 siteads 须带 slot 与模板体'
            );
        }
        $rows = app(\app\common\service\site\SiteSlideService::class)->listPublicForCurrentPage($slot);
        $out  = '';
        foreach ($rows as $field) {
            if (app(SiteSlideService::class)->isOverlayCreativeType((string) ($field['creative_type'] ?? ''))) {
                continue;
            }
            if (($field['creative_type'] ?? '') === \app\common\service\site\SiteSlideService::TYPE_HTML) {
                $field['html_body'] = (string) ($field['html_body'] ?? '');
            }
            $out .= $this->renderLoop($tpl, $field, $pageVars);
        }

        return $out;
    }

public function renderTagList(array $m, array $pageVars): string
    {
        $attrs = app(TemplateTagParser::class)->parseAttrs($m[1]);
        $tpl   = trim((string) ($m[2] ?? ''));
        if ($tpl === '') {
            return app(TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                'taglist',
                '块内写标签项，例：<a href="{$field.url}">{$field.name}</a>'
            );
        }
        $id    = (int) app(TemplateTagParser::class)->resolveAttrValue((string) ($attrs['id'] ?? $attrs['document_id'] ?? '0'), $pageVars);
        if ($id < 1) {
            return '';
        }
        $out = '';
        foreach (app(\app\common\service\tag\TagService::class)->getTagsForDocument($id) as $field) {
            $out .= $this->renderLoop($tpl, $field, $pageVars);
        }
        return $out;
    }

public function renderArcViewBlock(array $m, array $pageVars): string
    {
        $attrs = app(TemplateTagParser::class)->parseAttrs($m[1]);
        $tpl   = trim((string) ($m[2] ?? ''));
        if ($tpl === '') {
            return app(TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                'arcview',
                '块标签须带模板体，例：{$field.title}'
            );
        }
        $id    = (int) app(TemplateTagParser::class)->resolveAttrValue((string) ($attrs['id'] ?? '0'), $pageVars);
        if ($id < 1 && isset($pageVars['document_id'])) {
            $id = (int) $pageVars['document_id'];
        }
        if ($id < 1) {
            return '';
        }
        $detail = app(DocumentPublicService::class)->getPublicDetail($id);
        return $detail === null ? '' : $this->renderLoop($tpl, $this->normalizeArticleField($detail, true), $pageVars);
    }

public function renderRelatedBlock(array $m, array $pageVars): string
    {
        $attrs = app(TemplateTagParser::class)->parseAttrs($m[1]);
        $tpl   = trim((string) ($m[2] ?? ''));
        if ($tpl === '') {
            return app(TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                'related',
                '块标签须带模板体，例：<a href="{$field.url}">{$field.title}</a>'
            );
        }
        $id = (int) app(TemplateTagParser::class)->resolveAttrValue((string) ($attrs['id'] ?? $attrs['document_id'] ?? '0'), $pageVars);
        if ($id < 1) {
            $id = (int) ($pageVars['document_id'] ?? 0);
        }
        if ($id < 1) {
            return '';
        }
        $limit  = min(max((int) ($attrs['row'] ?? $attrs['loop'] ?? 6), 1), 20);
        $rows   = app(DocumentPublicService::class)->getRelatedPublic($id, $limit);
        $out    = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $field = $this->normalizeArticleField($row);
            $out  .= $this->renderLoop($tpl, [
                'id'              => (int) ($field['id'] ?? 0),
                'title'           => (string) ($field['title'] ?? ''),
                'url'             => (string) ($field['url'] ?? ''),
                'litpic'          => (string) ($field['litpic'] ?? ''),
                'excerpt_short'   => (string) ($field['excerpt_short'] ?? ''),
                'create_date'     => (string) ($field['create_date'] ?? ''),
                'published_date'  => (string) ($field['published_date'] ?? ''),
            ], $pageVars);
        }

        return $out;
    }

public function normalizeArticleField(array $row, bool $detail = false): array
    {
        $field = $row;
        // 强制按主频道重建（勿沿用 listPublic/缓存里的 /documents/ 旧链）
        $field['url'] = SiteUrl::documentFromRow($row);
        $field['arcurl'] = $field['url'];
        $field['create_date'] = !empty($row['created_at'])
            ? AppTime::format('Y-m-d', strtotime((string) $row['created_at'])) : '';
        $field['published_date'] = !empty($row['published_at'])
            ? AppTime::format('Y-m-d', strtotime((string) $row['published_at'])) : $field['create_date'];
        $field['date'] = $field['published_date'];
        $ts = 0;
        if (!empty($row['published_at'])) {
            $ts = (int) strtotime((string) $row['published_at']);
        } elseif (!empty($row['created_at'])) {
            $ts = (int) strtotime((string) $row['created_at']);
        }
        if ($ts > 0) {
            $field['published_year'] = AppTime::format('Y', $ts);
            $field['published_md'] = AppTime::format('m-d', $ts);
        } else {
            $field['published_year'] = '';
            $field['published_md'] = '';
        }
        $field['excerpt'] = (string) ($row['summary'] ?? '');
        $plain            = $field['excerpt'];
        $field['excerpt_short'] = mb_strlen($plain) > 120 ? mb_substr($plain, 0, 120) : $plain;
        $field['litpic'] = (string) ($row['litpic'] ?? '');
        if ($detail && isset($row['content'])) {
            $field['content_html'] = (string) $row['content'];
        }

        return app(DocumentAttrHelper::class)->decorateAttrFlagsForView($field);
    }

public function renderLoop(string $tpl, array $field, array $pageVars = []): string
    {
        return app(TemplateTagParser::class)->renderItemLoop($tpl, [$field], $pageVars);
    }
}
