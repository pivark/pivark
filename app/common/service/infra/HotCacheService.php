<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use think\facade\Cache;

final class HotCacheService
{

    public function __construct(
        private readonly FrontCacheInvalidator $cacheInvalidator,
    ) {
    }

    private const PREFIX = 'pv_hot_';

    public function ttl(): int
    {
        return max(0, (int) config('pivark.hot_data_cache_ttl', 300));
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

    public function forgetPrefix(string $prefix): void
    {
        $this->forget($prefix);
        for ($page = 1; $page <= 50; $page++) {
            for ($limit = 10; $limit <= 100; $limit += 10) {
                $this->forget($prefix . '_p' . $page . '_l' . $limit);
            }
        }
    }

    private function key(string $tag): string
    {
        $tag = preg_replace('/[^a-z0-9_\-]/i', '_', $tag) ?? $tag;

        return self::PREFIX . 'g' . $this->cacheInvalidator->generation() . '_' . $tag;
    }
}
