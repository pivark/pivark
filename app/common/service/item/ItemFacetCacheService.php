<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use think\facade\Cache;

/** 品项 Facet 上下文计数短缓存（独立于 meta_sql，按 generation 失效） */
final class ItemFacetCacheService
{

    private const GEN_KEY = 'pv_item_facet_gen';

    public function ttl(): int
    {
        return max(0, (int) config('pivark.item_facet_cache_ttl', 300));
    }

    public function enabled(): bool
    {
        return $this->ttl() > 0;
    }

    public function generation(): int
    {
        $g = Cache::get(self::GEN_KEY);

        return is_numeric($g) ? max(1, (int) $g) : 1;
    }

    public function bump(): void
    {
        if (!Cache::has(self::GEN_KEY)) {
            Cache::set(self::GEN_KEY, 2, 0);

            return;
        }
        Cache::inc(self::GEN_KEY);
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
        $key = 'pv_item_facet_g' . $this->generation() . '_' . preg_replace('/[^a-z0-9_\-]/i', '_', $tag);
        $hit = Cache::get($key);
        if ($hit !== null && $hit !== false) {
            return $hit;
        }
        $value = $loader();
        Cache::set($key, $value, $this->ttl());

        return $value;
    }
}
