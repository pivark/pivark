<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\service\item\ItemPublicGateway;

/** 搜索建议：示例问法 + 参数选项前缀（P1/P5） */
final class SmartSearchSuggestService
{

    public function __construct(
        private readonly SmartSearchConfigService $smartSearch,
        private readonly SearchQueryLogService $queryLog,
    ) {
    }

    /**
     * @return list<string>
     */
    public function suggestions(string $prefix, int $limit = 8): array
    {
        $prefix = trim($prefix);
        $limit  = max(1, min(20, $limit));
        $pool   = $this->smartSearch->exampleQueries();

        if ($prefix === '') {
            foreach ($this->queryLog->topZeroKeywords(14, 6) as $row) {
                $kw = trim($row['keyword']);
                if ($kw !== '') {
                    $pool[] = $kw;
                }
            }
        }

        if (class_exists(\app\common\service\product\ProductSmartSearchService::class)
            && \app\common\service\product\ProductSmartSearchService::isAvailable()) {
            foreach (app(ItemPublicGateway::class)->filterOptions([]) as $def) {
                $label = trim((string) ($def['label'] ?? ''));
                foreach (is_array($def['options'] ?? null) ? $def['options'] : [] as $opt) {
                    if (is_array($opt)) {
                        $opt = trim((string) ($opt['label'] ?? $opt['value'] ?? $opt['text'] ?? ''));
                    } else {
                        $opt = trim((string) $opt);
                    }
                    if ($opt === '') {
                        continue;
                    }
                    $pool[] = $label !== '' ? $label . $opt : $opt;
                }
            }
        }

        $pool = array_values(array_unique($pool));
        if ($prefix === '') {
            return array_slice($pool, 0, $limit);
        }
        $starts = [];
        $contains = [];
        foreach ($pool as $item) {
            if (mb_strpos($item, $prefix) === 0) {
                $starts[] = $item;
            } elseif (mb_strpos($item, $prefix) !== false) {
                $contains[] = $item;
            }
        }

        return array_slice(array_merge($starts, $contains), 0, $limit);
    }
}
