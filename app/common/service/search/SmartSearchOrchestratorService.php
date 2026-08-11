<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\service\ai\KnowledgeSearchService;
use app\common\support\QueryLimit;
use app\common\support\ServiceResult;

use app\common\support\SiteUrl;
use think\facade\Request;

/** 超级搜索编排：P0–P5 统一入口 */
final class SmartSearchOrchestratorService
{

    public function __construct(
        private readonly SmartSearchStackDeps $stack,
        private readonly SmartSearchAssistDeps $assist,
    ) {
    }

    /** 破环：KnowledgeSearchService → SmartSearchOrchestratorService → 本类 */
    private function knowledgeSearch(): KnowledgeSearchService
    {
        return app(KnowledgeSearchService::class);
    }

    /**
     * 前台 /search 页
     *
     * @param bool $keywordPrepared 为 true 时跳过 prepareKeyword（调用方已处理同义词/会话）
     * @return array<string, mixed>
     */
    public function buildPageContext(string $keyword, bool $keywordPrepared = false, int $productPage = 1): array
    {
        if (!$keywordPrepared) {
            $keyword = $this->prepareKeyword($keyword);
        }

        $product = $this->productPack($keyword, 12, $productPage);
        $smart   = $this->smartPack($keyword, $product);

        return array_merge($product, [
            'search_keyword'         => $keyword,
            'search_examples'        => $this->stack->smartConfig->exampleQueries(),
            'search_suggestions'       => $this->assist->suggest->suggestions($keyword, 8),
            'smart_search_on'          => (int) ($smart['enabled'] ?? 0),
            'smart_answer'             => (string) ($smart['answer'] ?? ''),
            'smart_answer_html'        => (string) ($smart['answer_html'] ?? ''),
            'smart_mode'               => (string) ($smart['mode'] ?? ''),
            'smart_sources'            => is_array($smart['sources'] ?? null) ? $smart['sources'] : [],
            'smart_sources_grouped'    => is_array($smart['sources_grouped'] ?? null) ? $smart['sources_grouped'] : [],
            'smart_parsed'             => is_array($smart['parsed'] ?? null) ? $smart['parsed'] : [],
            'smart_filter_chips'       => is_array($smart['filter_chips'] ?? null) ? $smart['filter_chips'] : [],
            'smart_catalog_url'          => (string) ($smart['catalog_url'] ?? ''),
            'smart_fallback'           => (int) ($smart['fallback'] ?? 0),
            'smart_chunk_citations'    => is_array($smart['chunk_citations'] ?? null) ? $smart['chunk_citations'] : [],
            'smart_parse_debug'        => is_array($smart['parse_debug'] ?? null) ? $smart['parse_debug'] : [],
            'search_session_bar'       => $this->assist->session->barForTemplate($keyword),
            'search_append_hint'       => $this->stack->smartConfig->sessionEnabled()
                ? '追加：新关键词前加 + 或勾选追加模式'
                : '',
        ]);
    }

    /**
     * @return array{
     *   product_list:list<array<string,mixed>>,
     *   product_total:int,
     *   product_search_on:int,
     *   product_parsed:array<string,mixed>,
     *   product_filter_chips:list<array<string,string>>,
     *   product_catalog_url:string
     * }
     */
    public function productPack(string $keyword, int $limit = 12, int $page = 1): array
    {
        $empty = [
            'product_list'          => [],
            'product_total'         => 0,
            'product_search_on'     => 0,
            'product_parsed'        => [],
            'product_filter_chips'  => [],
            'product_catalog_url'   => '',
            'product_page'          => max(1, $page),
            'product_limit'         => max(1, $limit),
        ];
        if ($keyword === '' || !class_exists(\app\common\service\product\ProductSmartSearchService::class)) {
            return $empty;
        }
        if (!\app\common\service\product\ProductSmartSearchService::isAvailable()) {
            return $empty;
        }
        $hit = \app\common\service\product\ProductSmartSearchService::search($keyword, $limit, true, $page);
        $productList = $this->typedProductRows($hit['list'] ?? []);
        $filterChips = $this->typedFilterChips($hit['filter_chips'] ?? []);
        $productTotal = (int) ($hit['total'] ?? 0);

        return [
            'product_list'         => $productList,
            'product_total'        => $productTotal,
            'product_search_on'    => $productTotal > 0 ? 1 : 0,
            'product_parsed'       => is_array($hit['parsed'] ?? null) ? $hit['parsed'] : [],
            'product_filter_chips' => $filterChips,
            'product_catalog_url'  => (string) ($hit['catalog_url'] ?? ''),
            'product_page'         => (int) ($hit['page'] ?? max(1, $page)),
            'product_limit'        => (int) ($hit['limit'] ?? max(1, $limit)),
        ];
    }

