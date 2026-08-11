<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
     * @param mixed $rawPath
     * @param mixed $queryPage
 */
declare(strict_types=1);

namespace app\common\service\front;

use app\common\service\catalog\CatalogFacetPathService;
use app\common\service\site\SitePageService;
use app\common\service\site\SiteNavService;
use app\common\service\infra\UrlPathService;
use app\common\service\tag\TagService;
use app\common\service\document\DocumentPublicService;
use app\common\service\site\SiteUrlModeService;
/** 前台入站 URL 统一解析 → 分发指令 */
class FrontUrlResolver
{

    /**
     * @return array{type:string,page:int,data?:array<string,mixed>,id?:int}|null
     */
    public function resolve(string $rawPath, int $queryPage = 1): ?array
    {
        $parsed = app(FrontUrlRuleService::class)->parseInbound($rawPath, $queryPage);
        $page   = max(1, $parsed['page'] > 1 ? $parsed['page'] : $queryPage);

        return $this->resolveParsed($parsed['path'], $page, $parsed['sub_key'], $parsed['kind']);
    }

    /**
     * @return array{type:string,page:int,data?:array<string,mixed>,id?:int}|null
     * @param mixed $rawPath
     * @param mixed $pageParam
     * @param mixed $queryPage
     */
    public function resolvePaged(string $rawPath, string $pageParam, int $queryPage = 1): ?array
    {
        $path  = app(UrlPathService::class)->normalize(app(SiteUrlModeService::class)->stripSuffix(trim($rawPath, '/')));
        $token = app(SiteUrlModeService::class)->stripSuffix(trim($pageParam));
        if ($path === '') {
            return null;
        }

        // /downloads/{html_name} 等：第二段不是 list_N/数字页码时按频道下文档解析
        $page = app(FrontUrlRuleService::class)->parsePageSegment($pageParam);
        if ($page <= 0 && $token !== '' && $token !== 'index') {
            return $this->resolveParsed($path, max(1, $queryPage), $token, 'nested');
        }

        // 纯数字第二段：优先按文档 ID 解析（与 buildDocument 无合法 html_name 时吐出 /channel/{id}.html 对齐）
        // list_N 仍只做分页；无对应公开文档时再回退为列表页码
        if ($page > 0 && $token !== '' && $token !== 'index' && ctype_digit($token)) {
            $asDoc = $this->resolveParsed($path, max(1, $queryPage), $token, 'nested');
            if ($asDoc !== null && ($asDoc['type'] ?? '') === 'document') {
                return $asDoc;
            }
        }

        $kind = 'tag_list';
        if ($token === 'index') {
            $kind = 'channel_home';
            // /{channel}/index.html?page=N → 须认 query，勿钉死第 1 页
            $page = max(1, $queryPage);
        } else {
            $page = max(1, $page > 0 ? $page : $queryPage);
        }

        return $this->resolveParsed($path, $page, '', $kind);
    }

    /** 文档 /articles/:key 路由 key 段（去掉 .html） */
    public function normalizeDocumentKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        return app(SiteUrlModeService::class)->stripSuffix($key);
    }

    /**
     * @return array{type:string,page:int,data?:array<string,mixed>,id?:int}|null
     */
    private function resolveParsed(string $path, int $page, string $subKey, string $kind): ?array
    {
        if ($path === '') {
            return null;
        }

        if ($subKey !== '' && $kind === 'nested') {
            // 多级栏目：/xinwen/guoji 优先整段门牌，再回退父栏 + 文档/品项/筛
            $deepPath = app(UrlPathService::class)->normalize($path . '/' . $subKey);
            if ($deepPath !== '' && $deepPath !== $path) {
                $deepCategory = app(SiteNavService::class)->findContentCategoryByPublicPath($deepPath);
                if ($deepCategory !== null) {
                    return ['type' => 'category', 'page' => max(1, $page), 'data' => $deepCategory];
                }
                $deepTag = app(TagService::class)->findRowByUrlPath($deepPath);
                if ($deepTag !== null) {
                    return ['type' => 'tag', 'page' => max(1, $page), 'data' => $deepTag];
                }
            }

            // 真栏目门牌优先：文档/品项/参数组筛均挂 site_nav
            $category = app(SiteNavService::class)->findContentCategoryByPublicPath($path);
            if ($category !== null) {
                $articleId = app(DocumentPublicService::class)->resolvePublicArticleKey($subKey);
                if ($articleId > 0) {
                    return ['type' => 'document', 'page' => 1, 'id' => $articleId];
                }
                $itemSlug = trim($subKey);
                if ($itemSlug !== '' && app(\app\common\service\item\ItemService::class)->findPublicBySlug($itemSlug) !== null) {
                    return ['type' => 'item', 'page' => 1, 'slug' => $itemSlug];
                }
                $facetFilters = app(CatalogFacetPathService::class)->tryDecodeForExtra($category, $subKey);
                if ($facetFilters !== null) {
                    $category['_catalog_facet_filters'] = $facetFilters;

                    return ['type' => 'category', 'page' => max(1, $page), 'data' => $category];
                }
            }

            // 纯聚合 Tag（非分类门牌）嵌套：文档/品项/专题筛
            $tagRow = app(TagService::class)->findRowByUrlPath($path);
            if ($tagRow !== null) {
                $articleId = app(DocumentPublicService::class)->resolvePublicArticleKey($subKey);
                if ($articleId > 0) {
                    return ['type' => 'document', 'page' => 1, 'id' => $articleId];
                }
                $itemSlug = trim($subKey);
                if ($itemSlug !== '' && app(\app\common\service\item\ItemService::class)->findPublicBySlug($itemSlug) !== null) {
                    return ['type' => 'item', 'page' => 1, 'slug' => $itemSlug];
                }
                $facetFilters = app(CatalogFacetPathService::class)->tryDecodeForExtra($tagRow, $subKey);
                if ($facetFilters !== null) {
                    $tagRow['_catalog_facet_filters'] = $facetFilters;

                    return ['type' => 'tag', 'page' => max(1, $page), 'data' => $tagRow];
                }
            }

            // 第二段存在但不是频道文档/参数筛：禁止回落到父 path 单页（否则 /portal/account 会显示 portal）
            return null;
        }

        $sitePage = app(SitePageService::class)->findByPath($path);
        if ($sitePage !== null) {
            return ['type' => 'page', 'page' => 1, 'data' => $sitePage];
        }

        // 栏目门牌同址归 site_nav；入站栏目不以 Tag 冒充
        $category = app(SiteNavService::class)->findContentCategoryByPublicPath($path);
        if ($category !== null) {
            return ['type' => 'category', 'page' => $page, 'data' => $category];
        }

        $tagRow = app(TagService::class)->findRowByUrlPath($path);
        if ($tagRow !== null) {
            return ['type' => 'tag', 'page' => $page, 'data' => $tagRow];
        }

        $articleId = app(DocumentPublicService::class)->resolvePublicArticleByUrlPath($path);
        if ($articleId > 0) {
            return ['type' => 'document', 'page' => 1, 'id' => $articleId];
        }

        if (app(SiteUrlModeService::class)->articleRule() === SiteUrlModeService::ARTICLE_ROOT) {
            $articleId = app(DocumentPublicService::class)->resolvePublicArticleKey($path);
            if ($articleId > 0) {
                return ['type' => 'document', 'page' => 1, 'id' => $articleId];
            }
        }

        return null;
    }
}
