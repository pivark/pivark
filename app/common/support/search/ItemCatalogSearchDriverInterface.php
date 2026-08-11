<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support\search;

/** 品项目录关键词驱动（Phase C 扩展点） */
interface ItemCatalogSearchDriverInterface
{
    public function name(): string;

    public function isAvailable(): bool;

    /** 仅外部引擎：true 表示 keyword 无命中时不回退 SQL */
    public function strictKeyword(): bool;

    /**
     * @param array<string, mixed> $context filter_* 等（Meili filterable attr）
     * @return list<int>
     */
    public function searchIds(string $keyword, int $limit = 24, array $context = []): array;
}
