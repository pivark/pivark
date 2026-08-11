<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);


namespace app\common\service\plugin\market;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\commerce\PluginSkuCatalogService;

use app\common\service\admin\AdminPortalService;
use app\common\service\plugin\manifest\PluginDistributionPolicy;

/** 插件市场目录：浏览、定价展示、授权统计 */
final class PluginMarketCatalogService
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $catalogListCache = [];

    public static function flushListCache(): void
    {
        self::clearListMemoryOnly();
        app(PluginMarketShelfDirectory::class)->invalidateCache();
        PluginService::clearListAdminCache();
    }

    /** 仅清进程内列表缓存（供 RemoteCatalog::invalidateCache 调用，避免循环） */
    public static function clearListMemoryOnly(): void
    {
        self::$catalogListCache = [];
    }

    /** 市场货架可见性真源（官方 Store / 客户站 Catalog 同管） */
    public function marketCatalogVisible(string $identifier): bool
    {
        return app(PluginService::class)->marketCatalogVisible($identifier);
    }

    /**
     * @return array{source:string,updated_at:string,remote_ok:bool,taxonomy?:array<string,mixed>}
     */
    public function catalogMeta(): array
    {
        $load = app(PluginMarketShelfDirectory::class)->load();
        $feedMeta = is_array($load['feed_meta'] ?? null) ? $load['feed_meta'] : [];
        $paramFilters = $this->normalizeParamFiltersFromFeed($feedMeta);

        return [
            'source'     => $load['source'],
            'updated_at' => $load['updated_at'],
            'remote_ok'  => !empty($load['ok']),
            'taxonomy'   => [
                // 筛条 SSOT：Feed meta.param_filters（插件参数组 filterable）；非从卡片 scrape
                'param_filters'    => $paramFilters,
                'filter_group_key' => trim((string) ($feedMeta['filter_group_key'] ?? 'plugin')) ?: 'plugin',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $feedMeta
     * @return list<array{param_key:string,label:string,options:list<string>}>
     */
    private function normalizeParamFiltersFromFeed(array $feedMeta): array
    {
        $raw = is_array($feedMeta['param_filters'] ?? null) ? $feedMeta['param_filters'] : [];
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['param_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $options = [];
            foreach (is_array($row['options'] ?? null) ? $row['options'] : [] as $opt) {
                $opt = trim((string) $opt);
                if ($opt !== '') {
                    $options[] = $opt;
                }
            }
            $out[] = [
                'param_key' => $key,
                'label'     => trim((string) ($row['label'] ?? $key)) ?: $key,
                'options'   => array_values(array_unique($options)),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, string> $paramFilters filter_* 或裸 param_key
     * @return array<string, string> param_key => 选项值
     */
    public function resolveActiveParamFilters(array $paramFilters): array
    {
        $out = [];
        foreach ($paramFilters as $k => $v) {
            $key = preg_replace('/^filter_/', '', trim((string) $k)) ?? '';
            $val = trim((string) $v);
            if ($key !== '' && $val !== '') {
                $out[$key] = $val;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $paramFilters
     * @return list<array<string, mixed>>
     */
    public function catalog(string $keyword = '', array $paramFilters = []): array
    {
        $paramFilters = $this->resolveActiveParamFilters($paramFilters);

        return $this->catalogCached($this->catalogCacheKey($keyword, $paramFilters));
    }

    /**
     * @param array<string, string> $paramFilters
     * @return list<array<string, mixed>>
     */
    private function buildCatalog(string $keyword, array $paramFilters = []): array
    {
        $paramFilters = $this->resolveActiveParamFilters($paramFilters);
        $remoteLoad = app(PluginMarketShelfDirectory::class)->load();
        $remoteIdx = app(PluginMarketShelfDirectory::class)->indexByIdentifier();
        $remoteSource = (string) ($remoteLoad['source'] ?? 'local');
        $adminById = [];
        foreach (app(PluginService::class)->listAdmin() as $adminItem) {
            $adminById[(string) ($adminItem['identifier'] ?? '')] = $adminItem;
        }

        $seen  = [];
        $items = [];
        // 有发布目录时货架成员 = Feed/catalog；本地 discover 只补安装态，禁止未上架 weapp 占货架
        $shelfOnly = $remoteIdx !== [];
        foreach (app(PluginService::class)->discover() as $manifest) {
            if (!app(AdminPortalService::class)->pluginAdminVisible($manifest)) {
                continue;
            }
            $id = (string) ($manifest['identifier'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!app(PluginService::class)->marketCatalogVisible($id)) {
                continue;
            }
            $remoteRow = $remoteIdx[$id] ?? null;
            if (is_array($remoteRow) && PluginDistributionPolicy::isRestrictedCatalogRow($remoteRow)) {
                continue;
            }
            if ($shelfOnly && !isset($remoteIdx[$id])) {
                continue;
            }
            $seen[$id] = true;
            $remoteVer = is_array($remoteRow) ? trim((string) ($remoteRow['version'] ?? '')) : '';
            $item = $this->formatMarketItem($manifest, $adminById[$id] ?? null, is_array($remoteRow) ? $remoteRow : null);
            if (!$this->matchesParamFilters($item, $paramFilters, is_array($remoteRow) ? $remoteRow : null)) {
                continue;
            }
            // 对比「已装库版本」vs Feed，禁止拿货架 version（常=remote）自比导致永不标升 / 展示同版→同版
            $compareVer = !empty($item['installed'])
                ? trim((string) ($item['installed_version'] ?? ''))
                : trim((string) ($item['version'] ?? ''));
            if ($compareVer === '') {
                $compareVer = trim((string) ($item['version'] ?? ''));
            }
            if ($remoteVer !== '' && app(PluginMarketShelfDirectory::class)->versionNewer($remoteVer, $compareVer)) {
                $item['remote_version'] = $remoteVer;
                if (!empty($item['installed'])) {
                    $item['needs_upgrade'] = true;
                    // 与 hydrate 同管：须走 primaryAction（B3 预装→purchase）
                    $item = $this->recomputePrimaryAction($item, true);
                }
            }
            if ($keyword !== '' && !$this->matchesKeyword($item, $keyword)) {
                continue;
            }
            if ($keyword !== '') {
                $item['_search_score'] = $this->keywordScore($item, $keyword);
            }
            $item['featured'] = $this->resolveMarketFeatured($id, is_array($remoteIdx[$id] ?? null) ? $remoteIdx[$id] : null);
            // 有品项投影行则标 item_ssot；否则沿用 RemoteCatalog 加载源（feed/local_file…）
            $item['catalog_source'] = (is_array($remoteRow) && (
                trim((string) ($remoteRow['ssot'] ?? '')) === 'item'
                || $remoteSource === 'item_ssot'
            ))
                ? 'item_ssot'
                : ($remoteSource !== '' ? $remoteSource : 'local');
            $items[] = $item;
        }

        foreach ($remoteIdx as $id => $remoteRow) {
            if (isset($seen[$id])) {
                continue;
            }
            if (!app(PluginService::class)->marketCatalogVisible($id)) {
                continue;
            }
            if (PluginDistributionPolicy::isRestrictedCatalogRow($remoteRow)) {
                continue;
            }
            $item = $this->formatRemoteOnlyItem($remoteRow, $adminById[$id] ?? null);
            if (!$this->matchesParamFilters($item, $paramFilters, $remoteRow)) {
                continue;
            }
            if ($keyword !== '' && !$this->matchesKeyword($item, $keyword)) {
                continue;
            }
            if ($keyword !== '') {
                $item['_search_score'] = $this->keywordScore($item, $keyword);
            }
            $item['featured'] = $this->resolveMarketFeatured($id, is_array($remoteRow) ? $remoteRow : null);
            $items[] = $item;
        }

        usort($items, static function (array $a, array $b): int {
            $fa = !empty($a['featured']) ? 0 : 1;
            $fb = !empty($b['featured']) ? 0 : 1;
            if ($fa !== $fb) {
                return $fa <=> $fb;
            }
            $sa = (int) ($a['_search_score'] ?? 0);
            $sb = (int) ($b['_search_score'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        foreach ($items as &$row) {
            unset($row['_search_score']);
        }
        unset($row);

        return $items;
    }

    /**
     * 游标分页（offset 游标；同请求内复用已构建目录）
     *
     * @return array{
     *   list:list<array<string,mixed>>,
     *   total:int,
     *   has_more:int,
     *   next_cursor:string
     * }
     */
    /**
     * @param array<string, string> $paramFilters
     * @return array{
     *   list:list<array<string,mixed>>,
     *   total:int,
     *   has_more:int,
     *   next_cursor:string
     * }
     */
    public function catalogPage(
        string $keyword = '',
        int $limit = 12,
        int $offset = 0,
        array $paramFilters = []
    ): array {
        $limit  = max(1, min(48, $limit));
        $offset = max(0, $offset);
        $paramFilters = $this->resolveActiveParamFilters($paramFilters);

        $remotePage = app(PluginMarketShelfDirectory::class)->browseRemotePage(
            $keyword,
            $limit,
            $offset,
            $paramFilters
        );
        if (is_array($remotePage)) {
            return $this->hydrateRemoteBrowsePage($remotePage);
        }

        // 无远程 browse（未配 PLATFORM_URL）：本机 discover，禁止为分页再拉全量远程
        $all    = $this->catalogCached($this->catalogCacheKey($keyword, $paramFilters));
        $total  = count($all);
        $slice  = array_slice($all, $offset, $limit);
        $next   = $offset + count($slice);

        return [
            'list'        => $slice,
            'total'       => $total,
            'has_more'    => $next < $total ? 1 : 0,
            'next_cursor' => $next < $total ? (string) $next : '',
        ];
    }

    /**
     * @param array{
     *   list:list<array<string,mixed>>,
     *   total:int,
     *   has_more:int,
     *   next_cursor:string,
     *   meta?:array<string,mixed>,
     *   source?:string
     * } $remotePage
     * @return array{list:list<array<string,mixed>>,total:int,has_more:int,next_cursor:string}
     */
    private function hydrateRemoteBrowsePage(array $remotePage): array
    {
        $adminById = [];
        foreach (app(PluginService::class)->listAdmin() as $adminItem) {
            $adminById[(string) ($adminItem['identifier'] ?? '')] = $adminItem;
        }
        $list = [];
        foreach ($remotePage['list'] as $remoteRow) {
            if (!is_array($remoteRow)) {
                continue;
            }
            $id = strtolower(trim((string) ($remoteRow['identifier'] ?? '')));
            if ($id === '' || !app(PluginService::class)->marketCatalogVisible($id)) {
                continue;
            }
            $manifest = app(PluginService::class)->readManifest($id);
            if (is_array($manifest) && app(AdminPortalService::class)->pluginAdminVisible($manifest)) {
                $item = $this->formatMarketItem($manifest, $adminById[$id] ?? null, $remoteRow);
            } else {
                $item = $this->formatRemoteOnlyItem($remoteRow, $adminById[$id] ?? null);
            }
            $remoteVer = trim((string) ($remoteRow['version'] ?? ''));
            $compareVer = !empty($item['installed'])
                ? trim((string) ($item['installed_version'] ?? ''))
                : trim((string) ($item['version'] ?? ''));
            if ($remoteVer !== '' && $compareVer !== ''
                && app(PluginMarketShelfDirectory::class)->versionNewer($remoteVer, $compareVer)
                && !empty($item['installed'])) {
                $item['remote_version'] = $remoteVer;
                $item['needs_upgrade']  = true;
                // 必须再走 primaryAction：B3 预装(install)有更新 → purchase，禁硬写 upgrade
                $item = $this->recomputePrimaryAction($item, true);
            }
            $item['featured'] = $this->resolveMarketFeatured($id, $remoteRow);
            $item['catalog_source'] = 'item_ssot';
            $list[] = $item;
        }

        return [
            'list'        => $list,
            'total'       => (int) ($remotePage['total'] ?? count($list)),
            'has_more'    => (int) ($remotePage['has_more'] ?? 0),
            'next_cursor' => (string) ($remotePage['next_cursor'] ?? ''),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalogCached(string $cacheKey): array
    {
        if (!isset(self::$catalogListCache[$cacheKey])) {
            $parts = explode("\0", $cacheKey, 2);
            $filters = [];
            $raw = (string) ($parts[1] ?? '');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $k => $v) {
                        $filters[(string) $k] = trim((string) $v);
                    }
                }
            }
            self::$catalogListCache[$cacheKey] = $this->buildCatalog($parts[0] ?? '', $filters);
        }

        return self::$catalogListCache[$cacheKey];
    }

    /**
     * @param array<string, string> $paramFilters
     */
    private function catalogCacheKey(string $keyword, array $paramFilters): string
    {
        ksort($paramFilters);

        return mb_strtolower(trim($keyword)) . "\0" . json_encode($paramFilters, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed>      $item
     * @param array<string, string>     $paramFilters
     * @param array<string, mixed>|null $remoteRow
     */
    private function matchesParamFilters(array $item, array $paramFilters, ?array $remoteRow = null): bool
    {
        if ($paramFilters === []) {
            return true;
        }
        $attrs = $this->resolveItemFilterAttrs($item, $remoteRow);
        foreach ($paramFilters as $key => $want) {
            $want = trim((string) $want);
            if ($want === '') {
                continue;
            }
            if (trim((string) ($attrs[$key] ?? '')) !== $want) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed>      $item
     * @param array<string, mixed>|null $remoteRow
     * @return array<string, string>
     */
    private function resolveItemFilterAttrs(array $item, ?array $remoteRow = null): array
    {
        $attrs = [];
        foreach ([$item['filter_attrs'] ?? null, $remoteRow['filter_attrs'] ?? null] as $bag) {
            if (!is_array($bag)) {
                continue;
            }
            foreach ($bag as $k => $v) {
                $key = trim((string) $k);
                $val = trim((string) $v);
                if ($key !== '' && $val !== '' && ($attrs[$key] ?? '') === '') {
                    $attrs[$key] = $val;
                }
            }
        }

        return app(\app\common\service\catalog\CatalogFacetPathService::class)->mergeDerivedFilterAttrs($attrs, [
            'publisher_type' => (string) ($item['publisher_type'] ?? ($remoteRow['publisher_type'] ?? '')),
            'price'          => $item['price'] ?? ($remoteRow['price'] ?? 0),
            'price_label'    => (string) ($item['price_label'] ?? ($remoteRow['price_label'] ?? '')),
            'kind'           => (string) ($item['kind'] ?? ($remoteRow['kind'] ?? '')),
            'kind_label'     => (string) ($item['kind_label'] ?? ($remoteRow['kind_label'] ?? '')),
        ]);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function matchesKeyword(array $item, string $keyword): bool
    {
        return $this->keywordScore($item, $keyword) > 0;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function keywordScore(array $item, string $keyword): int
    {
        $keyword = mb_strtolower(trim($keyword));
        if ($keyword === '') {
            return 0;
        }

        $tokens = preg_split('/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return 0;
        }
        if (count($tokens) === 1) {
            return $this->singleTokenKeywordScore($item, $tokens[0]);
        }

        $total = 0;
        foreach ($tokens as $token) {
            $score = $this->singleTokenKeywordScore($item, (string) $token);
            if ($score <= 0) {
                return 0;
            }
            $total += $score;
        }

        return min(100, (int) round($total / count($tokens)));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function singleTokenKeywordScore(array $item, string $token): int
    {
        $token = mb_strtolower(trim($token));
        if ($token === '') {
            return 0;
        }

        $id   = mb_strtolower((string) ($item['identifier'] ?? ''));
        $name = mb_strtolower((string) ($item['name'] ?? ''));
        $desc = mb_strtolower((string) ($item['description'] ?? ''));
        $tags = mb_strtolower(implode(' ', array_map('strval', (array) ($item['tags'] ?? []))));

        if ($id === $token) {
            return 100;
        }
        if ($id !== '' && str_starts_with($id, $token)) {
            return 90;
        }
        if ($name === $token) {
            return 85;
        }
        if ($name !== '' && str_starts_with($name, $token)) {
            return 75;
        }
        if ($id !== '' && str_contains($id, $token)) {
            return 60;
        }
        if ($name !== '' && str_contains($name, $token)) {
            return 50;
        }
        if ($tags !== '' && str_contains($tags, $token)) {
            return 40;
        }
        if ($desc !== '' && str_contains($desc, $token)) {
            return 20;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $commercial
     */
    public function commercialIsPaid(array $commercial): bool
    {
        $model       = (string) ($commercial['model'] ?? 'free');
        $price       = (float) ($commercial['price'] ?? 0);
        $billingType = strtolower(trim((string) ($commercial['billing_type'] ?? '')));
        if (in_array($billingType, [
            PluginSkuCatalogService::BILLING_FREE,
            PluginSkuCatalogService::BILLING_LIMITED_FREE,
            PluginSkuCatalogService::BILLING_TRIAL_TIME,
            PluginSkuCatalogService::BILLING_TRIAL_QUOTA,
        ], true)) {
            return false;
        }
        if ($billingType !== '') {
            return $price > 0;
        }

        return $model !== 'free' && $model !== 'bundled' && $price > 0;
    }

    /**
     * @param array<string, mixed>      $manifest
     * @param array<string, mixed>|null $adminRow
     * @param array<string, mixed>|null $remoteRow
     * @return array<string, mixed>
     */
    private function formatMarketItem(array $manifest, ?array $adminRow, ?array $remoteRow = null): array
    {
        $item = $this->presenter()->formatMarketItem($manifest, $adminRow, $remoteRow);
        $item['filter_attrs'] = $this->resolveItemFilterAttrs($item, is_array($remoteRow) ? $remoteRow : null);

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function recomputePrimaryAction(array $item, bool $needsUpgrade): array
    {
        return $this->presenter()->recomputePrimaryAction($item, $needsUpgrade);
    }

    /**
     * @param array<string, mixed>      $remoteRow
     * @param array<string, mixed>|null $adminRow
     * @return array<string, mixed>
     */
    private function formatRemoteOnlyItem(array $remoteRow, ?array $adminRow): array
    {
        $item = $this->presenter()->formatRemoteOnlyItem($remoteRow, $adminRow);
        $item['filter_attrs'] = $this->resolveItemFilterAttrs($item, $remoteRow);

        return $item;
    }
    /**
     * @return list<array<string, mixed>>
     */
    public function listUpdates(): array
    {
        return app(PluginMarketShelfDirectory::class)->checkUpdates();
    }

    /**
     * @return array{total:int,expiring:int,expired:int}
     */
    public function entitlementStats(): array
    {
        $rows = app(EntitlementService::class)->listEntitledAdmin();
        $expiring = 0;
        $expired  = 0;
        $now      = time();
        foreach ($rows as $row) {
            $exp = trim((string) ($row['entitlement']['expire_at'] ?? ''));
            if ($exp === '') {
                continue;
            }
            $ts = strtotime($exp);
            if ($ts === false) {
                continue;
            }
            if ($ts < $now) {
                $expired++;
            } elseif ($ts - $now < 7 * 86400) {
                $expiring++;
            }
        }

        return ['total' => count($rows), 'expiring' => $expiring, 'expired' => $expired];
    }


    private function presenter(): PluginMarketCardPresenter
    {
        return app(PluginMarketCardPresenter::class);
    }
    /**
     * 品项 flags.market_featured 优先；无官方品项时读 catalog.json 投影
     *
     * @param array<string, mixed>|null $remoteRow
     */
    private function resolveMarketFeatured(string $identifier, ?array $remoteRow): bool
    {
        $fromItem = PluginOfficialProduct::dispatch('plugin_market_featured', [
            'identifier' => $identifier,
        ], null);
        if ($fromItem !== null) {
            return (bool) $fromItem;
        }

        return !empty($remoteRow['featured']);
    }
}
