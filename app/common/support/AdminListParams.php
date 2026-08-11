<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/**
 * 后台列表 Ajax 通用分页/关键词参数。
 * @see docs/03-开发/后台列表Composable参考.md#e1-adminlistparams
 */
final class AdminListParams
{
    /**
     * @param array<string, mixed> $params
     * @return array{page:int,limit:int,keyword:string}
     */
    public static function parse(array $params): array
    {
        return [
            'page'    => max(1, (int) ($params['page'] ?? 1)),
            'limit'   => min(max((int) ($params['limit'] ?? 20), 1), 100),
            'keyword' => trim((string) ($params['keyword'] ?? $params['q'] ?? '')),
        ];
    }

    /**
     * @param \think\db\BaseQuery|\think\db\Query $query
     */
    public static function applyKeyword($query, string $keyword, string $fields): void
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return;
        }
        $like = '%' . addcslashes($keyword, '%_\\') . '%';
        $query->whereLike($fields, $like);
    }
}
