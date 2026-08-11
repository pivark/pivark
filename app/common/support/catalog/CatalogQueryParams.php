<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support\catalog;

/** 前台/开放 API 列表查询参数（items / erp 流水 / shop 订单等共用） */
final class CatalogQueryParams
{
    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function parse(array $raw): array
    {
        $params = [
            'page'       => max(1, (int) ($raw['page'] ?? 1)),
            'limit'      => min(max((int) ($raw['limit'] ?? 20), 1), 100),
            'tag'        => trim((string) ($raw['tag'] ?? '')),
            'nav_id'     => max(0, (int) ($raw['nav_id'] ?? 0)),
            'keyword'    => trim((string) ($raw['keyword'] ?? $raw['q'] ?? '')),
            'cursor'     => trim((string) ($raw['cursor'] ?? '')),
            'skip_total'   => self::truthy($raw['skip_total'] ?? false) ? 1 : 0,
            'filters_only' => self::truthy($raw['filters_only'] ?? false) ? 1 : 0,
        ];
        foreach ($raw as $k => $v) {
            $key = (string) $k;
            if (str_starts_with($key, 'filter_') && trim((string) $v) !== '') {
                $params[$key] = trim((string) $v);
            }
        }
        if ($params['keyword'] === '') {
            unset($params['keyword']);
        }
        // 有真分类时不把 tag 当归属（聚合筛选另议；catalog 直传 nav_id）
        if ($params['nav_id'] > 0) {
            unset($params['tag']);
        } elseif ($params['tag'] === '') {
            unset($params['tag']);
        }
        if ($params['nav_id'] < 1) {
            unset($params['nav_id']);
        }
        if ($params['cursor'] === '') {
            unset($params['cursor']);
        }
        if ($params['skip_total'] === 0) {
            unset($params['skip_total']);
        }
        if ($params['filters_only'] === 0) {
            unset($params['filters_only']);
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public static function filterOnly(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $key = (string) $k;
            if (str_starts_with($key, 'filter_') && trim((string) $v) !== '') {
                $out[$key] = trim((string) $v);
            }
        }
        ksort($out);

        return $out;
    }

    /** @param mixed $v */
    public static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }

        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }
}
