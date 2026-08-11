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

/** 品项 listPublic 结果短缓存（筛选/分页/query 指纹） */
final class ItemListCacheService
{

    private const GEN_KEY = 'pv_item_list_gen';

    public function ttl(): int
    {
        return max(0, (int) config('pivark.item_list_cache_ttl', 90));
    }

    public function enabled(): bool
    {
        return $this->ttl() > 0;
    }

    public function generation(): int
    {
        $gen = Cache::get(self::GEN_KEY);

        return is_numeric($gen) ? (int) $gen : 1;
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
     * @param array<string, mixed> $params
     * @param callable(): array<string, mixed> $loader
     * @return array<string, mixed>
     */
    public function remember(array $params, callable $loader): array
    {
        if (!$this->enabled()) {
            return $loader();
        }
        $key = $this->cacheKey($params);
        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit;
        }
        $value = $loader();
        $list  = $value['list'] ?? null;
        if (is_array($list) && $list !== []) {
            Cache::set($key, $value, $this->ttl());
        }

        return $value;
    }

    /** @param array<string, mixed> $params */
    private function cacheKey(array $params): string
    {
        $norm = [];
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
        $gen = Cache::get(self::GEN_KEY);

        return 'pv_item_list_g' . (is_numeric($gen) ? (int) $gen : 1)
            . '_' . hash('sha256', json_encode($norm, JSON_UNESCAPED_UNICODE));
    }
}
