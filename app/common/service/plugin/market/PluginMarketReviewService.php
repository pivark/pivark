<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;

use app\common\support\ProjectPaths;

/**
 * 插件市场评分摘要（本地 JSON · 只读）
 * HTTP 评论写面已退役；禁止再挂 submit/list 路由。
 */
final class PluginMarketReviewService
{
    private const FILE = 'plugin_market_reviews.json';

    /**
     * @return array{avg_rating:float,review_count:int}
     */
    public function summary(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        $rows       = $this->load()['reviews'][$identifier] ?? [];
        if (!is_array($rows) || $rows === []) {
            return ['avg_rating' => 0.0, 'review_count' => 0];
        }

        $sum = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sum += max(1, min(5, (int) ($row['rating'] ?? 0)));
        }

        return [
            'avg_rating'    => round($sum / count($rows), 1),
            'review_count'  => count($rows),
        ];
    }

    /**
     * @return array{reviews:array<string,list<array<string,mixed>>>}
     */
    private function load(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return ['reviews' => []];
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : ['reviews' => []];
    }

    private function path(): string
    {
        return rtrim(ProjectPaths::runtimeDir(), '/\\') . DIRECTORY_SEPARATOR . self::FILE;
    }
}
