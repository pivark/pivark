<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\infra\PaginationService;
use app\common\service\item\ItemPublicGateway;
use think\facade\Request;

/**
 * 前台产品目录列表页：注入 product_list + pagination_*（与文档列表同一套 PaginationService）
 */
final class ProductCatalogListPageService
{
    /** @deprecated 使用 PaginationService::frontListPageSize()；保留常量避免站外误引用断裂 */
    public const PAGE_SIZE = 12;

    public function __construct(
        private readonly ItemPublicGateway $items,
        private readonly PaginationService $pagination,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listPageVars(string $tagSlug = '', ?int $page = null, int $navId = 0): array
    {
        $tagSlug = trim($tagSlug);
        $navId   = max(0, $navId);
        $query   = Request::get();
        $query   = is_array($query) ? $query : [];
        $page    = $page !== null && $page > 0
            ? max(1, $page)
            : $this->pagination->resolveListPage([], [], $query);

        $params = [
            'page'  => $page,
            'limit' => $this->pagination->frontListPageSize(),
        ];
        if ($navId > 0) {
            $params['nav_id'] = $navId;
        }

        foreach ($this->pagination->sanitizeListQuery($query) as $key => $val) {
            if ($key === 'page') {
                continue;
            }
            if (($key === 'keyword' || $key === 'q') && $val !== '') {
                $params['keyword'] = $val;
                continue;
            }
            if ($key === 'tag' && $tagSlug === '' && $val !== '') {
                $tagSlug = $val;
                continue;
            }
            if ($key === 'nav_id' && (int) $val > 0 && $navId < 1) {
                $params['nav_id'] = (int) $val;
                continue;
            }
            if (str_starts_with($key, 'filter_') && $val !== '') {
                $params[$key] = $val;
            }
        }

        // 栏目只认 nav_id；Tag 仅聚合（禁 slug→栏目桥）
        $navId = max(0, (int) ($params['nav_id'] ?? 0));
        if ($navId > 0) {
            $params['nav_id'] = $navId;
            unset($params['tag']);
        } elseif ($tagSlug !== '') {
            $params['tag'] = $tagSlug;
        }

        $result = $this->items->listPublic($params);
        $page   = max(1, (int) ($result['page'] ?? $page));
        $total  = (int) ($result['total'] ?? 0);
        $limit  = max(1, (int) ($result['limit'] ?? $this->pagination->frontListPageSize()));
        $list   = is_array($result['list'] ?? null) ? $result['list'] : [];

        $tagForUrl = $tagSlug;
        $pageUrl   = function (int $p) use ($query, $tagForUrl): string {
            return $this->pagination->buildFrontListPageUrl($p, $query, ['tag' => $tagForUrl]);
        };

        return array_merge([
            'product_list'  => $list,
            'page'          => $page,
            'total'         => $total,
            'limit'         => $limit,
            'tag_slug'      => $tagSlug,
            'channel_nav_id'=> $navId,
        ], $this->pagination->build($page, $total, $limit, $pageUrl));
    }
}
