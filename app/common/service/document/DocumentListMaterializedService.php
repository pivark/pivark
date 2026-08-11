<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document;

use app\common\service\infra\FrontCacheInvalidator;
use think\facade\Cache;

final class DocumentListMaterializedService
{

    public function __construct(
        private readonly FrontCacheInvalidator $cacheInvalidator,
    ) {
    }

    private const GENERATION_KEY = 'pv_doc_list_mat_generation';

    private const PREFIX = 'pv_doc_list_mat_';

    public function listGeneration(): int
    {
        $gen = Cache::get(self::GENERATION_KEY);

        return max(1, (int) ($gen === null || $gen === false ? 1 : $gen));
    }

    public function bumpGeneration(): int
    {
        $next = $this->listGeneration() + 1;
        Cache::set(self::GENERATION_KEY, $next, 86400 * 365);

        return $next;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array>,total:int,page:int,limit:int}|null
     */
    public function get(array $params): ?array
    {
        if (!$this->canMaterialize($params)) {
            return null;
        }
        $hit = Cache::get($this->cacheKey($params));
        if (!is_array($hit) || !isset($hit['list'])) {
            return null;
        }

        return $hit;
    }

    /**
     * @param array<string, mixed>                              $params
     * @param array{list:list<array>,total:int,page:int,limit:int} $result
     */
    public function set(array $params, array $result): void
    {
        if (!$this->canMaterialize($params)) {
            return;
        }
        $ttl = max(60, (int) config('pivark.doc_list_materialized_ttl', 86400));
        Cache::set($this->cacheKey($params), $result, $ttl);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function canMaterialize(array $params): bool
    {
        if (trim((string) ($params['keyword'] ?? '')) !== '') {
            return false;
        }
        if ((int) ($params['id'] ?? 0) > 0) {
            return false;
        }
        if (trim((string) ($params['ids'] ?? '')) !== '') {
            return false;
        }
        if (trim((string) ($params['attr'] ?? '')) !== '') {
            return false;
        }
        // 仅匿名可物化：会员 read_perm 与游客不同，禁串缓存
        if (empty($params['_materialize_guest'])) {
            return false;
        }
        // 频道列表（带 tags）优先；无 tags 的全站列表也允许（首页等）
        return true;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function cacheKey(array $params): string
    {
        $norm = $params;
        unset($norm['_materialize_guest']);
        ksort($norm);

        $tg = 0;
        if (class_exists(\app\common\support\SiteDomainContext::class)) {
            $tg = (int) \app\common\support\SiteDomainContext::tagGroupId();
        }

        return self::PREFIX . 'g' . $this->listGeneration()
            . '_f' . $this->cacheInvalidator->generation()
            . '_tg' . $tg
            . '_' . hash('sha256', json_encode($norm, JSON_UNESCAPED_UNICODE));
    }
}
