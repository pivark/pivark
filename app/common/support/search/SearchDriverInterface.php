<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 文档全文搜索驱动接口（系统级，非插件）
 *
 * @see docs/03-开发/后台列表Composable参考.md
 * @see docs/06_技术决策/文档全文搜索引擎选型.md
 */
declare(strict_types=1);

namespace app\common\support\search;

/** 关键词全文检索驱动：sql | meili | elastic（预留） */
interface SearchDriverInterface
{
    public function name(): string;

    /** 引擎是否可用（配置正确且可连通） */
    public function isAvailable(): bool;

    /**
     * 前台关键词搜索，返回文档 ID 列表（已排序）与总数。
     *
     * @param array<string, mixed> $context 预留：会员等级等
     * @return array{ids:list<int>,total:int}
     */
    public function searchDocumentIds(string $keyword, int $page, int $limit, array $context = []): array;

    /** 写入/更新单条索引文档 */
    public function upsert(array $record): void;

    /** 从索引移除 */
    public function delete(int $documentId): void;

    /**
     * 全量重建（批量 upsert）。
     *
     * @param iterable<int, array<string, mixed>> $records
     */
    public function reindexBatch(iterable $records): int;
}
