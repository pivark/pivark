<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\model\Document;
use app\common\model\Item;
use app\common\model\SiteNav;
use app\common\service\site\SiteNavService;
use app\common\support\OpsLog;

/**
 * 首页区块模板变量：只认配置 home_*_nav_id（禁 path 猜词）。
 */
final class HomeBlockSiteVars
{
    /**
     * @param array<string, mixed> $cfg
     * @return array<string, mixed>
     */
    public function vars(array $cfg): array
    {
        $newsNav = $this->resolveConfiguredNavId($cfg, 'home_news_nav_id');
        $dlNav   = $this->resolveConfiguredNavId($cfg, 'home_download_nav_id');
        $vidNav  = $this->resolveConfiguredNavId($cfg, 'home_video_nav_id');
        $prodNav = $this->resolveConfiguredNavId($cfg, 'home_product_nav_id');

        $newsHas = $this->homeNavHasDocuments($newsNav);
        $dlHas   = $this->homeNavHasDocuments($dlNav);
        $vidHas  = $this->homeNavHasDocuments($vidNav);
        $prodHas = $prodNav > 0
            ? ($this->homeNavHasItems($prodNav) || $this->homeNavHasDocuments($prodNav))
            : $this->anyPublicItems();

        return [
            'home_news_nav_id'     => $newsNav,
            'home_news_url'        => $this->homeBlockListUrl($newsNav),
            'home_news_has'        => $newsHas ? 1 : 0,
            'home_download_nav_id' => $dlNav,
            'home_download_url'    => $this->homeBlockListUrl($dlNav),
            'home_download_has'    => $dlHas ? 1 : 0,
            'home_news_dl_has'     => ($newsHas || $dlHas) ? 1 : 0,
            'home_video_nav_id'    => $vidNav,
            'home_video_url'       => $this->homeBlockListUrl($vidNav),
            'home_video_has'       => $vidHas ? 1 : 0,
            'home_dl_video_has'    => ($dlHas || $vidHas) ? 1 : 0,
            'home_product_nav_id'  => $prodNav,
            'home_product_url'     => $this->homeBlockListUrl($prodNav),
            'home_product_has'     => $prodHas ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function resolveConfiguredNavId(array $cfg, string $key): int
    {
        $configured = (int) ($cfg[$key] ?? 0);
        if ($configured < 1) {
            return 0;
        }
        $row = SiteNav::where('id', $configured)->where('status', 1)->find();

        return $row !== null ? $configured : 0;
    }

    private function homeBlockListUrl(int $navId): string
    {
        if ($navId < 1) {
            return '';
        }
        $row = SiteNav::where('id', $navId)->where('status', 1)->find()?->toArray();
        if (!is_array($row) || $row === []) {
            return '';
        }
        $url = trim(app(SiteNavService::class)->resolveUrl($row));

        return ($url !== '' && $url !== '#') ? $url : '';
    }

    private function homeNavHasDocuments(int $navId): bool
    {
        if ($navId < 1) {
            return false;
        }
        try {
            $navIds = app(SiteNavService::class)->contentCategorySelfAndDescendantIds($navId);
            $q = Document::whereNull('deleted_at')->where('status', 1);
            app(SiteNavService::class)->applyPrimaryOrExtraNavFilter($q, $navIds, 'document');

            return (int) $q->limit(1)->count() > 0;
        } catch (\Throwable $e) {
            $this->logDegrade('home_nav_docs', $e);

            return false;
        }
    }

    private function homeNavHasItems(int $navId): bool
    {
        if ($navId < 1) {
            return false;
        }
        try {
            $navIds = app(SiteNavService::class)->contentCategorySelfAndDescendantIds($navId);
            $q = Item::where('status', \app\common\service\item\ItemService::STATUS_ACTIVE);
            app(SiteNavService::class)->applyPrimaryOrExtraNavFilter($q, $navIds, 'item');

            return (int) $q->limit(1)->count() > 0;
        } catch (\Throwable $e) {
            $this->logDegrade('home_nav_items', $e);

            return false;
        }
    }

    private function anyPublicItems(): bool
    {
        try {
            return (int) Item::where('status', \app\common\service\item\ItemService::STATUS_ACTIVE)
                ->limit(1)
                ->count() > 0;
        } catch (\Throwable $e) {
            $this->logDegrade('home_any_items', $e);

            return false;
        }
    }

    private function logDegrade(string $context, \Throwable $e): void
    {
        OpsLog::businessWarning('home_block_site_vars_degrade', [
            'context' => $context,
            'msg'     => $e->getMessage(),
        ]);
    }
}
