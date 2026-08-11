<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use think\facade\Cache;

/** 超级搜索结果短缓存（P3/P4） */
final class SmartSearchCacheService
{

    public function __construct(
        private readonly SmartSearchConfigService $smartSearch,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function remember(string $keyword, callable $resolver): array
    {
        $ttl = $this->smartSearch->cacheTtl();
        if ($ttl < 1) {
            return $resolver();
        }
        $key = 'smart_search:' . hash('sha256', mb_strtolower(trim($keyword)));
        $hit = Cache::get($key);
        if (is_array($hit)) {
            $hit['_cache'] = 1;

            return $hit;
        }
        $data = $resolver();
        if (is_array($data)) {
            Cache::set($key, $data, $ttl);
        }

        return is_array($data) ? $data : [];
    }
}
