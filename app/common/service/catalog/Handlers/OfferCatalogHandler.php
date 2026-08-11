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
use app\common\service\product\OfferBridgeFacade;
use app\common\support\catalog\CatalogQueryHandlerInterface;

/** 插件可售报价 catalog 域（L1 无表名/插件 ID，委托 OfferBridgeFacade） */
final class OfferCatalogHandler implements CatalogQueryHandlerInterface
{
    public function domain(): string
    {
        return 'plugin_offers';
    }

    public function isAvailable(): bool
    {
        return app(OfferBridgeFacade::class)->enabled();
    }

    public function list(array $params): array
    {
        if (!$this->isAvailable() || !app(OfferBridgeFacade::class)->isOpen()) {
            return app(CatalogQueryService::class)->emptyList($params);
        }

        return app(OfferBridgeFacade::class)->catalogList($params);
    }

    public function filterOptions(array $context = []): array
    {
        return [];
    }
}
