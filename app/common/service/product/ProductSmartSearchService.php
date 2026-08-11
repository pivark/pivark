<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\search\SmartSearchConfigService;
use app\common\service\item\ItemService;
use app\common\service\weapp\WeappHookGateway;
use app\common\service\weapp\WeappSearchGateway;
use app\common\service\weapp\WeappItemGateway;
use app\common\service\item\ItemPublicGateway;
use app\common\model\Item;
use app\common\support\SiteUrl;

/**
 * 自然语言品项检索（开源友好）：仅依赖站点 product_param_defs + 在售品项 attrs，无行业硬编码
 */
final class ProductSmartSearchService
{
    /** @var list<string> */
    private const STOP_WORDS_ZH = [
        '帮我', '请', '找', '搜', '搜索', '查询', '查找', '有没有', '想要', '需要', '给我', '推荐',
        '一下', '看看', '并且', '而且', '还有', '以及', '同时', '要是', '要',
        '是', '的', '了', '吗', '呢', '啊', '呀', '吧', '么', '什么', '哪些', '哪个', '怎样',
        '如何', '可以', '能否', '能不能', '帮忙', '帮', '我', '你', '有', '没', '不', '很',
        '非常', '比较', '特别', '大概', '左右', '之间', '以内',
        '一双', '双', '件', '个', '款', '种',
    ];

    /**
     * 常见参数别名 → 站点 attrs 键（按 attrs 实际键名对齐，非行业业务硬编码）
     *
     * @var array<string, list<string>>
     */
    private const PARAM_KEY_ALIASES = [
        '重量'         => ['重量', '净重'],
        '净重'         => ['净重', '重量'],
        '扩张距离'     => ['扩张距离', '最大扩张距离'],
        '最大扩张距离' => ['最大扩张距离', '扩张距离'],
        '开口距离'     => ['开口距离', '剪刀端部开口距离', '剪切开口距离', '最大开启距离'],
        '剪刀端部开口距离' => ['剪刀端部开口距离', '开口距离', '剪切开口距离'],
    ];

    /** @var list<string> */
    private const STOP_WORDS_EN = [
        'a', 'an', 'the', 'please', 'find', 'search', 'show', 'need', 'want', 'with', 'and', 'or',
        'for', 'me', 'my', 'any', 'some', 'product', 'products', 'item', 'items',
    ];

    /** @return list<string> */
    private static function stopWords(): array
    {
        return app(WeappSearchGateway::class)->smartSearchLang() === 'en' ? self::STOP_WORDS_EN : self::STOP_WORDS_ZH;
    }

    public static function isAvailable(): bool
    {
        return ProductCenterGateService::publicSurfaceOpen();
    }

    /**
     * 是否为带参数/数值条件的智能检索（非纯关键词）
     *
     * @param array{
     *   filters?:array<string,string>,
     *   soft_filters?:array<string,string>,
     *   comparisons?:list<array<string,mixed>>,
     *   ranges?:list<string>
     * } $parsed
     */
    public static function parsedHasConditions(array $parsed): bool
    {
        if (($parsed['filters'] ?? []) !== []) {
            return true;
        }
        if (($parsed['soft_filters'] ?? []) !== []) {
            return true;
        }
        if (($parsed['comparisons'] ?? []) !== []) {
            return true;
        }

        return false;
    }

    /**
     * @return array{
     *   list:list<array<string,mixed>>,
     *   total:int,
     *   parsed:array{
     *     filters:array<string,string>,
     *     soft_filters:array<string,string>,
     *     tokens:list<string>,
     *     ranges:list<string>
     *   }
     * }
     */
    public static function recall(string $keyword, int $limit = 12): array
    {
        if (!self::isAvailable()) {
            return [
                'list'   => [],
                'total'  => 0,
                'parsed' => ['filters' => [], 'soft_filters' => [], 'tokens' => [], 'ranges' => [], 'comparisons' => []],
            ];
        }

        return self::search($keyword, $limit);
    }

