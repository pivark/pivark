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
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\support\catalog\CatalogQueryHandlerInterface;
use app\common\support\DbTable;

/**
 * MES 工单域（预留）
 * 表：mes_work_orders(item_id, status, line_id, …)
 */
final class MesWorkOrderCatalogHandler implements CatalogQueryHandlerInterface
{
    public function domain(): string
    {
        return 'mes_work_orders';
    }

    public function isAvailable(): bool
    {
        return app(EntitlementService::class)->can('mes') && self::tableExists();
    }

    public function list(array $params): array
    {
        if (!$this->isAvailable()) {
            return app(CatalogQueryService::class)->emptyList($params);
        }

        return app(CatalogQueryService::class)->emptyList($params);
    }

    public function filterOptions(array $context = []): array
    {
        return [];
    }

    private static function tableExists(): bool
    {
        return DbTable::exists('mes_work_orders');
    }
}
