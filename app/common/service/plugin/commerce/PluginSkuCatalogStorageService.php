<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);


namespace app\common\service\plugin\commerce;

use app\common\service\plugin\extension\PluginOfficialProduct;


use app\common\support\AppTime;
use app\common\support\ServiceResult;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\service\plugin\market\PluginMarketShelfDirectory;
use app\common\service\plugin\market\PluginMarketCatalogSignatureService;
use app\common\service\plugin\PluginService;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;

final class PluginSkuCatalogStorageService
{
    public function __construct(
        private readonly PluginService $pluginService,
        private readonly PluginSkuCatalogService $pluginSkuCatalog,
        private readonly PluginMarketShelfDirectory $pluginMarketRemoteCatalog,
        private readonly PluginMarketCatalogSignatureService $pluginMarketCatalogSignature,
    ) {
    }

    /**
     * 冷备只落站点 public/static/market（禁 tools/devtools 影子双写 · R16）
     *
     * @return list<string>
     */
    public function catalogPaths(): array
    {
        $root = rtrim(ProjectPaths::root(), '/\\');

        return [
            $root . '/public/static/market/catalog.json',
        ];
    }

    public function primaryCatalogPath(): string
    {
        foreach ($this->catalogPaths() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $this->catalogPaths()[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadCatalog(): ?array
    {
        $path = $this->primaryCatalogPath();
        if (!is_file($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }

    /**
     * @param array<string, mixed> $policy
     * @return ServiceResult
     */
    public function updatePluginPolicy(string $identifier, array $policy): ServiceResult
    {
        $identifier = strtolower(trim($identifier));

        return $this->mutateCatalogUnderLock(function (array $catalog) use ($identifier, $policy): array|ServiceResult {
            $plugins = is_array($catalog['plugins'] ?? null) ? $catalog['plugins'] : [];
            $found   = false;
            foreach ($plugins as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (strtolower(trim((string) ($row['identifier'] ?? ''))) !== $identifier) {
                    continue;
                }
                $existingPolicy = is_array($row['policy'] ?? null) ? $row['policy'] : [];
                $row['policy']  = array_merge($existingPolicy, $policy);
                unset($row['skus'], $row['meter'], $row['commercial_profile']);
                $plugins[$idx] = $row;
                $found         = true;
                break;
            }

            if (!$found) {
                $manifest = $this->pluginService->readManifest($identifier);
                if ($manifest === null) {
                    return ServiceResult::fail('插件不存在');
                }
                $plugins[] = [
                    'identifier' => $identifier,
                    'policy'     => $policy,
                ];
            }

            $catalog['plugins']    = array_values($plugins);
            $catalog['updated_at'] = AppTime::format('c');

            return $catalog;
        });
    }

    /**
     * 品项/catalog_snapshot 变更后，按 identifier 刷新 market catalog 条目（保留既有 package_url）
     *
     * @param list<string> $identifiers
     * @return ServiceResult
     */
    public function mergePluginCatalogEntries(array $identifiers): ServiceResult
    {
        $identifiers = array_values(array_unique(array_filter(array_map(
            static fn (string $id): string => strtolower(trim($id)),
            $identifiers
        ))));
        $retired = $this->pluginService->catalogRetiredIdentifiers();
        if ($retired !== []) {
            $identifiers = array_values(array_filter(
                $identifiers,
                static fn (string $id): bool => !in_array($id, $retired, true)
            ));
        }
        if ($identifiers === []) {
            return ServiceResult::ok(null, 'skip');
        }

        $save = $this->mutateCatalogUnderLock(function (array $catalog) use ($identifiers): array {
            /** @var array<string, array<string, mixed>> $byId */
            $byId = [];
            foreach (is_array($catalog['plugins'] ?? null) ? $catalog['plugins'] : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = strtolower(trim((string) ($row['identifier'] ?? '')));
                if ($id !== '') {
                    $byId[$id] = $row;
                }
            }

            $skuCatalog = $this->pluginSkuCatalog;
            foreach ($identifiers as $identifier) {
                $manifest = $this->pluginService->readManifest($identifier);
                if ($manifest === null || empty($manifest['_manifest_valid'])) {
                    continue;
                }
                $existing   = $byId[$identifier] ?? [];
                $packageUrl = trim((string) ($existing['package_url'] ?? ''));
                $entry      = $skuCatalog->buildCatalogPluginEntry($identifier, $manifest, [
                    'package_url' => $packageUrl,
                ]);
                if ($packageUrl !== '') {
                    $entry['package_url'] = $packageUrl;
                }
                $featured = PluginOfficialProduct::dispatch('plugin_market_featured', [
                    'identifier' => $identifier,
                ], null);
                if ($featured === true) {
                    $entry['featured'] = true;
                } else {
                    unset($entry['featured']);
                }
                $byId[$identifier] = array_merge($existing, $entry);
            }

            $merged = array_values($byId);
            usort($merged, static fn (array $a, array $b): int => strcmp(
                (string) ($a['identifier'] ?? ''),
                (string) ($b['identifier'] ?? '')
            ));

            $catalog['plugins']    = $merged;
            $catalog['updated_at'] = AppTime::format('c');

            return $catalog;
        });

        return $save;
    }

    /**
     * 从发布目录移除插件（品项下架 / retired · ITEM-SSOT-002）
     *
     * @param list<string> $identifiers
     */
    public function removeIdentifiersFromCatalog(array $identifiers): ServiceResult
    {
        $identifiers = array_values(array_unique(array_filter(array_map(
            static fn (string $id): string => strtolower(trim($id)),
            $identifiers
        ))));
        if ($identifiers === []) {
            return ServiceResult::ok(null, 'skip');
        }

        $save = $this->mutateCatalogUnderLock(function (array $catalog) use ($identifiers): array {
            $plugins = [];
            $removed = 0;
            foreach (is_array($catalog['plugins'] ?? null) ? $catalog['plugins'] : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = strtolower(trim((string) ($row['identifier'] ?? '')));
                if ($id !== '' && in_array($id, $identifiers, true)) {
                    $removed++;
                    continue;
                }
                $plugins[] = $row;
            }
            if ($removed < 1) {
                return $catalog;
            }
            $catalog['plugins']    = array_values($plugins);
            $catalog['updated_at'] = AppTime::format('c');

            return $catalog;
        });

        return $save;
    }

    /**
     * 品项商业字段盖到 catalog 条目（名/简介/kind；包 URL/version 保留原值）
     *
     * @param array<string, mixed> $overlay name/description/kind/kind_label/skus/...
     */
    public function overlayPluginCatalogEntry(string $identifier, array $overlay): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('identifier 无效');
        }
        if (in_array($identifier, $this->pluginService->catalogRetiredIdentifiers(), true)) {
            return $this->removeIdentifiersFromCatalog([$identifier]);
        }

        $save = $this->mutateCatalogUnderLock(function (array $catalog) use ($identifier, $overlay): array {
            $plugins = is_array($catalog['plugins'] ?? null) ? $catalog['plugins'] : [];
            $found   = false;
            foreach ($plugins as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (strtolower(trim((string) ($row['identifier'] ?? ''))) !== $identifier) {
                    continue;
                }
                $found = true;
                $next  = $row;
                foreach (['name', 'description', 'kind', 'kind_label', 'publisher_type', 'commercial_profile'] as $key) {
                    if (array_key_exists($key, $overlay) && trim((string) $overlay[$key]) !== '') {
                        $next[$key] = $overlay[$key];
                    }
                }
                if (array_key_exists('publisher_type', $overlay)
                    && strtolower(trim((string) $overlay['publisher_type'])) === 'official') {
                    unset($next['host_listing_id'], $next['developer_member_id']);
                    if (($next['commercial_profile'] ?? '') === 'developer_listing') {
                        unset($next['commercial_profile']);
                    }
                }
                if (is_array($overlay['skus'] ?? null) && $overlay['skus'] !== []) {
                    $next['skus'] = $overlay['skus'];
                }
                if (is_array($overlay['commercial'] ?? null)) {
                    $next['commercial'] = array_merge(
                        is_array($next['commercial'] ?? null) ? $next['commercial'] : [],
                        $overlay['commercial']
                    );
                }
                if (isset($overlay['item_id'])) {
                    $next['item_id'] = (int) $overlay['item_id'];
                }
                $plugins[$idx] = $next;
                break;
            }
            if (!$found) {
                // 尚无条目：有本地 manifest 则建底稿再盖品项字段；否则仅品项无法装包，跳过
                $manifest = $this->pluginService->readManifest($identifier);
                if ($manifest === null || empty($manifest['_manifest_valid'])) {
                    return $catalog;
                }
                $entry = $this->pluginSkuCatalog->buildCatalogPluginEntry($identifier, $manifest, [
                    'package_url' => '',
                ]);
                foreach (['name', 'description', 'kind', 'kind_label'] as $key) {
                    if (array_key_exists($key, $overlay) && trim((string) $overlay[$key]) !== '') {
                        $entry[$key] = $overlay[$key];
                    }
                }
                if (is_array($overlay['skus'] ?? null) && $overlay['skus'] !== []) {
                    $entry['skus'] = $overlay['skus'];
                }
                if (isset($overlay['item_id'])) {
                    $entry['item_id'] = (int) $overlay['item_id'];
                }
                $plugins[] = $entry;
            }

            usort($plugins, static fn (array $a, array $b): int => strcmp(
                (string) ($a['identifier'] ?? ''),
                (string) ($b['identifier'] ?? '')
            ));
            $catalog['plugins']    = array_values($plugins);
            $catalog['updated_at'] = AppTime::format('c');

            return $catalog;
        });

        return $save;
    }

    /**
     * @param array<string, mixed> $catalog
     * @return ServiceResult
     */
    public function saveCatalog(array $catalog): ServiceResult
    {
        return $this->mutateCatalogUnderLock(static fn (array $_ignored) => $catalog);
    }

    /**
     * 主 catalog 排他锁内读→改→多路径写，避免 TOCTOU 覆盖。
     *
     * @param callable(array<string, mixed>): (array<string, mixed>|ServiceResult) $mutator
     */
    private function mutateCatalogUnderLock(callable $mutator): ServiceResult
    {
        $path = $this->primaryCatalogPath();
        $dir  = dirname($path);
        if (!is_dir($dir) && !LocalFile::mkdirIfMissing($dir)) {
            return ServiceResult::fail('无法创建目录：' . $dir);
        }

        // Windows：不可对已 flock 的 catalog.json 再 file_put_contents（Permission denied，甚至截成 0 字节）
        $lockPath = $path . '.lock';
        $handle   = fopen($lockPath, 'c+');
        if ($handle === false) {
            return ServiceResult::fail('无法打开 catalog 锁：' . $lockPath);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return ServiceResult::fail('catalog 繁忙，请稍后重试');
            }

            $raw = is_file($path) ? (string) file_get_contents($path) : '';
            $existing = $raw !== '' ? json_decode($raw, true) : null;
            $catalog = is_array($existing) ? $existing : [
                'updated_at'      => AppTime::format('c'),
                'edition'         => 'community',
                'catalog_version' => (int) config('plugin.sku_defaults.catalog_version', 2),
                'plugins'         => [],
            ];

            $next = $mutator($catalog);
            if ($next instanceof ServiceResult) {
                return $next;
            }
            if (!is_array($next)) {
                return ServiceResult::fail('catalog 变更回调返回无效');
            }

            return $this->afterCatalogPersisted(
                $this->persistCatalogLocked($next, is_array($existing) ? $existing : null)
            );
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** 落盘成功后失效 Remote/列表缓存（含 saveCatalog；禁各入口各自 invalidate） */
    private function afterCatalogPersisted(ServiceResult $save): ServiceResult
    {
        if ($save->isOk() && $save->message() !== 'catalog 无变更，跳过写入') {
            $this->pluginMarketRemoteCatalog->invalidateCache();
        }

        return $save;
    }

    /**
     * @param array<string, mixed>      $catalog
     * @param array<string, mixed>|null $existingUnderLock
     */
    private function persistCatalogLocked(array $catalog, ?array $existingUnderLock): ServiceResult
    {
        $catalog = $this->stripRetiredCatalogEntries($catalog);
        $existing = $existingUnderLock;
        if ($existing !== null && $this->catalogSemanticFingerprint($catalog) === $this->catalogSemanticFingerprint($existing)) {
            return ServiceResult::ok(null, 'catalog 无变更，跳过写入');
        }

        $catalog = $this->pluginMarketCatalogSignature->attachCatalogSignature($catalog);
        $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return ServiceResult::fail('catalog JSON 编码失败');
        }
        $payload = $json . "\n";
        $paths   = $this->catalogPaths();
        $primary = $paths[0] ?? '';
        foreach ($paths as $idx => $path) {
            $dir       = dirname($path);
            $isPrimary = $path === $primary || $idx === 0;
            if (!is_dir($dir)) {
                // 部署 lane（b/test）常无 /devtools，影子 catalog 可跳过
                if (!$isPrimary) {
                    continue;
                }
                if (!LocalFile::mkdirIfMissing($dir)) {
                    return ServiceResult::fail('无法创建目录：' . $dir);
                }
            }
            if (!LocalFile::putContents($path, $payload)) {
                if (!$isPrimary) {
                    continue;
                }

                return ServiceResult::fail('无法写入：' . $path);
            }
        }

        return ServiceResult::ok(null, 'catalog 已更新（定价以品项 DB 为准）');
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    private function stripRetiredCatalogEntries(array $catalog): array
    {
        $retired = $this->pluginService->catalogRetiredIdentifiers();
        if ($retired === [] || !is_array($catalog['plugins'] ?? null)) {
            return $catalog;
        }
        $catalog['plugins'] = array_values(array_filter(
            $catalog['plugins'],
            static function ($row) use ($retired): bool {
                if (!is_array($row)) {
                    return false;
                }
                $id = strtolower(trim((string) ($row['identifier'] ?? '')));

                return $id !== '' && !in_array($id, $retired, true);
            }
        ));

        return $catalog;
    }

    /**
     * 比较 catalog 语义内容（不含 updated_at / signature），用于无变更时跳过落盘。
     *
     * @param array<string, mixed> $catalog
     */
    private function catalogSemanticFingerprint(array $catalog): string
    {
        $payload = $catalog;
        unset($payload['updated_at'], $payload['signature'], $payload['signature_schema']);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }
}