    /**
     * @param array{
     *   product_list?:list<array<string,mixed>>,
     *   product_total?:int,
     *   product_parsed?:array<string,mixed>,
     *   product_filter_chips?:list<array<string,string>>,
     *   product_catalog_url?:string
     * } $productPack
     * @return array<string, mixed>
     */
    public function smartPack(string $keyword, array $productPack = []): array
    {
        $smart = [
            'answer'           => '',
            'enabled'          => 0,
            'mode'             => '',
            'sources'          => [],
            'sources_grouped'  => ['product' => [], 'document' => [], 'site' => [], 'plugin' => [], 'other' => []],
            'parsed'           => $productPack['product_parsed'] ?? [],
            'filter_chips'     => $productPack['product_filter_chips'] ?? [],
            'catalog_url'      => $productPack['product_catalog_url'] ?? '',
            'fallback'         => 0,
            'parse_debug'      => [],
        ];
        if ($keyword === '' || !$this->stack->smartConfig->isEnabled()) {
            return $this->wrapSmart($smart);
        }

        if (!$this->stack->searchConfig->isAiAnswerEnabled()) {
            $smart = $this->fallbackSmart($keyword, $productPack, $smart);

            return $this->wrapSmart($smart);
        }

        $payload = $this->assist->cache->remember($keyword, function () use ($keyword, $productPack): array {
            if ($this->stack->aiConfig->isEnabled() && $this->stack->aiConfig->activeProviderConfigured()) {
                $res = $this->knowledgeSearch()->smartSearch($keyword);
                if ($res->isOk()) {
                    return $res->dataArray();
                }
            }

            $fallback = $this->fallbackSearchResult($keyword, $productPack);

            return $fallback->isOk() ? $fallback->dataArray() : [];
        });

        $smart['answer']  = (string) ($payload['answer'] ?? '');
        $smart['mode']    = (string) ($payload['mode'] ?? '');
        $smart['sources'] = $this->sourceList($payload['sources'] ?? []);
        $smart['parsed']  = is_array($payload['parsed'] ?? null)
            ? $payload['parsed']
            : ($productPack['product_parsed'] ?? []);
        $smart['filter_chips'] = is_array($payload['filter_chips'] ?? null)
            ? $payload['filter_chips']
            : ($productPack['product_filter_chips'] ?? []);
        $smart['catalog_url']  = (string) ($payload['catalog_url'] ?? ($productPack['product_catalog_url'] ?? ''));
        $smart['fallback']     = (int) ($payload['fallback'] ?? 0);
        $smart['chunk_citations'] = is_array($payload['chunk_citations'] ?? null) ? $payload['chunk_citations'] : [];
        $smart['enabled']      = ($smart['answer'] !== '' || $smart['sources'] !== []) ? 1 : 0;
        $smart['sources_grouped'] = $this->groupSources($smart['sources']);
        $smart['parse_debug'] = $this->parseDebug($smart['parsed']);

        $this->assist->session->persist($keyword, $smart['parsed']);

        return $this->wrapSmart($smart);
    }

