<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\catalog\Handlers;

use app\common\service\catalog\CatalogQueryService;
use app\common\support\catalog\CatalogQueryHandlerInterface;
use app\common\support\DbTable;

/**
 * ERP 进出库流水域（预留）
 * 表：erp_inventory_ledger(item_id, warehouse_id, qty, direction, biz_type, created_at)
 * 筛选：filter_warehouse / filter_direction / filter_biz_type + tag(item) + keyword
 */
final class ErpInventoryLedgerCatalogHandler implements CatalogQueryHandlerInterface
{
    public function domain(): string
    {
        return 'erp_inventory_ledger';
    }

    public function isAvailable(): bool
    {
        return self::tableExists();
    }

    public function list(array $params): array
    {
        if (!self::tableExists()) {
            return app(CatalogQueryService::class)->emptyList($params);
        }
        // ERP 插件落地时在此实现：游标按 id 降序、skip_total、仓库索引
        return app(CatalogQueryService::class)->emptyList($params);
    }

    public function filterOptions(array $context = []): array
    {
        return [];
    }

    private static function tableExists(): bool
    {
        return DbTable::exists('erp_inventory_ledger');
    }
}