    /**
     * @return array{
     *   list:list<array<string,mixed>>,
     *   total:int,
     *   parsed:array{
     *     filters:array<string,string>,
     *     soft_filters:array<string,string>,
     *     tokens:list<string>,
     *     ranges:list<string>
     *   }
     * }
     */
    public static function search(string $keyword, int $limit = 12, bool $keywordPrepared = false, int $page = 1): array
    {
        $keyword = trim($keyword);
        $limit   = max(1, min(50, $limit));
        $page    = max(1, $page);
        $empty   = [
            'list'         => [],
            'total'        => 0,
            'parsed'       => ['filters' => [], 'soft_filters' => [], 'tokens' => [], 'ranges' => [], 'comparisons' => []],
            'filter_chips' => [],
            'catalog_url'  => '',
            'page'         => $page,
            'limit'        => $limit,
        ];
        if ($keyword === '' || !self::isAvailable()) {
            return $empty;
        }

        $session = app(WeappSearchGateway::class)->smartSearchSessionMergeKeyword($keyword);
        $keyword = trim((string) ($session['keyword'] ?? $keyword));
        if (!$keywordPrepared) {
            $keyword = app(WeappSearchGateway::class)->smartSearchSynonymExpand($keyword);
            $hook    = app(WeappHookGateway::class)->hookFire('search.parse_query', ['keyword' => $keyword, 'parsed' => null]);
            $keyword = trim((string) ($hook['keyword'] ?? $keyword));
        }

        $defs   = self::matchingParamDefs();
        $parsed = self::parseQuery($keyword, $defs);
        $parsed = app(WeappSearchGateway::class)->smartSearchSynonymApplyToParsed($parsed, $defs);
        foreach (is_array($session['filters'] ?? null) ? $session['filters'] : [] as $k => $v) {
            $k = (string) $k;
            $v = trim((string) $v);
            if ($k !== '' && $v !== '' && !isset($parsed['filters'][$k])) {
                $parsed['filters'][$k] = $v;
            }
        }

        $strict = self::fetchCandidates($parsed, $defs, $limit, true, $keyword, $page);
        $result = $strict;
        if ($result['list'] === [] && $result['total'] === 0 && $parsed['filters'] !== []) {
            $relaxed = $parsed;
            if (app(WeappSearchGateway::class)->smartSearchRelaxMode() === SmartSearchConfigService::RELAX_KEYWORD_ONLY) {
                $relaxed['filters']      = [];
                $relaxed['soft_filters'] = [];
            } else {
                $relaxed['filters'] = [];
            }
            $loose = self::fetchCandidates($relaxed, $defs, $limit, false, $keyword, $page);
            if ($loose['list'] !== [] || $loose['total'] > 0) {
                $loose['parsed'] = $parsed;
                $result          = $loose;
            }
        }
        if ($result['list'] === [] && $result['total'] === 0) {
            $wide = [
                'filters'      => [],
                'soft_filters' => [],
                'tokens'       => $parsed['tokens'],
                'ranges'       => $parsed['ranges'],
                'comparisons'  => $parsed['comparisons'] ?? [],
            ];
            if ($wide['tokens'] === []) {
                $wide['tokens'] = array_values(array_filter(
                    preg_split('/[\s,，、；;]+/u', $keyword) ?: [],
                    static fn (string $t): bool => mb_strlen(trim($t)) >= 2,
                ));
            }
            if ($wide['tokens'] !== [] || ($wide['comparisons'] ?? []) !== []) {
                $loose = self::fetchCandidates($wide, $defs, $limit, false, $keyword, $page);
                if ($loose['list'] !== [] || $loose['total'] > 0) {
                    $loose['parsed'] = $parsed;
                    $result          = $loose;
                }
            }
        }

        $result['filter_chips'] = self::buildFilterChips($parsed, $defs);
        $result['catalog_url']  = self::buildCatalogUrl($parsed);
        $result['page']         = $page;
        $result['limit']        = $limit;
        app(WeappSearchGateway::class)->smartSearchSessionPersist($keyword, $parsed);

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function onParseQueryHook(array &$payload): void
    {
        $payload['keyword'] = app(WeappSearchGateway::class)->smartSearchSynonymExpand(trim((string) ($payload['keyword'] ?? '')));
    }

    /**
     * search.enhance.product：AI 未命中时注入品项规则摘要（P5）
     *
     * @param array<string, mixed> $payload
     */
    public static function onSearchEnhanceHook(array &$payload): void
    {
        if (!self::isAvailable() || !app(WeappSearchGateway::class)->smartSearchFallbackEnabled()) {
            return;
        }
        $keyword = trim((string) ($payload['keyword'] ?? ''));
        if ($keyword === '' || !isset($payload['smart']) || !is_array($payload['smart'])) {
            return;
        }
        $smart = &$payload['smart'];
        if ((int) ($smart['enabled'] ?? 0) === 1 && trim((string) ($smart['answer'] ?? '')) !== '') {
            return;
        }
        $hit = self::search($keyword, 8);
        $list = is_array($hit['list'] ?? null) ? $hit['list'] : [];
        if ($list === []) {
            return;
        }
        $lines = ['根据站内品项参数，找到 ' . count($list) . ' 条相关在售型号：'];
        foreach (array_slice($list, 0, 5) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = ($i + 1) . '. ' . ($row['name'] ?? '');
            $spec = trim((string) ($row['attrs_summary_text'] ?? ''));
            if ($spec !== '') {
                $line .= '（' . $spec . '）';
            }
            $reason = trim((string) ($row['match_reason_text'] ?? ''));
            if ($reason !== '') {
                $line .= ' — ' . $reason;
            }
            $lines[] = $line;
        }
        $smart['answer']   = implode("\n", $lines);
        $smart['enabled']  = 1;
        $smart['mode']     = 'product_rule';
        $smart['fallback'] = 1;
        $smart['sources']  = array_merge(
            self::toKnowledgeSources($list),
            is_array($smart['sources'] ?? null) ? $smart['sources'] : [],
        );
        $smart['filter_chips'] = is_array($hit['filter_chips'] ?? null) ? $hit['filter_chips'] : [];
        $smart['catalog_url']  = (string) ($hit['catalog_url'] ?? '');
        $smart['parsed']       = is_array($hit['parsed'] ?? null) ? $hit['parsed'] : [];
    }

    /**
     * 加载参数定义并补齐选项（配置项 + 站内已有 attrs 去重值），供任意行业站点使用
     *
     * @return list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}>
     */
    public static function matchingParamDefs(): array
    {
        $out = [];
        $seen = [];
        foreach (ProductService::listParamDefs() as $def) {
            $key = (string) ($def['param_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $options = is_array($def['options'] ?? null) ? $def['options'] : [];
            $options = array_values(array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                $options,
            ), static fn (string $v): bool => $v !== '' && $v !== '—' && $v !== '-'));
            if ($options === []) {
                $options = app(WeappItemGateway::class)->itemAttrDistinctValues($key);
            }
            $out[] = [
                'param_key'   => $key,
                'label'       => (string) ($def['label'] ?? $key),
                'filterable'  => (int) ($def['filterable'] ?? 0),
                'input_type'  => (string) ($def['input_type'] ?? 'text'),
                'options'     => $options,
            ];
            $seen[$key] = true;
        }

        foreach (self::discoverAttrParamKeys() as $key) {
            if (isset($seen[$key])) {
                continue;
            }
            $out[] = [
                'param_key'   => $key,
                'label'       => $key,
                'filterable'  => 0,
                'input_type'  => 'text',
                'options'     => app(WeappItemGateway::class)->itemAttrDistinctValues($key),
            ];
            $seen[$key] = true;
        }

        return $out;
    }

    /** @return list<string> */
    private static function discoverAttrParamKeys(): array
    {
        $counts = [];
        $rows = Item::where('status', ItemService::STATUS_ACTIVE)->field('attrs')->limit(500)->select()->toArray();
        foreach ($rows as $row) {
            $attrs = $row['attrs'] ?? null;
            if (is_string($attrs)) {
                $decoded = json_decode($attrs, true);
                $attrs = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($attrs)) {
                continue;
            }
            foreach ($attrs as $k => $v) {
                if (is_array($v) || is_object($v)) {
                    continue;
                }
                $key = trim((string) $k);
                if ($key === '' || str_starts_with($key, 'eyou_') || $key === 'list_price') {
                    continue;
                }
                if (!preg_match('/^[\x{4e00}-\x{9fff}A-Za-z0-9_\-]{1,40}$/u', $key)) {
                    continue;
                }
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_slice(array_keys($counts), 0, 80);
    }

    /**
     * @param list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}> $paramDefs
     * @return array{
     *   filters:array<string,string>,
     *   soft_filters:array<string,string>,
     *   tokens:list<string>,
     *   ranges:list<string>,
     *   comparisons:list<array{key:string,label:string,op:string,a:float,b?:float}>
     * }
     */
    public static function parseQuery(string $keyword, array $paramDefs): array
    {
        $working = trim(preg_replace('/\s+/u', ' ', $keyword) ?? $keyword);
        $working = self::normalizeUnits($working);
        $comparisons = self::extractComparisons($working, $paramDefs);
        $comparisons = array_merge($comparisons, self::extractUnitImpliedComparisons($working, $paramDefs));
        $ranges  = self::extractRanges($working);
        foreach (self::stopWords() as $w) {
            $working = str_replace($w, ' ', $working);
        }
        $working = trim(preg_replace('/\s+/u', ' ', $working) ?? $working);

        $matched = self::matchParamOptions($working, $paramDefs);
        $filters = $matched['filters'];
        $soft    = $matched['soft_filters'];

        self::bindRangesToFilters($ranges, $paramDefs, $filters, $soft);
        self::bindLabeledRangesToComparisons($ranges, $working, $paramDefs, $comparisons);

        $tokens = self::tokenizeResidual($working);
        foreach ($ranges as $rangeText) {
            if ($rangeText !== '' && !in_array($rangeText, $tokens, true)) {
                // 已绑定为数值比较的区间不再当关键词
                $bound = false;
                foreach ($comparisons as $cmp) {
                    if (($cmp['op'] ?? '') === 'between'
                        && isset($cmp['a'], $cmp['b'])
                        && abs((float) $cmp['a'] - (float) explode('-', $rangeText)[0]) < 0.0001) {
                        $bound = true;
                        break;
                    }
                }
                if (!$bound) {
                    $tokens[] = $rangeText;
                }
            }
        }
        foreach (self::extractProductCodes($keyword) as $code) {
            if (!in_array($code, $tokens, true)) {
                $tokens[] = $code;
            }
        }
        foreach (array_merge($filters, $soft) as $val) {
            if ($val !== '' && !in_array($val, $tokens, true)) {
                $tokens[] = $val;
            }
        }

        return [
            'filters'      => $filters,
            'soft_filters' => $soft,
            'tokens'       => $tokens,
            'ranges'       => $ranges,
            'comparisons'  => $comparisons,
        ];
    }

    /**
     * @param list<array{param_key:string,label:string,filterable:int,options:list<string>}> $paramDefs
     * @param array<string,string> $filters
     * @param array<string,string> $soft
     */
    private static function bindRangesToFilters(array $ranges, array $paramDefs, array &$filters, array &$soft): void
    {
        foreach ($ranges as $rangeText) {
            if ($rangeText === '') {
                continue;
            }
            foreach ($paramDefs as $def) {
                $key = (string) ($def['param_key'] ?? '');
                if ($key === '' || isset($filters[$key]) || isset($soft[$key])) {
                    continue;
                }
                $opt = self::findOptionContaining($def, $rangeText);
                if ($opt === '') {
                    continue;
                }
                if ((int) ($def['filterable'] ?? 0) === 1) {
                    $filters[$key] = $opt;
                } else {
                    $soft[$key] = $opt;
                }
                break;
            }
        }
    }

    /**
     * @param array{
     *   filters:array<string,string>,
     *   soft_filters:array<string,string>,
     *   tokens:list<string>,
     *   ranges:list<string>
     * } $parsed
     * @return array{
     *   list:list<array<string,mixed>>,
     *   total:int,
     *   parsed:array{
     *     filters:array<string,string>,
     *     soft_filters:array<string,string>,
     *     tokens:list<string>,
     *     ranges:list<string>
     *   }
     * }
     */
    /**
     * @param list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}> $defs
     */
    /**
     * @param list<array<string,mixed>> $base
     * @param array{filters:array<string,string>,soft_filters:array<string,string>,tokens:list<string>} $parsed
     * @return list<array<string,mixed>>
     */
    private static function mergeMeiliCandidates(array $base, array $parsed, int $limit, string $originalKeyword = ''): array
    {
        if (!app(WeappItemGateway::class)->itemSearchIndexServiceAvailable()) {
            return $base;
        }
        $keyword = trim($originalKeyword);
        if ($keyword === '') {
            $keyword = implode(' ', array_merge(
                $parsed['tokens'] ?? [],
                array_values($parsed['filters'] ?? []),
                array_values($parsed['soft_filters'] ?? []),
            ));
        }
        if ($keyword === '') {
            return $base;
        }
        $hit = app(WeappItemGateway::class)->itemSearchIndexSearchIds($keyword, $limit);
        $ids = is_array($hit['ids'] ?? null) ? $hit['ids'] : [];
        if ($ids === []) {
            return $base;
        }

        $merged = self::mergeListRows($base, app(ItemPublicGateway::class)->listPublicByIds($ids));
        foreach ($merged as &$row) {
            if (is_array($row)) {
                $row['_meili_hit'] = 1;
            }
        }
        unset($row);

        return $merged;
    }

    /**
     * @param list<array<string,mixed>> $a
     * @param list<array<string,mixed>> $b
     * @return list<array<string,mixed>>
     */
    private static function mergeListRows(array $a, array $b): array
    {
        $seen = [];
        $out  = [];
        foreach (array_merge($a, $b) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[]     = $row;
        }

        return $out;
    }

    /**
     * @param list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}> $defs
     */
    private static function fetchCandidates(array $parsed, array $defs, int $limit, bool $applyFilters, string $originalKeyword = '', int $page = 1): array
    {
        $page = max(1, $page);
        $pool = min(200, max($limit * 8, $page * $limit));
        $params = ['page' => 1, 'limit' => min(120, $pool)];
        if ($applyFilters) {
            foreach ($parsed['filters'] as $paramKey => $val) {
                $params['filter_' . $paramKey] = $val;
            }
        }

        $keywordParts = $parsed['tokens'];
        foreach ($parsed['soft_filters'] as $val) {
            if ($val !== '' && !in_array($val, $keywordParts, true)) {
                $keywordParts[] = $val;
            }
        }
        $keywordForList = implode(' ', $keywordParts);
        $hasComparisons = ($parsed['comparisons'] ?? []) !== [];
        if ($keywordForList !== '') {
            $params['keyword'] = $keywordForList;
        } elseif (!$applyFilters || ($parsed['filters'] === [] && !$hasComparisons)) {
            return ['list' => [], 'total' => 0, 'parsed' => $parsed];
        }

        $rows = self::mergeMeiliCandidates([], $parsed, $pool, $originalKeyword);
        $hit  = app(ItemPublicGateway::class)->listPublic($params);
        $rows = self::mergeListRows($rows, is_array($hit['list'] ?? null) ? $hit['list'] : []);
        if ($rows === [] && $applyFilters && $parsed['filters'] !== []) {
            $rows = self::mergeMeiliCandidates([], $parsed, min(200, $pool + $limit * 2), $originalKeyword);
            if ($rows === []) {
                return ['list' => [], 'total' => 0, 'parsed' => $parsed];
            }
        }
        $rows = self::appendTextParamCandidates($rows, $parsed, $defs, $pool);

        $scored = self::scoreRows($rows, $parsed, $defs, $originalKeyword);
        $list   = [];
        $offset = ($page - 1) * $limit;
        foreach (array_slice($scored, $offset, $limit) as $item) {
            $row = is_array($item['row'] ?? null) ? $item['row'] : [];
            if ($row === []) {
                continue;
            }
            $row['match_reason_text'] = implode('；', is_array($item['reasons'] ?? null) ? $item['reasons'] : []);
            $row['match_reason_html'] = self::formatMatchReasonHtml($row['match_reason_text']);
            unset($row['_meili_hit']);
            $list[] = $row;
        }

        return ['list' => $list, 'total' => count($scored), 'parsed' => $parsed];
    }

    private static function formatMatchReasonHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 关键词「扩张器」及任意「…」内搜索词改色
        return (string) preg_replace(
            '/「([^」]+)」/u',
            '「<mark class="st-search-hit">$1</mark>」',
            $html
        );
    }

    /**
     * @param array{filters:array<string,string>,soft_filters:array<string,string>,tokens:list<string>,ranges:list<string>} $parsed
     * @param list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}> $defs
     * @return list<array{key:string,label:string,value:string,url:string}>
     */
    public static function buildFilterChips(array $parsed, array $defs): array
    {
        $labels = [];
        foreach ($defs as $def) {
            $key = (string) ($def['param_key'] ?? '');
            if ($key !== '') {
                $labels[$key] = (string) ($def['label'] ?? $key);
            }
        }
        $chips = [];
        foreach (array_merge($parsed['filters'] ?? [], $parsed['soft_filters'] ?? []) as $key => $val) {
            $val = trim((string) $val);
            if ($val === '') {
                continue;
            }
            $chips[] = [
                'key'   => (string) $key,
                'label' => $labels[(string) $key] ?? (string) $key,
                'value' => $val,
                'url'   => self::buildCatalogUrl($parsed, (string) $key),
            ];
        }
        foreach ($parsed['comparisons'] ?? [] as $cmp) {
            if (!is_array($cmp)) {
                continue;
            }
            $key = (string) ($cmp['key'] ?? '');
            $label = (string) ($cmp['label'] ?? ($labels[$key] ?? $key));
            $op = (string) ($cmp['op'] ?? '');
            $a = $cmp['a'] ?? null;
            if ($key === '' || $op === '' || !is_numeric($a)) {
                continue;
            }
            $value = match ($op) {
                'lt' => '<' . $a,
                'lte' => '≤' . $a,
                'gt' => '>' . $a,
                'gte' => '≥' . $a,
                'between' => $a . '-' . ($cmp['b'] ?? ''),
                default => (string) $a,
            };
            $chips[] = [
                'key'   => $key,
                'label' => $label,
                'value' => $value,
                'url'   => self::buildCatalogUrl($parsed),
            ];
        }

        return $chips;
    }

    /**
     * @param array{filters?:array<string,string>,soft_filters?:array<string,string>} $parsed
     */
    public static function buildCatalogUrl(array $parsed, ?string $dropKey = null): string
    {
        $params = [];
        foreach (array_merge($parsed['filters'] ?? [], $parsed['soft_filters'] ?? []) as $key => $val) {
            if ($dropKey !== null && (string) $key === $dropKey) {
                continue;
            }
            $val = trim((string) $val);
            if ($val !== '') {
                $params['filter_' . $key] = $val;
            }
        }
        if ($params === []) {
            return '/chanpin';
        }

        return '/chanpin?' . http_build_query($params);
    }

    /**
     * @param list<array{param_key:string,label:string,filterable:int,options:list<string>}> $paramDefs
     * @return array{filters:array<string,string>,soft_filters:array<string,string>}
     */
    private static function matchParamOptions(string &$working, array $paramDefs): array
    {
        $filters = [];
        $soft    = [];
        $catalog = [];

        foreach ($paramDefs as $def) {
            $paramKey = (string) ($def['param_key'] ?? '');
            $label    = trim((string) ($def['label'] ?? ''));
            if ($paramKey === '') {
                continue;
            }
            foreach (is_array($def['options'] ?? null) ? $def['options'] : [] as $opt) {
                if (is_array($opt)) {
                    $optValue = trim((string) ($opt['value'] ?? ''));
                    $optLabel = trim((string) ($opt['label'] ?? ''));
                    if ($optValue === '') {
                        $optValue = $optLabel;
                    }
                    if ($optLabel === '') {
                        $optLabel = $optValue;
                    }
                } else {
                    $optValue = trim((string) $opt);
                    $optLabel = $optValue;
                }
                if ($optValue === '' || $optValue === '—' || $optValue === '-') {
                    continue;
                }
                foreach (array_unique(array_filter([$optValue, $optLabel], static fn (string $s): bool => $s !== '')) as $token) {
                    $catalog[] = [
                        'param_key'  => $paramKey,
                        'label'      => $label,
                        'value'      => $optValue,
                        'token'      => $token,
                        'filterable' => (int) ($def['filterable'] ?? 0),
                        'len'        => mb_strlen($token),
                    ];
                }
            }
        }

        usort($catalog, static fn (array $a, array $b): int => $b['len'] <=> $a['len']);

        foreach ($catalog as $c) {
            $token = (string) ($c['token'] ?? $c['value'] ?? '');
            if ($token === '' || mb_strpos($working, $token) === false) {
                continue;
            }
            $key = $c['param_key'];
            if (isset($filters[$key]) || isset($soft[$key])) {
                continue;
            }
            if ($c['filterable'] === 1) {
                $filters[$key] = $c['value'];
            } else {
                $soft[$key] = $c['value'];
            }
            $working = str_replace($token, ' ', $working);
        }

        self::matchLabelAnchoredOptions($working, $paramDefs, $filters, $soft);
        self::matchOptionPrefixes($working, $paramDefs, $filters, $soft);

        return ['filters' => $filters, 'soft_filters' => $soft];
    }

    /**
     * 问句含参数显示名时，用剩余片段对该参数的选项做前缀/包含匹配（不依赖颜色等行业词表）
     *
     * @param list<array{param_key:string,label:string,filterable:int,options:list<string>}> $paramDefs
     * @param array<string,string> $filters
     * @param array<string,string> $soft
     */
    private static function matchLabelAnchoredOptions(
        string &$working,
        array $paramDefs,
        array &$filters,
        array &$soft,
    ): void {
        foreach ($paramDefs as $def) {
            $paramKey = (string) ($def['param_key'] ?? '');
            $label    = trim((string) ($def['label'] ?? ''));
            if ($paramKey === '' || $label === '' || mb_strlen($label) < 2) {
                continue;
            }
            if (mb_strpos($working, $label) === false) {
                continue;
            }
            if (isset($filters[$paramKey]) || isset($soft[$paramKey])) {
                continue;
            }
            $options = is_array($def['options'] ?? null) ? $def['options'] : [];
            usort($options, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            foreach ($options as $opt) {
                $opt = trim((string) $opt);
                if ($opt === '') {
                    continue;
                }
                if (self::optionMentionedInText($working, $opt)) {
                    if ((int) ($def['filterable'] ?? 0) === 1) {
                        $filters[$paramKey] = $opt;
                    } else {
                        $soft[$paramKey] = $opt;
                    }
                    $working = self::stripMentionFromText($working, $opt);
                    $working = str_replace($label, ' ', $working);
                    break;
                }
            }
        }
    }

    /**
     * 选项前缀匹配：用户说「红」、选项为「红色」时仍可命中（仅限未匹配过的参数）
     *
     * @param list<array{param_key:string,label:string,filterable:int,options:list<string>}> $paramDefs
     * @param array<string,string> $filters
     * @param array<string,string> $soft
     */
    private static function matchOptionPrefixes(
        string &$working,
        array $paramDefs,
        array &$filters,
        array &$soft,
    ): void {
        $parts = preg_split('/[\s,，、；;]+/u', trim($working)) ?: [];
        foreach ($paramDefs as $def) {
            $paramKey = (string) ($def['param_key'] ?? '');
            if ($paramKey === '' || isset($filters[$paramKey]) || isset($soft[$paramKey])) {
                continue;
            }
            $options = is_array($def['options'] ?? null) ? $def['options'] : [];
            usort($options, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            foreach ($options as $opt) {
                $opt = trim((string) $opt);
                if ($opt === '' || mb_strlen($opt) < 2) {
                    continue;
                }
                foreach ($parts as $part) {
                    $part = trim((string) $part);
                    if ($part === '' || mb_strlen($part) < 1) {
                        continue;
                    }
                    if (!self::prefixMatchesOption($part, $opt)) {
                        continue;
                    }
                    if ((int) ($def['filterable'] ?? 0) === 1) {
                        $filters[$paramKey] = $opt;
                    } else {
                        $soft[$paramKey] = $opt;
                    }
                    $working = self::stripMentionFromText($working, $part);
                    break 2;
                }
            }
        }
    }

    private static function prefixMatchesOption(string $token, string $option): bool
    {
        if ($token === $option) {
            return true;
        }
        if (mb_strpos($option, $token) === 0 && mb_strlen($token) >= 1) {
            return true;
        }
        if (mb_strpos($token, $option) === 0 && mb_strlen($option) >= 2) {
            return true;
        }

        return false;
    }

    private static function optionMentionedInText(string $text, string $option): bool
    {
        if ($option === '') {
            return false;
        }
        if (mb_strpos($text, $option) !== false) {
            return true;
        }
        $parts = preg_split('/[\s,，、；;]+/u', trim($text)) ?: [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '' && self::prefixMatchesOption($part, $option)) {
                return true;
            }
        }

        return false;
    }

    private static function stripMentionFromText(string $text, string $fragment): string
    {
        if ($fragment === '') {
            return $text;
        }

        return trim(preg_replace('/\s+/u', ' ', str_replace($fragment, ' ', $text)) ?? $text);
    }

    /**
     * @param array{param_key:string,label:string,filterable:int,options:list<string>} $def
     */
    private static function findOptionContaining(array $def, string $needle): string
    {
        foreach (is_array($def['options'] ?? null) ? $def['options'] : [] as $opt) {
            $opt = trim((string) $opt);
            if ($opt !== '' && (mb_strpos($opt, $needle) !== false || mb_strpos($needle, $opt) !== false)) {
                return $opt;
            }
        }

        return '';
    }

    /** @return list<string> */
    private static function extractRanges(string &$working): array
    {
        $ranges = [];
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*[-~～至到]\s*(\d+(?:\.\d+)?)/u', $working, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $ranges[] = $hit[1] . '-' . $hit[2];
                $working  = str_replace($hit[0], ' ', $working);
            }
        }

        return array_values(array_unique($ranges));
    }

    /**
     * @param list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}> $paramDefs
     * @return list<array{key:string,label:string,op:string,a:float,b?:float}>
     */
    private static function extractComparisons(string &$working, array $paramDefs): array
    {
        $labels = self::comparableLabels($paramDefs);
        usort($labels, static fn (array $a, array $b): int => mb_strlen($b['label']) <=> mb_strlen($a['label']));
        $out = [];
        foreach ($labels as $meta) {
            $label = $meta['label'];
            $key   = $meta['key'];
            if ($label === '' || mb_strpos($working, $label) === false) {
                continue;
            }
            $quoted = preg_quote($label, '/');

            // 扩张距离在600-650 / 重量 600~650
            if (preg_match('/' . $quoted . '\s*在?\s*(\d+(?:\.\d+)?)\s*[-~～至到]\s*(\d+(?:\.\d+)?)\s*(?:kg|KG|mm|MM|m|M|kn|KN)?/u', $working, $m) === 1) {
                $out[] = ['key' => $key, 'label' => $label, 'op' => 'between', 'a' => (float) $m[1], 'b' => (float) $m[2]];
                $working = str_replace($m[0], ' ', $working);
                continue;
            }

            // 重量小于20kg / 净重≤18 / 开口距离大于700
            $opMap = [
                '小于等于' => 'lte', '不大于' => 'lte', '不超过' => 'lte', '低于等于' => 'lte',
                '大于等于' => 'gte', '不小于' => 'gte', '不少于' => 'gte', '不低于' => 'gte',
                '小于' => 'lt', '低于' => 'lt', '不到' => 'lt',
                '大于' => 'gt', '高于' => 'gt', '超过' => 'gt',
                '<=' => 'lte', '≤' => 'lte', '>=' => 'gte', '≥' => 'gte', '<' => 'lt', '>' => 'gt',
            ];
            $opAlt = implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), array_keys($opMap)));
            if (preg_match('/' . $quoted . '\s*(?:在)?\s*(' . $opAlt . ')\s*(\d+(?:\.\d+)?)\s*(?:kg|KG|mm|MM|m|M|kn|KN)?/u', $working, $m) === 1) {
                $op = $opMap[$m[1]] ?? '';
                if ($op !== '') {
                    $out[] = ['key' => $key, 'label' => $label, 'op' => $op, 'a' => (float) $m[2]];
                    $working = str_replace($m[0], ' ', $working);
                    continue;
                }
            }

