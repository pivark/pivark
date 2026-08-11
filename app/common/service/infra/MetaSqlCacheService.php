<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;
use app\common\support\OpsLog;

use think\facade\Cache;

final class MetaSqlCacheService
{

    public function __construct(
        private readonly FrontCacheInvalidator $cacheInvalidator,
    ) {
    }

    private const PREFIX = 'pv_meta_sql_';

    /** @var list<string> */
    private const FRONT_META_TAGS = [
        'config_all',
        'site_nav_rows',
        'site_pages_pub',
        'site_pages_pub_v2',
        'site_ad_slots_pub',
        'tag_url_vars',
        'entitlements_active_map',
        'tag_slug_id_map',
        'tag_public_index_v1',
        'tag_public_index_v2',
    ];

    public function ttl(): int
    {
        return max(0, (int) config('pivark.meta_sql_cache_ttl', 86400));
    }

    public function enabled(): bool
    {
        return $this->ttl() > 0;
    }

    /**
     * @template T
     * @param callable(): T $loader
     * @return T
     */
    public function remember(string $tag, callable $loader)
    {
        if (!$this->enabled()) {
            return $loader();
        }

        $key = $this->key($tag);
        $hit = Cache::get($key);
        if ($hit !== null && $hit !== false) {
            return $hit;
        }

        $value = $loader();
        Cache::set($key, $value, $this->ttl());

        return $value;
    }

    public function forget(string $tag): void
    {
        Cache::delete($this->key($tag));
    }

    /** 内容/配置变更时清空前台元数据缓存 */
    public function clearFrontMeta(): void
    {
        foreach (self::FRONT_META_TAGS as $tag) {
            $this->forget($tag);
        }
        for ($limit = 1; $limit <= 100; $limit++) {
            $this->forget('site_links_l' . $limit);
        }
        for ($g = 0; $g <= 32; $g++) {
            $this->forget('tag_nav_grouped_g' . $g);
        }
    }

    /** 缓存驱动切换时：file + redis 双清，避免旧驱动残留 */
    public function clearFrontMetaAllStores(): void
    {
        $this->clearFrontMeta();
        $gen = $this->cacheInvalidator->generation();
        foreach (['file', 'redis'] as $storeName) {
            $this->clearFrontMetaOnStore($storeName, $gen);
        }
    }

    private function clearFrontMetaOnStore(string $storeName, int $generation): void
    {
        try {
            $store = Cache::store($storeName);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('meta_sql_cache_clear_store_unavailable', [
                'store' => $storeName,
                'msg'   => $e->getMessage(),
            ]);

            return;
        }
        for ($g = max(1, $generation - 1); $g <= $generation + 1; $g++) {
            foreach (self::FRONT_META_TAGS as $tag) {
                $store->delete(self::PREFIX . 'g' . $g . '_' . $tag);
            }
            for ($limit = 1; $limit <= 100; $limit++) {
                $store->delete(self::PREFIX . 'g' . $g . '_site_links_l' . $limit);
            }
            for ($group = 0; $group <= 32; $group++) {
                $store->delete(self::PREFIX . 'g' . $g . '_tag_nav_grouped_g' . $group);
            }
        }
    }

    private function key(string $tag): string
    {
        $tag = preg_replace('/[^a-z0-9_\-]/i', '_', $tag) ?? $tag;

        return self::PREFIX . 'g' . $this->cacheInvalidator->generation() . '_' . $tag;
    }
}
