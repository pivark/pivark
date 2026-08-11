<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\catalog;

use think\facade\Cache;

/** 按业务域隔离的 list 短缓存 */
final class CatalogListCacheService
{

    public function __construct()
    {
    }

    public function ttl(): int
    {
        return max(0, (int) config('pivark.catalog_list_cache_ttl', config('pivark.item_list_cache_ttl', 90)));
    }

    public function enabled(string $domain = ''): bool
    {
        if ($domain === 'items') {
            return false;
        }

        return $this->ttl() > 0;
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(): array<string, mixed> $loader
     * @return array<string, mixed>
     */
    public function remember(string $domain, array $params, callable $loader): array
    {
        if (!$this->enabled($domain)) {
            return $loader();
        }
        $key = $this->cacheKey($domain, $params);
        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit;
        }
        $value = $loader();
        if ($value !== []) {
            Cache::set($key, $value, $this->ttl());
        }

        return $value;
    }

    public function bump(?string $domain = null): void
    {
        app(CatalogQueryService::class)->bumpCache($domain);
    }

    /** @param array<string, mixed> $params */
    private function cacheKey(string $domain, array $params): string
    {
        $norm = ['__domain' => strtolower(trim($domain))];
        foreach ($params as $k => $v) {
            if (!is_string($k) && !is_int($k)) {
                continue;
            }
            $key = (string) $k;
            if ($v === null || $v === '') {
                continue;
            }
            $norm[$key] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        ksort($norm);
        $gen = app(CatalogQueryService::class)->cacheGeneration($norm['__domain']);

        return 'pv_catalog_list_g' . $gen . '_' . hash('sha256', json_encode($norm, JSON_UNESCAPED_UNICODE));
    }
}
