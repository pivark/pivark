<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;
use app\common\support\QueryLimit;

use app\common\service\config\ConfigService;

/** 超级搜索增强配置（P0–P5，开源站点自定义参数驱动） */
final class SmartSearchConfigService
{

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    public const RELAX_DROP_FILTERS = 'drop_filters';
    public const RELAX_KEYWORD_ONLY  = 'keyword_only';
    public const PARAM_LOGIC_AND     = 'and';
    public const PARAM_LOGIC_OR      = 'or';

    /** 后台未配置示例问法时的前台兜底（R6-3） */
    private const DEFAULT_EXAMPLE_QUERIES = [
        '产品型号',
        '安装教程',
        '常见问题',
        '资料下载',
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return [
            'search_smart_on',
            'search_smart_fallback_on',
            'search_smart_synonyms_json',
            'search_smart_examples_json',
            'search_smart_parse_debug_on',
            'search_smart_vector_on',
            'search_smart_cache_ttl',
            'search_smart_session_on',
            'search_smart_relax_mode',
            'search_smart_param_logic',
            'search_smart_weight_keyword',
            'search_smart_weight_vector',
            'search_smart_weight_product',
            'search_smart_lang',
            'search_smart_embed_on',
            'search_meili_items_index',
        ];
    }

    public function defaultFor(string $key): string
    {
        return match ($key) {
            'search_smart_on'              => '1',
            'search_smart_fallback_on'     => '1',
            'search_smart_synonyms_json'    => '[]',
            'search_smart_examples_json'   => '[]',
            'search_smart_parse_debug_on'  => '0',
            'search_smart_vector_on'       => '1',
            'search_smart_cache_ttl'       => '300',
            'search_smart_session_on'      => '1',
            'search_smart_relax_mode'      => self::RELAX_DROP_FILTERS,
            'search_smart_param_logic'     => self::PARAM_LOGIC_AND,
            'search_smart_weight_keyword'  => '35',
            'search_smart_weight_vector'   => '25',
            'search_smart_weight_product'  => '40',
            'search_smart_lang'            => 'zh',
            'search_smart_embed_on'        => '1',
            'search_meili_items_index'     => 'items',
            default                        => '',
        };
    }

    public function embeddingEnabled(): bool
    {
        return $this->isEnabled()
            && (string) $this->config->get('search_smart_embed_on', '1') === '1';
    }

    public function isEnabled(): bool
    {
        return (string) $this->config->get('search_smart_on', $this->defaultFor('search_smart_on')) === '1';
    }

    public function fallbackEnabled(): bool
    {
        return (string) $this->config->get('search_smart_fallback_on', '1') === '1';
    }

    public function vectorEnabled(): bool
    {
        return $this->isEnabled()
            && (string) $this->config->get('search_smart_vector_on', '1') === '1';
    }

    public function sessionEnabled(): bool
    {
        return $this->isEnabled()
            && (string) $this->config->get('search_smart_session_on', '1') === '1';
    }

    public function parseDebugEnabled(): bool
    {
        return (string) $this->config->get('search_smart_parse_debug_on', '0') === '1';
    }

    public function lang(): string
    {
        $v = strtolower(trim((string) $this->config->get('search_smart_lang', $this->defaultFor('search_smart_lang'))));

        return $v === 'en' ? 'en' : 'zh';
    }

    public function cacheTtl(): int
    {
        return max(0, min(3600, (int) $this->config->get('search_smart_cache_ttl', '300')));
    }

    public function relaxMode(): string
    {
        $v = (string) $this->config->get('search_smart_relax_mode', self::RELAX_DROP_FILTERS);

        return in_array($v, [self::RELAX_DROP_FILTERS, self::RELAX_KEYWORD_ONLY], true)
            ? $v
            : self::RELAX_DROP_FILTERS;
    }

    public function paramLogic(): string
    {
        $v = (string) $this->config->get('search_smart_param_logic', self::PARAM_LOGIC_AND);

        return $v === self::PARAM_LOGIC_OR ? self::PARAM_LOGIC_OR : self::PARAM_LOGIC_AND;
    }

    /** @return array{keyword:int,vector:int,product:int} */
    public function scoreWeights(): array
    {
        $kw  = max(0, min(100, (int) $this->config->get('search_smart_weight_keyword', '35')));
        $vec = max(0, min(100, (int) $this->config->get('search_smart_weight_vector', '25')));
        $prd = max(0, min(100, (int) $this->config->get('search_smart_weight_product', '40')));
        $sum = $kw + $vec + $prd;

        return $sum > 0
            ? ['keyword' => $kw, 'vector' => $vec, 'product' => $prd]
            : ['keyword' => 35, 'vector' => 25, 'product' => 40];
    }

    /** @return list<array{from:string,to:string,param?:string}> */
    public function synonyms(): array
    {
        $raw = (string) $this->config->get('search_smart_synonyms_json', '[]');
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $this->normalizeSynonyms($decoded) : [];
    }

    /** @return list<string> */
    public function exampleQueries(): array
    {
        $raw = (string) $this->config->get('search_smart_examples_json', '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            $s = trim(is_string($item) ? $item : (string) ($item['q'] ?? $item['query'] ?? ''));
            if ($s !== '') {
                $out[] = $s;
            }
        }
        if ($out === []) {
            return self::DEFAULT_EXAMPLE_QUERIES;
        }

        return array_slice($out, 0, QueryLimit::FRONT_LIST);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public function extractSavePayload(array $data): array
    {
        $payload = [];
        foreach ($this->keys() as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $payload[$key] = match ($key) {
                'search_smart_on',
                'search_smart_fallback_on',
                'search_smart_parse_debug_on',
                'search_smart_vector_on',
                'search_smart_session_on',
                'search_smart_embed_on' => !empty($data[$key]) ? '1' : '0',
                'search_smart_cache_ttl' => (string) max(0, min(3600, (int) ($data[$key] ?? 300))),
                'search_smart_relax_mode' => in_array((string) ($data[$key] ?? ''), [self::RELAX_DROP_FILTERS, self::RELAX_KEYWORD_ONLY], true)
                    ? (string) $data[$key]
                    : self::RELAX_DROP_FILTERS,
                'search_smart_param_logic' => ((string) ($data[$key] ?? '')) === self::PARAM_LOGIC_OR
                    ? self::PARAM_LOGIC_OR
                    : self::PARAM_LOGIC_AND,
                'search_smart_synonyms_json',
                'search_smart_examples_json' => $this->encodeJsonField($data[$key] ?? '[]'),
                'search_meili_items_index' => trim((string) ($data[$key] ?? $this->defaultFor('search_meili_items_index'))) ?: 'items',
                default => trim((string) ($data[$key] ?? $this->defaultFor($key))),
            };
        }

        return $payload;
    }

    /**
     * @param mixed $value
     */
    private function encodeJsonField($value): string
    {
        if (is_string($value)) {
            $trim = trim($value);

            return $trim !== '' ? $trim : '[]';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        return '[]';
    }

    /**
     * @param array<mixed, mixed> $rows
     * @return list<array{from:string,to:string,param?:string}>
     */
    private function normalizeSynonyms(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $from = trim((string) ($row['from'] ?? $row['alias'] ?? ''));
            $to   = trim((string) ($row['to'] ?? $row['value'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }
            $item = ['from' => $from, 'to' => $to];
            $param = trim((string) ($row['param'] ?? $row['param_key'] ?? ''));
            if ($param !== '') {
                $item['param'] = $param;
            }
            $out[] = $item;
        }

        return $out;
    }
}
