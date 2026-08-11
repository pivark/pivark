<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\service\infra\PaginationService;
use app\common\service\template\ArclistTagResolveService;
use app\common\service\template\TemplateBlockRenderer;

/**
 * `{pv:arclist entity=product}` 属性 → ItemService::listPublic 参数。
 * Tag 定位复用 ArclistTagResolveService（tagid / tagname / tagurl / nav…）。
 */
final class ItemListTagAttrsService
{
    public function __construct(
        private readonly ArclistTagResolveService $tagResolve,
        private readonly PaginationService $pagination,
        private readonly TemplateBlockRenderer $blockRenderer,
    ) {
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     * @return array<string, mixed>
     */
    public function listParamsFromAttrs(array $attrs, array $pageVars = [], ?array $queryParams = null): array
    {
        $query = $queryParams ?? $this->requestQueryFromPageVars($pageVars);
        [$limit, $offset] = $this->resolveLimitOffset($attrs);

        $params = [
            'page'   => $offset > 0
                ? 1
                : $this->pagination->resolveListPage($attrs, $pageVars, $query),
            'limit'  => $limit,
            'offset' => $offset,
            'sort'   => $this->blockRenderer->mapTagArticlesSort($attrs),
        ];

        // 栏目意图优先 nav_id，再解析 Tag 聚合
        $navId = $this->tagResolve->resolveContentNavId($attrs, $pageVars);
        if ($navId > 0) {
            $params['nav_id'] = $navId;
        } else {
            $slugs = $this->tagResolve->resolveSlugList($attrs, $pageVars);
            if ($slugs !== []) {
                // 品项 listPublic 当前单 Tag；多 Tag 取首个（与常见侧栏块一致）
                $params['tag'] = $slugs[0];
            }
        }

        if (($keyword = trim((string) ($attrs['keyword'] ?? $attrs['q'] ?? ''))) !== '') {
            $params['keyword'] = $keyword;
        }

        foreach ($attrs as $k => $v) {
            if (str_starts_with((string) $k, 'filter_')) {
                $params[(string) $k] = (string) $v;
            }
        }

        foreach ($this->pagination->sanitizeListQuery($query) as $key => $val) {
            if ($key === 'page') {
                continue;
            }
            if ($key === 'keyword' || $key === 'q') {
                $params['keyword'] = $val;
                continue;
            }
            if ($key === 'tag' && $val !== '' && !isset($params['tag'])) {
                $params['tag'] = $val;
                continue;
            }
            if (str_starts_with($key, 'filter_') && !isset($params[$key])) {
                $params[$key] = $val;
            }
        }

        return $params;
    }

    /**
     * 显式声明了与本页主列表不同的条数（row/limit）→ 不得复用 product_list。
     *
     * @param array<string, mixed> $attrs
     */
    public function wantsExplicitLimit(array $attrs): ?int
    {
        if (isset($attrs['row']) || isset($attrs['loop'])) {
            return min(100, max(1, (int) ($attrs['row'] ?? $attrs['loop'] ?? 0)));
        }
        if (isset($attrs['limit'])) {
            $raw = trim((string) $attrs['limit']);
            if (preg_match('/^(\d+)\s*,\s*(\d+)$/', $raw, $lm)) {
                return min(100, max(1, (int) $lm[2]));
            }
            if (preg_match('/^\d+$/', $raw)) {
                return min(100, max(1, (int) $raw));
            }
        }

        return null;
    }

    /**
     * 品项行补文档风格别名，便于模板共用 {$field.title}/url/litpic。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function normalizeItemField(array $row): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        $url  = trim((string) ($row['card_url'] ?? $row['detail_url'] ?? $row['page_url'] ?? $row['url'] ?? ''));
        // 列表缩略图约定 litpic（enrich 已写入）；cover_url 与之同值供详情模板
        $pic  = trim((string) ($row['litpic'] ?? ''));
        $code = trim((string) ($row['code'] ?? ''));
        $sum  = trim((string) ($row['attrs_summary_html'] ?? $row['summary'] ?? ''));
        if ($sum === '' && $code !== '') {
            $sum = $code;
        }

        // 与详情 ItemPublicViewService 一致：型号优先 attrs，否则回退货号 code
        $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
        $model = trim((string) ($row['model'] ?? ''));
        if ($model === '') {
            $model = trim((string) ($attrs['型号'] ?? $attrs['model'] ?? ''));
        }
        if ($model === '') {
            $model = $code;
        }
        if ($model !== '') {
            $row['model'] = $model;
        }

        if ($name !== '' && !isset($row['title'])) {
            $row['title'] = $name;
        }
        if ($url !== '') {
            $row['url'] = $row['url'] ?? $url;
            $row['arcurl'] = $row['arcurl'] ?? $url;
            $row['card_url'] = $row['card_url'] ?? $url;
            $row['detail_url'] = $row['detail_url'] ?? $url;
        }
        if ($pic !== '') {
            $row['litpic'] = $pic;
            $row['cover_url'] = $pic;
        }
        if ($sum !== '') {
            $row['summary'] = $row['summary'] ?? $sum;
            $row['excerpt'] = $row['excerpt'] ?? $sum;
            $row['excerpt_short'] = $row['excerpt_short'] ?? mb_substr(strip_tags($sum), 0, 120);
            $row['info'] = $row['info'] ?? $row['excerpt_short'];
        }
        $ts = 0;
        foreach (['published_at', 'created_at', 'updated_at'] as $k) {
            $raw = trim((string) ($row[$k] ?? ''));
            if ($raw !== '') {
                $ts = (int) strtotime($raw);
                if ($ts > 0) {
                    break;
                }
            }
        }
        if ($ts > 0) {
            $row['published_date'] = $row['published_date'] ?? date('Y-m-d', $ts);
            $row['published_year'] = $row['published_year'] ?? date('Y', $ts);
            $row['published_md'] = $row['published_md'] ?? date('m-d', $ts);
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return list<array<string, mixed>>
     */
    public function normalizeItemList(array $list): array
    {
        $out = [];
        foreach ($list as $row) {
            if (is_array($row)) {
                $out[] = $this->normalizeItemField($row);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array{0:int,1:int} [limit, offset]
     */
    private function resolveLimitOffset(array $attrs): array
    {
        $limit  = 12;
        $offset = 0;
        if (isset($attrs['limit']) && preg_match('/^(\d+)\s*,\s*(\d+)$/', trim((string) $attrs['limit']), $lm)) {
            $offset = max(0, (int) $lm[1]);
            $limit  = max(1, (int) $lm[2]);
        } elseif (isset($attrs['row']) || isset($attrs['loop'])) {
            $limit = max(1, (int) ($attrs['row'] ?? $attrs['loop'] ?? 12));
            if (isset($attrs['offset'])) {
                $offset = max(0, (int) $attrs['offset']);
            }
        } elseif (isset($attrs['limit']) && preg_match('/^\d+$/', trim((string) $attrs['limit']))) {
            $limit = max(1, (int) trim((string) $attrs['limit']));
            if (isset($attrs['offset'])) {
                $offset = max(0, (int) $attrs['offset']);
            }
        } elseif (isset($attrs['offset'])) {
            $offset = max(0, (int) $attrs['offset']);
        }

        return [min(100, $limit), $offset];
    }

    /**
     * @param array<string, mixed> $pageVars
     * @return array<string, mixed>
     */
    private function requestQueryFromPageVars(array $pageVars): array
    {
        $query = $pageVars['request_query'] ?? [];

        return is_array($query) ? $query : [];
    }
}
