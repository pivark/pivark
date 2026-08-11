<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\ServiceResult;

use app\common\service\config\ConfigService;

/** 问句同义词展开（配置驱动，P0） */
final class SmartSearchSynonymService
{

    public function __construct(
        private readonly SmartSearchConfigService $smartSearch,
        private readonly ConfigService $config,
    ) {
    }

    public function expand(string $keyword): string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return '';
        }
        $rows = $this->smartSearch->synonyms();
        usort($rows, static fn (array $a, array $b): int => mb_strlen($b['from']) <=> mb_strlen($a['from']));
        foreach ($rows as $row) {
            $from = $row['from'];
            $to   = $row['to'];
            if ($from === '' || $to === '' || !str_contains($keyword, $from)) {
                continue;
            }
            $keyword = str_replace($from, $to, $keyword);
        }

        return trim(preg_replace('/\s+/u', ' ', $keyword) ?? $keyword);
    }

    /**
     * 将带 param 的同义词写入解析结果（效果优化：别名→标准选项值）
     *
     * @param array{
     *   filters:array<string,string>,
     *   soft_filters:array<string,string>,
     *   tokens:list<string>,
     *   ranges:list<string>
     * } $parsed
     * @param list<array{param_key:string,filterable?:int}> $paramDefs
     * @return array{
     *   filters:array<string,string>,
     *   soft_filters:array<string,string>,
     *   tokens:list<string>,
     *   ranges:list<string>
     * }
     */
    public function applyToParsed(array $parsed, array $paramDefs = []): array
    {
        $filterable = [];
        foreach ($paramDefs as $def) {
            $key = $def['param_key'];
            if ($key !== '') {
                $filterable[$key] = (int) ($def['filterable'] ?? 0) === 1;
            }
        }

        $filters = $parsed['filters'];
        $soft    = $parsed['soft_filters'];
        $tokens  = $parsed['tokens'];
        $ranges  = $parsed['ranges'];

        foreach ($this->smartSearch->synonyms() as $row) {
            $from  = trim($row['from']);
            $to    = trim($row['to']);
            $param = trim((string) ($row['param'] ?? ''));
            if ($from === '' || $to === '' || $param === '' || !isset($filterable[$param])) {
                continue;
            }
            if (($filters[$param] ?? '') === $from) {
                $filters[$param] = $to;
            }
            if (($soft[$param] ?? '') === $from) {
                $soft[$param] = $to;
            }
            foreach ($tokens as $idx => $token) {
                if ($token !== $from) {
                    continue;
                }
                if ($filterable[$param]) {
                    $filters[$param] = $to;
                } else {
                    $soft[$param] = $to;
                }
                unset($tokens[$idx]);
            }
        }

        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => trim($t) !== ''));

        return [
            'filters'      => $filters,
            'soft_filters' => $soft,
            'tokens'       => $tokens,
            'ranges'       => $ranges,
            'comparisons'  => is_array($parsed['comparisons'] ?? null) ? $parsed['comparisons'] : [],
        ];
    }

    /**
     * @return ServiceResult
     */
    public function append(string $from, string $to, string $param = ''): ServiceResult
    {
        $from = trim($from);
        $to   = trim($to);
        $param = trim($param);
        if ($from === '' || $to === '' || $from === $to) {
            return ServiceResult::fail('原词与目标词不能为空且不能相同');
        }
        $list = $this->smartSearch->synonyms();
        foreach ($list as $row) {
            if ($row['from'] === $from && (string) ($row['param'] ?? '') === $param) {
                return ServiceResult::fail('同义词已存在');
            }
        }
        $item = ['from' => $from, 'to' => $to];
        if ($param !== '') {
            $item['param'] = $param;
        }
        $list[] = $item;
        $this->config->set(
            'search_smart_synonyms_json',
            json_encode($list, JSON_UNESCAPED_UNICODE) ?: '[]',
        );

        return ServiceResult::ok($list, '已添加同义词');
    }
}