    /**
     * API: GET/POST q=
     *
     * @return array<string, mixed>
     */
    public function apiSearch(string $keyword, int $limit = QueryLimit::FRONT_LIST): array
    {
        $keyword = $this->prepareKeyword($keyword);
        $product = $this->productPack($keyword);
        if ($limit > 0 && class_exists(\app\common\service\product\ProductSmartSearchService::class)
            && \app\common\service\product\ProductSmartSearchService::isAvailable()) {
            $hit = \app\common\service\product\ProductSmartSearchService::search($keyword, $limit, true);
            $product['product_list']  = $this->typedProductRows($hit['list'] ?? []);
            $product['product_total'] = (int) ($hit['total'] ?? 0);
            $product['product_search_on'] = $product['product_list'] !== [] ? 1 : 0;
            $product['product_filter_chips'] = $this->typedFilterChips($hit['filter_chips'] ?? []);
            $product['product_catalog_url']  = (string) ($hit['catalog_url'] ?? '');
        }
        $smart = $this->smartPack($keyword, $product);

        $docTotal = 0;
        if ($keyword !== '') {
            $docHit   = $this->stack->contentSearch->searchPublic($keyword, 1, max(1, min(12, $limit)));
            $docBlock = $docHit['documents'];
            $docTotal = (int) $docBlock['total'];
        }
        $this->recordPageOutcome(
            $keyword,
            $docTotal,
            $product['product_total'],
            $smart,
        );

        return [
            'keyword'   => $keyword,
            'products'  => $product['product_list'],
            'smart'     => $smart,
            'suggestions' => $this->assist->suggest->suggestions($keyword, 8),
        ];
    }

    /**
     * @param array<string, mixed> $productPack
     * @param array<string, mixed> $smart
     * @return array<string, mixed>
     */
    private function fallbackSmart(string $keyword, array $productPack, array $smart): array
    {
        if (!$this->stack->smartConfig->fallbackEnabled()) {
            return $this->wrapSmart($smart);
        }
        $result = $this->fallbackSearchResult($keyword, $productPack);
        $data   = $result->dataArray();
        $smart['answer']  = (string) ($data['answer'] ?? '');
        $smart['mode']    = (string) ($data['mode'] ?? 'rule_fallback');
        $smart['sources'] = $this->sourceList($data['sources'] ?? []);
        $smart['fallback'] = 1;
        $smart['enabled']  = $smart['answer'] !== '' || $smart['sources'] !== [] ? 1 : 0;
        $smart['sources_grouped'] = $this->groupSources($smart['sources']);
        $smart['parsed']  = is_array($data['parsed'] ?? null) ? $data['parsed'] : ($productPack['product_parsed'] ?? []);
        $smart['filter_chips'] = is_array($data['filter_chips'] ?? null) ? $data['filter_chips'] : ($productPack['product_filter_chips'] ?? []);
        $smart['parse_debug'] = $this->parseDebug($smart['parsed']);

        return $this->wrapSmart($smart);
    }

