<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\item\ItemService;
use app\common\service\weapp\WeappSiteGateway;
use app\common\service\weapp\WeappItemGateway;
use app\common\service\front\FrontUrlBuilder;
use app\common\service\item\ItemPublicVisibilityService;
use app\common\model\Item;
use app\common\model\SitePage;

/** 产品相关 URL 纳入 Sitemap */
class ProductSitemapService
{
    /**
     * @param list<array<string, mixed>> $urls
     * @return list<array<string, mixed>>
     */
    public static function appendUrls(
        array $urls,
        string $base,
        string $freqList,
        string $freqContent,
        string $priList,
        string $priContent,
    ): array {
        if (!ProductCenterGateService::publicSurfaceOpen() || $base === '') {
            return $urls;
        }
        $seen = [];
        foreach ($urls as $u) {
            $seen[(string) ($u['loc'] ?? '')] = true;
        }
        $append = static function (array $entry) use (&$urls, &$seen): void {
            $loc = (string) ($entry['loc'] ?? '');
            if ($loc === '' || isset($seen[$loc])) {
                return;
            }
            $seen[$loc] = true;
            $urls[]     = $entry;
        };

        $listTpls = ['list_page_products.php'];
        if (ProductConfigService::sitemapIncludeItemsTpl()) {
            $listTpls[] = 'list_page_items.php';
        }
        foreach (SitePage::where('status', 1)->field('title,path,tpl_name,updated_at')->select()->toArray() as $row) {
            $tpl = app(WeappSiteGateway::class)->sitePageNormalizeTpl((string) ($row['tpl_name'] ?? ''));
            if (!in_array($tpl, $listTpls, true)) {
                continue;
            }
            $path = trim((string) ($row['path'] ?? ''), '/');
            if ($path === '') {
                continue;
            }
            $append([
                'loc'        => $base . app(FrontUrlBuilder::class)->pageFromRow($row),
                'changefreq' => $freqList,
                'priority'   => $priList,
                'lastmod'    => (string) ($row['updated_at'] ?? ''),
                'title'      => (string) ($row['title'] ?? '产品中心'),
                'kind'       => $tpl === 'list_page_items.php' ? 'item_list' : 'product_list',
            ]);
        }

        $statuses = [ItemService::STATUS_ACTIVE];
        if (ProductConfigService::sitemapIncludeDiscontinued()) {
            $statuses[] = ItemService::STATUS_DISCONTINUED;
        }
        $itemQuery = Item::whereIn('status', $statuses)
            ->where('primary_document_id', '>', 0)
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->limit(2000);
        app(ItemPublicVisibilityService::class)->applyToQuery($itemQuery, ItemPublicVisibilityService::CHANNEL_WWW);
        $rows = $itemQuery->select()->toArray();
        foreach ($rows as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            $loc  = $slug !== '' ? app(WeappItemGateway::class)->itemResolvePublicPageUrl($slug) : '';
            if ($loc === '') {
                $loc = app(WeappItemGateway::class)->itemResolvePublicDetailUrl((int) ($row['primary_document_id'] ?? 0));
            }
            if ($loc === '') {
                continue;
            }
            if (!preg_match('#^https?://#i', $loc)) {
                $loc = $base . ($loc[0] === '/' ? $loc : '/' . $loc);
            }
            $append([
                'loc'        => $loc,
                'changefreq' => $freqContent,
                'priority'   => $priContent,
                'lastmod'    => (string) ($row['updated_at'] ?? ''),
                'title'      => (string) ($row['name'] ?? ''),
                'kind'       => 'product_item',
            ]);
        }

        return $urls;
    }
}
