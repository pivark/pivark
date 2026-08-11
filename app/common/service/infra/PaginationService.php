<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\support\QueryLimit;
use app\common\service\template\DocumentListTemplateTagService;
use app\common\service\template\PaginationTemplateTagService;
use app\common\support\SiteUrl;
use think\facade\Request;

/** 为模板生成分页导航变量 */
class PaginationService
{

    public function __construct(
        private readonly PaginationTemplateTagService $paginationTemplateTagService,
    ) {
    }

    /** 前台频道/标签列表默认每页条数（模板未写 row 时的回退；运行时请用 frontListPageSize($tpl)） */
    public const FRONT_LIST_PAGE_SIZE = QueryLimit::FRONT_LIST;

    public const FRONT_LIST_PAGE_SIZE_MIN = 1;

    public const FRONT_LIST_PAGE_SIZE_MAX = 100;

    /**
     * 前台列表每页条数：读列表模板首个 `{pv:list row=|loop=|limit=}`，夹紧后回退默认 12。
     *
     * @param string|null $tpl 逻辑模板名（如 list_document_news），频道列表务必传入
     */
    public function frontListPageSize(?string $tpl = null): int
    {
        if ($tpl !== null && trim($tpl) !== '') {
            $fromTpl = app(DocumentListTemplateTagService::class)->resolvePageSizeFromTpl($tpl);
            if ($fromTpl !== null) {
                return $this->clampFrontListPageSize($fromTpl);
            }
        }

        return self::FRONT_LIST_PAGE_SIZE;
    }

    public function clampFrontListPageSize(int $raw): int
    {
        if ($raw < self::FRONT_LIST_PAGE_SIZE_MIN) {
            return self::FRONT_LIST_PAGE_SIZE;
        }

        return min(self::FRONT_LIST_PAGE_SIZE_MAX, $raw);
    }

    /**
     * @param callable(int): string $pageUrl 页码 → URL
     * @return array<string, mixed>
     */
    public function build(int $page, int $total, int $limit, callable $pageUrl): array
    {
        $page       = max(1, $page);
        $limit      = max(1, $limit);
        $totalPages = max(1, (int) ceil($total / $limit));
        $page       = min($page, $totalPages);

        $items = [];
        $window = 2;
        $start  = max(1, $page - $window);
        $end    = min($totalPages, $page + $window);
        if ($start > 1) {
            $items[] = ['page' => 1, 'url' => $pageUrl(1), 'active' => 0, 'label' => '1'];
            if ($start > 2) {
                $items[] = ['page' => 0, 'url' => '', 'active' => 0, 'label' => '…', 'ellipsis' => 1];
            }
        }
        for ($p = $start; $p <= $end; $p++) {
            $items[] = [
                'page'   => $p,
                'url'    => $pageUrl($p),
                'active' => $p === $page ? 1 : 0,
                'label'  => (string) $p,
            ];
        }
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $items[] = ['page' => 0, 'url' => '', 'active' => 0, 'label' => '…', 'ellipsis' => 1];
            }
            $items[] = [
                'page'   => $totalPages,
                'url'    => $pageUrl($totalPages),
                'active' => $totalPages === $page ? 1 : 0,
                'label'  => (string) $totalPages,
            ];
        }

        return [
            'pagination_show'        => $totalPages > 1 ? 1 : 0,
            'pagination_page'        => $page,
            'pagination_total'       => $total,
            'pagination_total_pages' => $totalPages,
            'pagination_has_prev'    => $page > 1 ? 1 : 0,
            'pagination_has_next'    => $page < $totalPages ? 1 : 0,
            'pagination_prev_url'    => $page > 1 ? $pageUrl($page - 1) : '',
            'pagination_next_url'    => $page < $totalPages ? $pageUrl($page + 1) : '',
            'pagination_items'       => $items,
        ];
    }

    /**
     * 清洗前台列表 query：丢掉 Apache `index.php?/$1` 写进 $_GET 的 pathinfo 脏键。
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    public function sanitizeListQuery(array $query): array
    {
        $out = [];
        foreach ($query as $k => $v) {
            $key = (string) $k;
            if ($key === '' || str_starts_with($key, '/') || str_contains($key, '/')) {
                continue;
            }
            if (!is_scalar($v)) {
                continue;
            }
            $val = trim((string) $v);
            if ($key === 'page') {
                $out['page'] = (string) max(1, (int) $val);

                continue;
            }
            if (
                $key === 'keyword'
                || $key === 'q'
                || $key === 'tag'
                || $key === 'cursor'
                || $key === 'sort'
                || $key === 'type'
                || $key === 'merchant'
                || str_starts_with($key, 'filter_')
            ) {
                if ($val !== '') {
                    $out[$key] = $val;
                }
            }
        }

        return $out;
    }

    /**
     * 解析列表当前页：attrs → 干净 query → 路径页变量。
     *
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     * @param array<string, mixed> $query
     */
    public function resolveListPage(array $attrs = [], array $pageVars = [], array $query = []): int
    {
        if (isset($attrs['page']) && trim((string) $attrs['page']) !== '' && (int) $attrs['page'] > 0) {
            return max(1, (int) $attrs['page']);
        }
        $clean = $this->sanitizeListQuery($query !== [] ? $query : Request::get());
        if (isset($clean['page'])) {
            return max(1, (int) $clean['page']);
        }
        $pathPage = (int) ($pageVars['page'] ?? $pageVars['pagination_page'] ?? 0);

        return max(1, $pathPage > 0 ? $pathPage : 1);
    }

    /**
     * 前台列表分页链接：有 tag 走 SiteUrl 友好路径；附加筛选 query（已洗脏 pathinfo 键）。
     *
     * @param array<string, mixed> $query
     * @param array{tag?:string,base_path?:string} $opts
     */
    public function buildFrontListPageUrl(int $page, array $query = [], array $opts = []): string
    {
        $page = max(1, $page);
        $q    = $this->sanitizeListQuery($query);
        unset($q['page']);

        $tag = trim((string) ($opts['tag'] ?? $q['tag'] ?? ''));
        unset($q['tag']);

        if ($tag !== '') {
            $base = SiteUrl::tag($tag, $page);

            return $q === [] ? $base : $base . '?' . http_build_query($q);
        }

        $path = trim((string) ($opts['base_path'] ?? ''));
        if ($path === '') {
            $path = $this->currentRequestPath();
        }
        if ($page > 1) {
            $q['page'] = (string) $page;
        }

        return $q === [] ? $path : $path . '?' . http_build_query($q);
    }

    public function currentRequestPath(): string
    {
        $uri  = (string) Request::server('REQUEST_URI', '/');
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /**
     * 分页条 HTML
     *
     * @param array<string, mixed>  $vars
     * @param array<string, string> $attrs 透传 PaginationTemplateTagService（class / list_class / show_summary 等）
     */
    public function renderHtml(array $vars, array $attrs = []): string
    {
        return $this->paginationTemplateTagService->renderHtml($vars, $attrs);
    }
}