    /**
     * @param array{
     *   product_list?:list<array<string,mixed>>,
     *   product_parsed?:array<string,mixed>,
     *   product_filter_chips?:list<array<string,string>>,
     *   product_catalog_url?:string
     * } $productPack
     * @return ServiceResult
     */
    private function fallbackSearchResult(string $keyword, array $productPack): ServiceResult
    {
        $products = $productPack['product_list'] ?? [];
        $sources  = class_exists(\app\common\service\product\ProductSmartSearchService::class)
            ? \app\common\service\product\ProductSmartSearchService::toKnowledgeSources($products)
            : [];
        $lines    = [];
        if ($products !== []) {
            $lines[] = '根据站内品项参数，找到 ' . count($products) . ' 条相关在售型号：';
            foreach (array_slice($products, 0, 5) as $i => $row) {
                $spec = trim((string) ($row['attrs_summary_text'] ?? ''));
                $reason = trim((string) ($row['match_reason_text'] ?? ''));
                $line = ($i + 1) . '. ' . ($row['name'] ?? '');
                if ($spec !== '') {
                    $line .= '（' . $spec . '）';
                }
                if ($reason !== '') {
                    $line .= ' — ' . $reason;
                }
                $lines[] = $line;
            }
        } else {
            $lines[] = '未匹配到符合条件的在售品项，请调整关键词或到产品中心浏览。';
        }

        return ServiceResult::ok(['answer' => implode("\n", $lines), 'mode' => 'rule_fallback', 'sources' => $sources, 'parsed' => $productPack['product_parsed'] ?? [], 'filter_chips' => $productPack['product_filter_chips'] ?? [], 'catalog_url' => $productPack['product_catalog_url'] ?? '', 'fallback' => 1], 'ok');
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @return array{product:list<array<string,mixed>>,document:list<array<string,mixed>>,site:list<array<string,mixed>>,plugin:list<array<string,mixed>>,other:list<array<string,mixed>>}
     */
    public function groupSources(array $sources): array
    {
        $groups = [
            'product'  => [],
            'document' => [],
            'site'     => [],
            'plugin'   => [],
            'other'    => [],
        ];
        foreach ($sources as $src) {
            $type = (string) ($src['type'] ?? '');
            if ($type === 'product') {
                $groups['product'][] = $src;
            } elseif ($type === 'plugin') {
                $groups['plugin'][] = $src;
            } elseif (($src['id'] ?? 0) === 0 && str_contains((string) ($src['title'] ?? ''), '站点')) {
                $groups['site'][] = $src;
            } elseif ((int) ($src['id'] ?? 0) > 0) {
                $groups['document'][] = $src;
            } else {
                $groups['other'][] = $src;
            }
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function parseDebug(array $parsed): array
    {
        if (!$this->stack->smartConfig->parseDebugEnabled()) {
            return [];
        }
        $debugParam = (int) Request::get('search_debug', 0) === 1;
        if (!$debugParam) {
            return [];
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $smart
     * @return array<string, mixed>
     */
    private function wrapSmart(array $smart): array
    {
        $answer = (string) ($smart['answer'] ?? '');
        $smart['answer_html'] = $this->assist->answerFormatter->toHtml($answer);

        return $smart;
    }

    /**
     * 前台搜索页落库统计（文档 + 品项 + 智能来源，与 smartPack 内统计互补）
     *
     * @param array<string, mixed> $smart
     */
    public function recordPageOutcome(string $keyword, int $docTotal, int $productTotal, array $smart = []): void
    {
        if ($keyword === '' || !$this->assist->analytics->tableExists()) {
            return;
        }
        $sources = is_array($smart['sources'] ?? null) ? $smart['sources'] : [];
        $this->assist->analytics->log($keyword, [
            'mode'           => (string) ($smart['mode'] ?? 'page'),
            'product_count'  => $productTotal,
            'doc_count'      => $docTotal,
            'source_count'   => count($sources),
        ]);
    }

    public function prepareKeyword(string $keyword): string
    {
        $keyword = $this->stack->contentSearch->normalizeKeyword($keyword);
        $session = $this->assist->session->mergeKeyword($keyword);
        $keyword = $this->stack->contentSearch->normalizeKeyword((string) $session['keyword']);
        $keyword = $this->assist->synonyms->expand($keyword);
        $payload = ['keyword' => $keyword, 'parsed' => null];
        $payload = $this->assist->hooks->fire('search.parse_query', $payload);

        return $this->stack->contentSearch->normalizeKeyword((string) ($payload['keyword'] ?? $keyword));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function typedProductRows(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, string>>
     */
    private function typedFilterChips(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $chip) {
            if (!is_array($chip)) {
                continue;
            }
            $out[] = [
                'key'   => (string) ($chip['key'] ?? ''),
                'value' => (string) ($chip['value'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $src) {
            if (is_array($src)) {
                $out[] = $src;
            }
        }

        return $out;
    }
}