            // 开口距离在700以上 / 重量20以下
            if (preg_match('/' . $quoted . '\s*在?\s*(\d+(?:\.\d+)?)\s*(?:kg|KG|mm|MM)?\s*(以上|及以上|以下|及以下|以内)/u', $working, $m) === 1) {
                $tail = $m[2];
                $op = str_contains($tail, '上') ? 'gte' : 'lte';
                $out[] = ['key' => $key, 'label' => $label, 'op' => $op, 'a' => (float) $m[1]];
                $working = str_replace($m[0], ' ', $working);
            }
        }

        return $out;
    }

    /**
     * 无参数名、但带单位的比较：如「小于18kg的扩张器」「20kg以下」。
     * kg/公斤 → 重量/净重；mm → 优先扩张距离/开口距离类参数。
     *
     * @param list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}> $paramDefs
     * @return list<array{key:string,label:string,op:string,a:float,b?:float}>
     */
    private static function extractUnitImpliedComparisons(string &$working, array $paramDefs): array
    {
        $opMap = [
            '小于等于' => 'lte', '不大于' => 'lte', '不超过' => 'lte', '低于等于' => 'lte',
            '大于等于' => 'gte', '不小于' => 'gte', '不少于' => 'gte', '不低于' => 'gte',
            '小于' => 'lt', '低于' => 'lt', '不到' => 'lt',
            '大于' => 'gt', '高于' => 'gt', '超过' => 'gt',
            '<=' => 'lte', '≤' => 'lte', '>=' => 'gte', '≥' => 'gte', '<' => 'lt', '>' => 'gt',
        ];
        $opAlt = implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), array_keys($opMap)));
        $out = [];

        // 小于18kg / 不到20公斤
        if (preg_match('/(' . $opAlt . ')\s*(\d+(?:\.\d+)?)\s*(kg|KG|公斤)/u', $working, $m) === 1) {
            $op = $opMap[$m[1]] ?? '';
            if ($op !== '') {
                $meta = self::resolveUnitParamMeta('kg', $paramDefs);
                $out[] = ['key' => $meta['key'], 'label' => $meta['label'], 'op' => $op, 'a' => (float) $m[2]];
                $working = str_replace($m[0], ' ', $working);
            }
        }

        // 18kg以下 / 20公斤以内
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|KG|公斤)\s*(以下|及以下|以内|以上|及以上)/u', $working, $m) === 1) {
            $meta = self::resolveUnitParamMeta('kg', $paramDefs);
            $op = str_contains($m[3], '上') ? 'gte' : 'lte';
            $out[] = ['key' => $meta['key'], 'label' => $meta['label'], 'op' => $op, 'a' => (float) $m[1]];
            $working = str_replace($m[0], ' ', $working);
        }

        // 大于700mm / 600mm以上（距离类）
        if (preg_match('/(' . $opAlt . ')\s*(\d+(?:\.\d+)?)\s*(mm|MM)/u', $working, $m) === 1) {
            $op = $opMap[$m[1]] ?? '';
            if ($op !== '') {
                $meta = self::resolveUnitParamMeta('mm', $paramDefs);
                if ($meta['key'] !== '') {
                    $out[] = ['key' => $meta['key'], 'label' => $meta['label'], 'op' => $op, 'a' => (float) $m[2]];
                    $working = str_replace($m[0], ' ', $working);
                }
            }
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(mm|MM)\s*(以下|及以下|以内|以上|及以上)/u', $working, $m) === 1) {
            $meta = self::resolveUnitParamMeta('mm', $paramDefs);
            if ($meta['key'] !== '') {
                $op = str_contains($m[3], '上') ? 'gte' : 'lte';
                $out[] = ['key' => $meta['key'], 'label' => $meta['label'], 'op' => $op, 'a' => (float) $m[1]];
                $working = str_replace($m[0], ' ', $working);
            }
        }

        return $out;
    }

    /**
     * @param list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}> $paramDefs
     * @return array{key:string,label:string}
     */
    private static function resolveUnitParamMeta(string $unit, array $paramDefs): array
    {
        $prefer = $unit === 'mm'
            ? ['扩张距离', '最大扩张距离', '开口距离', '剪刀端部开口距离', '剪切开口距离', '最大开启距离']
            : ['重量', '净重'];
        foreach ($prefer as $name) {
            foreach ($paramDefs as $def) {
                $key = trim((string) ($def['param_key'] ?? ''));
                $label = trim((string) ($def['label'] ?? ''));
                if ($key === $name || $label === $name) {
                    return [
                        'key'   => $key !== '' ? $key : $name,
                        'label' => $label !== '' ? $label : $name,
                    ];
                }
            }
        }
        if ($unit === 'kg') {
            return ['key' => '重量', 'label' => '重量'];
        }

        return ['key' => '', 'label' => ''];
    }

    /**
     * @param list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}> $paramDefs
     * @return list<array{key:string,label:string}>
     */
    private static function comparableLabels(array $paramDefs): array
    {
        $out = [];
        $seen = [];
        foreach ($paramDefs as $def) {
            $key = trim((string) ($def['param_key'] ?? ''));
            $label = trim((string) ($def['label'] ?? $key));
            if ($key === '' || $label === '') {
                continue;
            }
            foreach ([$label, $key] as $cand) {
                $cand = trim((string) $cand);
                if ($cand === '' || isset($seen[$cand])) {
                    continue;
                }
                $seen[$cand] = true;
                $out[] = ['key' => $key, 'label' => $cand];
            }
            foreach (self::PARAM_KEY_ALIASES[$key] ?? [] as $alias) {
                $alias = trim((string) $alias);
                if ($alias === '' || isset($seen[$alias])) {
                    continue;
                }
                $seen[$alias] = true;
                $out[] = ['key' => $key, 'label' => $alias];
            }
        }
        foreach (array_keys(self::PARAM_KEY_ALIASES) as $alias) {
            if (isset($seen[$alias])) {
                continue;
            }
            $targets = self::PARAM_KEY_ALIASES[$alias];
            $key = $targets[0] ?? $alias;
            $seen[$alias] = true;
            $out[] = ['key' => $key, 'label' => $alias];
        }

        return $out;
    }

    /**
     * @param list<string> $ranges
     * @param list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}> $paramDefs
     * @param list<array{key:string,label:string,op:string,a:float,b?:float}> $comparisons
     */
    private static function bindLabeledRangesToComparisons(
        array $ranges,
        string $working,
        array $paramDefs,
        array &$comparisons,
    ): void {
        if ($ranges === []) {
            return;
        }
        $labels = self::comparableLabels($paramDefs);
        usort($labels, static fn (array $a, array $b): int => mb_strlen($b['label']) <=> mb_strlen($a['label']));
        foreach ($ranges as $rangeText) {
            if (!preg_match('/^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/', $rangeText, $rm)) {
                continue;
            }
            $already = false;
            foreach ($comparisons as $cmp) {
                if (($cmp['op'] ?? '') === 'between'
                    && abs((float) ($cmp['a'] ?? 0) - (float) $rm[1]) < 0.0001
                    && abs((float) ($cmp['b'] ?? 0) - (float) $rm[2]) < 0.0001) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                continue;
            }
            foreach ($labels as $meta) {
                $label = $meta['label'];
                if ($label !== '' && mb_strpos($working, $label) !== false) {
                    $comparisons[] = [
                        'key'   => $meta['key'],
                        'label' => $label,
                        'op'    => 'between',
                        'a'     => (float) $rm[1],
                        'b'     => (float) $rm[2],
                    ];
                    break;
                }
            }
        }
    }

    private static function normalizeQueryText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $text = self::normalizeUnits($text);
        foreach (self::stopWords() as $w) {
            $text = str_replace($w, ' ', $text);
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function normalizeUnits(string $text): string
    {
        $text = preg_replace('/(\d+(?:\.\d+)?)\s*[Vv](?:伏)?/u', '$1V', $text) ?? $text;
        $text = preg_replace('/(\d+(?:\.\d+)?)\s*(?:mm|毫米)/iu', '$1mm', $text) ?? $text;
        $text = preg_replace('/(\d+(?:\.\d+)?)\s*(?:kg|千克|公斤)/iu', '$1kg', $text) ?? $text;
        $text = preg_replace('/(\d+)\s*[~～]\s*(\d+)/u', '$1-$2', $text) ?? $text;
        $text = str_replace(['０', '１', '２', '３', '４', '５', '６', '７', '８', '９'], ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $text);

        return $text;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array{filters:array<string,string>,soft_filters:array<string,string>,tokens:list<string>} $parsed
     * @param list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}> $defs
     * @return list<array<string,mixed>>
     */
    private static function appendTextParamCandidates(array $rows, array $parsed, array $defs, int $limit): array
    {
        $tokens = $parsed['tokens'] ?? [];
        if ($tokens === []) {
            return $rows;
        }
        $textKeys = [];
        foreach ($defs as $def) {
            if ((string) ($def['input_type'] ?? 'text') === 'text') {
                $textKeys[] = (string) ($def['param_key'] ?? '');
            }
        }
        if ($textKeys === []) {
            return $rows;
        }
        $seen = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $seen[(int) ($row['id'] ?? 0)] = true;
            }
        }
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            $like = '%' . addcslashes($token, '%_\\') . '%';
            $extra = Item::where('status', ItemService::STATUS_ACTIVE)
                ->where(function ($q) use ($textKeys, $like, $token) {
                    $q->whereLike('name', $like)->whereOr('code', 'like', $like);
                    foreach ($textKeys as $key) {
                        if ($key !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                            $q->whereOrRaw(
                                "JSON_UNQUOTE(JSON_EXTRACT(`attrs`, '$.\"{$key}\"')) LIKE ?",
                                [$like],
                            );
                        }
                    }
                })
                ->limit($limit * 2)
                ->select()
                ->toArray();
            foreach ($extra as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $id = (int) ($raw['id'] ?? 0);
                if ($id < 1 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $rows[]    = app(WeappItemGateway::class)->itemFormatPublicRow($raw);
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private static function tokenizeResidual(string $working): array
    {
        $working = trim($working);
        if ($working === '') {
            return [];
        }
        $parts  = preg_split('/[\s,，、；;]+/u', $working) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }
            if (preg_match('/^\d+(?:\.\d+)?$/', $part)) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array{
     *   filters:array<string,string>,
     *   soft_filters:array<string,string>,
     *   tokens:list<string>,
     *   ranges:list<string>
     * } $parsed
     * @return list<array{score:int,row:array<string,mixed>}>
     */
    /**
     * @param list<array{param_key:string,label:string,filterable:int,input_type:string,options:list<string>}> $defs
     * @return list<array{score:int,row:array<string,mixed>,reasons:list<string>}>
     */
    /**
     * @return list<string>
     */
    private static function extractProductCodes(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $out = [];
        if (preg_match_all('/[A-Za-z]{1,12}(?:[-_\s]?\d{2,}[A-Za-z0-9_-]*)?/u', $text, $matches)) {
            foreach ($matches[0] as $raw) {
                $raw = trim((string) $raw);
                if ($raw === '' || mb_strlen($raw) < 3) {
                    continue;
                }
                $compact = strtoupper(preg_replace('/[\s_]+/', '-', $raw) ?? $raw);
                $out[]   = $compact;
                $out[]   = str_replace('-', '', $compact);
            }
        }

        return array_values(array_unique(array_filter($out, static fn (string $c): bool => $c !== '')));
    }

    private static function scoreRows(array $rows, array $parsed, array $defs, string $phrase = ''): array
    {
        $phrase     = trim($phrase);
        $allFilters = array_merge($parsed['filters'], $parsed['soft_filters']);
        $clickBoost = app(WeappSearchGateway::class)->smartSearchProductClickScores($phrase);
        $labels     = [];
        foreach ($defs as $def) {
            $key = (string) ($def['param_key'] ?? '');
            if ($key !== '') {
                $labels[$key] = (string) ($def['label'] ?? $key);
            }
        }
        $logicOr = app(WeappSearchGateway::class)->smartSearchParamLogic() === SmartSearchConfigService::PARAM_LOGIC_OR;
        $weights = app(WeappSearchGateway::class)->smartSearchScoreWeights();
        $scored  = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hay       = self::rowHaystack($row);
            $score     = 0;
            $reasons   = [];
            $filterHit = 0;
            $filterTotal = count($allFilters);

            $cmpOk = true;
            foreach ($parsed['comparisons'] ?? [] as $cmp) {
                if (!is_array($cmp)) {
                    continue;
                }
                $eval = self::evalComparisonOnRow($row, $cmp);
                if ($eval === null) {
                    $cmpOk = false;
                    break;
                }
                if ($eval === false) {
                    $cmpOk = false;
                    break;
                }
                $score += (int) round(14 * $weights['product'] / 40);
                $reasons[] = self::comparisonReason($cmp);
            }
            if (!$cmpOk) {
                continue;
            }

            foreach ($allFilters as $key => $val) {
                $raw = ($row['attrs'] ?? [])[$key] ?? '';
                if (is_array($raw) || is_object($raw)) {
                    continue;
                }
                $v = trim((string) $raw);
                if ($v === $val) {
                    $score += (int) round(12 * $weights['product'] / 40);
                    $filterHit++;
                    $reasons[] = ($labels[$key] ?? $key) . '=' . $val;
                } elseif ($v !== '' && (mb_strpos($v, $val) !== false || mb_strpos($val, $v) !== false)) {
                    $score += (int) round(8 * $weights['product'] / 40);
                    $filterHit++;
                    $reasons[] = ($labels[$key] ?? $key) . '≈' . $val;
                }
            }
            if ($logicOr && $filterTotal > 0 && $filterHit === 0) {
                continue;
            }
            foreach ($parsed['ranges'] as $range) {
                if ($range !== '' && mb_strpos($hay, $range) !== false) {
                    $score += 6;
                    $reasons[] = '区间 ' . $range;
                }
            }
            foreach ($parsed['tokens'] as $token) {
                if ($token !== '' && mb_strpos($hay, $token) !== false) {
                    $score += (int) round(5 * $weights['keyword'] / 35);
                    $reasons[] = '关键词「' . $token . '」';
                }
            }
            if ($phrase !== '' && mb_strlen($phrase) >= 2 && mb_strpos($hay, $phrase) !== false) {
                $score += (int) round(18 * $weights['keyword'] / 35);
                $reasons[] = '整句相关';
            }
            $itemId = (int) ($row['id'] ?? 0);
            if ($itemId > 0 && isset($clickBoost[$itemId])) {
                $score += min(12, (int) $clickBoost[$itemId] * 3);
                $reasons[] = '近期点击偏好';
            }
            if (!empty($row['_meili_hit'])) {
                $score += (int) round(6 * $weights['keyword'] / 35);
                $reasons[] = '全文索引';
            }
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            foreach ($parsed['tokens'] as $token) {
                $t = strtoupper(preg_replace('/[\s_]+/', '', (string) $token) ?? '');
                if ($t !== '' && $code !== '' && ($code === $t || str_contains($code, $t) || str_contains($t, $code))) {
                    $score += (int) round(14 * $weights['keyword'] / 35);
                    $reasons[] = '货号「' . $token . '」';
                    break;
                }
            }
            if ($score < 1 && ($parsed['tokens'] !== [] || $allFilters !== [])) {
                $score = 1;
            }
            $scored[] = ['score' => $score, 'row' => $row, 'reasons' => array_slice(array_unique($reasons), 0, 6)];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * @param array{key?:string,label?:string,op?:string,a?:float|int,b?:float|int} $cmp
     */
    private static function comparisonReason(array $cmp): string
    {
        $label = (string) ($cmp['label'] ?? $cmp['key'] ?? '');
        $op = (string) ($cmp['op'] ?? '');
        $a = $cmp['a'] ?? '';
        return match ($op) {
            'lt' => $label . '<' . $a,
            'lte' => $label . '≤' . $a,
            'gt' => $label . '>' . $a,
            'gte' => $label . '≥' . $a,
            'between' => $label . ' ' . $a . '-' . ($cmp['b'] ?? ''),
            default => $label . $op . $a,
        };
    }

    /**
     * @param array<string,mixed> $row
     * @param array{key?:string,label?:string,op?:string,a?:float|int,b?:float|int} $cmp
     */
    private static function evalComparisonOnRow(array $row, array $cmp): ?bool
    {
        $key = trim((string) ($cmp['key'] ?? ''));
        $op = (string) ($cmp['op'] ?? '');
        if ($key === '' || $op === '' || !isset($cmp['a']) || !is_numeric($cmp['a'])) {
            return null;
        }
        $num = self::attrNumericValue($row, $key);
        if ($num === null) {
            return null;
        }
        $a = (float) $cmp['a'];
        return match ($op) {
            'lt' => $num < $a,
            'lte' => $num <= $a,
            'gt' => $num > $a,
            'gte' => $num >= $a,
            'between' => isset($cmp['b']) && is_numeric($cmp['b'])
                ? ($num >= min($a, (float) $cmp['b']) && $num <= max($a, (float) $cmp['b']))
                : null,
            default => null,
        };
    }

    /** @param array<string,mixed> $row */
    private static function attrNumericValue(array $row, string $key): ?float
    {
        $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
        foreach (self::resolveAttrKeys($key) as $cand) {
            $raw = $attrs[$cand] ?? null;
            if (is_array($raw) || is_object($raw) || $raw === null || $raw === '') {
                continue;
            }
            $parsed = self::parseLeadingNumber((string) $raw);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function resolveAttrKeys(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return [];
        }
        $keys = self::PARAM_KEY_ALIASES[$key] ?? [$key];
        if (!in_array($key, $keys, true)) {
            array_unshift($keys, $key);
        }

        return array_values(array_unique($keys));
    }

    private static function parseLeadingNumber(string $raw): ?float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // >650MM / ≥735mm / ≤18kg / 16.5KG / 63或 72MPA → 取第一个有效数字
        if (preg_match('/([<>≤≥]=?)?\s*(\d+(?:\.\d+)?)/u', $raw, $m) !== 1) {
            return null;
        }

        return (float) $m[2];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowHaystack(array $row): string
    {
        $parts = [
            (string) ($row['name'] ?? ''),
            (string) ($row['code'] ?? ''),
            (string) ($row['attrs_summary_text'] ?? ''),
        ];
        $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
        foreach ($attrs as $k => $v) {
            if (is_array($v) || is_object($v)) {
                continue;
            }
            if ($v === null || $v === '') {
                continue;
            }
            $parts[] = (string) $k . ' ' . (string) $v;
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array{id:int,title:string,summary:string,url:string,type:string}>
     */
    public static function toKnowledgeSources(array $items): array
    {
        $sources = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $url = trim((string) ($row['card_url'] ?? $row['page_url'] ?? ''));
            if ($url === '' && ($slug = trim((string) ($row['slug'] ?? ''))) !== '') {
                $url = SiteUrl::productItem($slug);
            }
            $summary = trim((string) ($row['attrs_summary_text'] ?? ''));
            $code    = trim((string) ($row['code'] ?? ''));
            if ($code !== '') {
                $summary = ($summary !== '' ? $summary . ' · ' : '') . '货号 ' . $code;
            }
            $sources[] = [
                'id'      => (int) ($row['id'] ?? 0),
                'title'   => $name,
                'summary' => $summary,
                'url'     => $url,
                'type'    => 'product',
            ];
        }

        return $sources;
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    public static function buildContextLines(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $lines = ['【在售品项】共 ' . count($items) . ' 条（匹配站点自定义参数定义）'];
        $n     = 0;
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $n++;
            $name = trim((string) ($row['name'] ?? ''));
            $spec = trim((string) ($row['attrs_summary_text'] ?? ''));
            $url  = trim((string) ($row['card_url'] ?? $row['page_url'] ?? ''));
            $line = $n . '. ' . $name;
            if ($spec !== '') {
                $line .= '（' . mb_substr($spec, 0, 120) . '）';
            }
            if ($url !== '') {
                $line .= ' [' . $url . ']';
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
