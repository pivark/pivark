<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\catalog\Handlers;

use app\common\support\catalog\CatalogQueryHandlerInterface;
use app\common\service\item\ItemCatalogPublicService;
use app\common\service\item\ItemPublicGateway;
use app\common\service\product\OfferBridgeFacade;
use app\common\service\product\ProductCenterGateService;

/** 品项域（product 目录 / items API） */
final class ItemCatalogQueryHandler implements CatalogQueryHandlerInterface
{
    public function domain(): string
    {
        return 'items';
    }

    public function isAvailable(): bool
    {
        // 表/域常在；无 Pro 时由 ItemPublicListService / filterOptions 静默空列表，
        // 勿用 CatalogQueryService「模块未安装」误导（种子已灌，只是档位未开）。
        return true;
    }

    public function list(array $params): array
    {
        $result = app(ItemPublicGateway::class)->listPublic($params);
        app(OfferBridgeFacade::class)->attachOfferSummariesToItems($result['list']);

        return $result;
    }

    public function filterOptions(array $context = []): array
    {
        return app(ItemCatalogPublicService::class)->filterOptions($context);
    }
}
