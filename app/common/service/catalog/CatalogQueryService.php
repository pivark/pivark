<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\catalog;
use app\common\service\catalog\CatalogListCacheService;
use app\common\service\catalog\CatalogQueryRegistry;

use app\common\support\catalog\CatalogQueryParams;
use think\facade\Cache;

/** 跨域 Catalog 查询门面（列表 + 筛选元数据 + 缓存） */
final class CatalogQueryService
{

    public function __construct(
        private readonly CatalogQueryRegistry $catalogQueryRegistry,
        private readonly CatalogListCacheService $catalogListCacheService,
    ) {
    }

    /**
     * @param array<string, mixed> $rawParams
     * @return array{list:list<array<string,mixed>>,filters:list<array<string,mixed>>,total:int,page:int,limit:int,next_cursor?:string,cursor?:string,has_more:int,domain:string}
     */
    public function catalog(string $domain, array $rawParams = []): array
    {
        $handler = $this->catalogQueryRegistry->get($domain);
        if ($handler === null) {
            return $this->emptyCatalog($domain, $rawParams, '未知业务域');
        }
        if (!$handler->isAvailable()) {
            return $this->emptyCatalog($domain, $rawParams, '模块未安装或未启用');
        }

        $params = CatalogQueryParams::parse($rawParams);
        if (($params['filters_only'] ?? 0) === 1) {
            return [
                'domain'      => $domain,
                'list'        => [],
                'filters'     => $handler->filterOptions($params),
                'total'       => 0,
                'page'        => 1,
                'limit'       => (int) ($params['limit'] ?? 20),
                'next_cursor' => '',
                'cursor'      => '',
                'has_more'    => 0,
            ];
        }

        $listResult = $this->catalogListCacheService->remember($domain, $params, static fn (): array => $handler->list($params));
        $filters    = $handler->filterOptions($params);

        return [
            'domain'      => $domain,
            'list'        => $listResult['list'],
            'filters'     => $filters,
            'total'       => (int) ($listResult['total'] ?? 0),
            'page'        => (int) ($listResult['page'] ?? 1),
            'limit'       => (int) ($listResult['limit'] ?? 20),
            'next_cursor' => (string) ($listResult['next_cursor'] ?? ''),
            'cursor'      => (string) ($listResult['cursor'] ?? ''),
            'has_more'    => ($listResult['next_cursor'] ?? '') !== '' ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $rawParams
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int,next_cursor?:string,cursor?:string}
     */
    public function list(string $domain, array $rawParams = []): array
    {
        $handler = $this->catalogQueryRegistry->get($domain);
        if ($handler === null || !$handler->isAvailable()) {
            $params = CatalogQueryParams::parse($rawParams);

            return $this->emptyList($params);
        }
        $params = CatalogQueryParams::parse($rawParams);

        return $this->catalogListCacheService->remember($domain, $params, static fn (): array => $handler->list($params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function emptyList(array $params): array
    {
        return [
            'list'  => [],
            'total' => 0,
            'page'  => max(1, (int) ($params['page'] ?? 1)),
            'limit' => min(max((int) ($params['limit'] ?? 20), 1), 100),
        ];
    }

    /**
     * @param array<string, mixed> $rawParams
     * @return array{list:list<array<string,mixed>>,filters:list<array<string,mixed>>,total:int,page:int,limit:int,has_more:int,domain:string}
     */
    private function emptyCatalog(string $domain, array $rawParams, string $reason): array
    {
        $params = CatalogQueryParams::parse($rawParams);
        $empty  = $this->emptyList($params);

        return [
            'domain'      => $domain,
            'list'        => [],
            'filters'     => [],
            'total'       => 0,
            'page'        => $empty['page'],
            'limit'       => $empty['limit'],
            'next_cursor' => '',
            'cursor'      => '',
            'has_more'    => 0,
            'unavailable' => $reason,
        ];
    }

    public function bumpCache(?string $domain = null): void
    {
        if ($domain === null || $domain === '') {
            Cache::inc($this->genKey('__all__'));
            return;
        }
        Cache::inc($this->genKey(strtolower(trim($domain))));
    }

    private function genKey(string $domain): string
    {
        return 'pv_catalog_list_gen_' . preg_replace('/[^a-z0-9_\-]/i', '_', $domain);
    }

    public function cacheGeneration(string $domain): int
    {
        $g = Cache::get($this->genKey($domain));

        return is_numeric($g) ? max(1, (int) $g) : 1;
    }
}
