<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\search\ItemCatalogSearchDriverInterface;

final class SqlItemCatalogSearchDriver implements ItemCatalogSearchDriverInterface
{
    public function name(): string
    {
        return ItemCatalogSearchDriverFactory::DRIVER_SQL;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function strictKeyword(): bool
    {
        return false;
    }

    public function searchIds(string $keyword, int $limit = 24, array $context = []): array
    {
        return [];
    }
}
