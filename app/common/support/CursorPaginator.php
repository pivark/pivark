<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use think\db\Query;

/** 大表游标分页（id 递减；allowCursor 时跳过 count，含首页） */
final class CursorPaginator
{
    /**
     * @return array{rows:list<array<string,mixed>>,total:int,next_cursor_id:int,has_more:int}
     */
    public static function paginateById(
        Query $query,
        int $limit,
        int $page = 1,
        int $cursorId = 0,
        string $orderColumn = 'id',
        bool $allowCursor = true,
        ?string $cursorColumn = null,
    ): array {
        $limit = max(1, min(100, $limit));
        $page  = max(1, $page);
        $cursorColumn = $cursorColumn ?? $orderColumn;

        if ($allowCursor) {
            $q = (clone $query);
            if ($cursorId > 0) {
                $q->where($cursorColumn, '<', $cursorId);
            }
            $fetch = $q->order($orderColumn, 'desc')
                ->limit($limit + 1)
                ->select()
                ->toArray();
            $hasMore = count($fetch) > $limit;
            $rows = $hasMore ? array_slice($fetch, 0, $limit) : $fetch;
            $nextCursor = 0;
            if ($rows !== []) {
                $last = $rows[count($rows) - 1];
                $nextCursor = (int) ($last[$cursorColumn] ?? 0);
            }

            return [
                'rows'           => $rows,
                'total'          => -1,
                'next_cursor_id' => $nextCursor,
                'has_more'       => $hasMore ? 1 : 0,
            ];
        }

        $total = (int) (clone $query)->count();
        $rows  = $query->order($orderColumn, 'desc')->page($page, $limit)->select()->toArray();
        $nextCursor = 0;
        if ($rows !== []) {
            $last       = $rows[count($rows) - 1];
            $nextCursor = (int) ($last[$cursorColumn] ?? 0);
        }

        return [
            'rows'           => $rows,
            'total'          => $total,
            'next_cursor_id' => $nextCursor,
            'has_more'       => ($page * $limit) < $total ? 1 : 0,
        ];
    }
}
