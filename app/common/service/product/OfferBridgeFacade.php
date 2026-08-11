<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\support\ServiceResult;

/** 品项报价与可售 overlay（委托已注册的报价扩展） */
final class OfferBridgeFacade
{
    public const POST_KEY_SINGLE = 'product_item_offer';

    public const POST_KEY_SINGLE_JSON = 'product_item_offer_json';

    private const FACADE = 'OfferCommerceFacade';

    private const OVERLAY = 'ItemAdminOfferOverlayService';

    public function enabled(): bool
    {
        $id = app(PluginOfferBridgeRegistry::class)->identifier();
        if ($id === null || $id === '') {
            return false;
        }

        return app(EntitlementService::class)->can($id)
            && is_dir(ROOT_PATH . 'weapp/' . $id);
    }

    public function isOpen(): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass(self::FACADE);

        return $cls !== null && method_exists($cls, 'isOpen') && (bool) $cls::isOpen();
    }

    public function hasOffersForDocument(int $documentId): bool
    {
        if ($documentId < 1 || !$this->isOpen()) {
            return false;
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass(self::FACADE);
        if ($cls === null) {
            return false;
        }
        $itemId = (int) $cls::primaryItemIdForDocument($documentId);
        if ($itemId < 1) {
            return false;
        }

        return $cls::listOffersPublic($itemId) !== [];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function attachOfferSummariesToItems(array &$items): void
    {
        if ($items === [] || !$this->isOpen()) {
            return;
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass(self::FACADE);
        if ($cls !== null && method_exists($cls, 'attachOfferSummariesToItems')) {
            $cls::attachOfferSummariesToItems($items);
        }
    }

    public function expirePendingOrders(): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass(self::FACADE);

        return ($cls !== null && method_exists($cls, 'expirePendingOrders'))
            ? (int) $cls::expirePendingOrders()
            : 0;
    }

    /** @return ServiceResult */
    public function getOrderForMember(int $memberId, string $orderNo): ServiceResult
    {
        if ($memberId < 1 || !$this->isOpen()) {
            return ServiceResult::fail('在线报价未启用');
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass(self::FACADE);
        if ($cls === null) {
            return ServiceResult::fail('服务不可用');
        }

        return $cls::getOrderForMember($memberId, $orderNo);
    }

    /** @return array{list:list<array<string,mixed>>,total:int} */
    public function listOrdersForMember(int $memberId, int $page, int $limit): array
    {
        if ($memberId < 1 || !$this->isOpen()) {
            return ['list' => [], 'total' => 0];
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass(self::FACADE);
        if ($cls === null) {
            return ['list' => [], 'total' => 0];
        }
        $result = $cls::listOrdersForMember($memberId, $page, $limit);
        if (!$result->isOk()) {
            return ['list' => [], 'total' => 0];
        }
        $data = $result->dataArray();

        return [
            'list'  => is_array($data['list'] ?? null) ? $data['list'] : [],
            'total' => (int) ($data['total'] ?? 0),
        ];
    }

    public function logisticsEnabledForMemberOrder(): int
    {
        if (!$this->isOpen()) {
            return 0;
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass(self::FACADE);
        if ($cls === null) {
            return 0;
        }
        $cfg = $cls::logisticsConfig();

        return (int) ($cfg['enabled'] ?? 0);
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, array<string, mixed>>
     */
    public function adminListOverlayByItemIds(array $itemIds): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass(self::OVERLAY);

        return ($cls !== null && method_exists($cls, 'adminListOverlayByItemIds'))
            ? $cls::adminListOverlayByItemIds($itemIds)
            : [];
    }

    /**
     * @param list<int> $variantIds
     * @return array<int, array<string, mixed>>
     */
    public function adminOverlayByVariantIds(array $variantIds): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass(self::OVERLAY);

        return ($cls !== null && method_exists($cls, 'adminOverlayByVariantIds'))
            ? $cls::adminOverlayByVariantIds($variantIds)
            : [];
    }

    public function syncSellableSkuStatusForItem(int $itemId, bool $on): void
    {
        if (!$this->enabled() || $itemId < 1) {
            return;
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass(self::OVERLAY);
        if ($cls !== null && method_exists($cls, 'syncSellableSkuStatusForItem')) {
            $cls::syncSellableSkuStatusForItem($itemId, $on);
        }
    }

    public function linkSkuByVariantCode(int $itemId, int $variantId, string $variantCode): void
    {
        if (!$this->enabled() || $itemId < 1 || $variantId < 1 || trim($variantCode) === '') {
            return;
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass(self::OVERLAY);
        if ($cls !== null && method_exists($cls, 'linkSkuByVariantCode')) {
            $cls::linkSkuByVariantCode($itemId, $variantId, $variantCode);
        }
    }

    public function catalogList(array $params): array
    {
        if (!$this->enabled() || !$this->isOpen()) {
            return ['list' => [], 'total' => 0, 'page' => 1, 'limit' => 20];
        }
        $this->ensureLoaded();
        $cls = $this->offerServiceClass('OfferCatalogQueryService');
        if ($cls === null || !method_exists($cls, 'list')) {
            return ['list' => [], 'total' => 0, 'page' => 1, 'limit' => 20];
        }

        return $cls::list($params);
    }

    public function bootstrap(): void
    {
        $this->ensureLoaded();
    }

    /** @return class-string|null */
    private function offerServiceClass(string $shortName): ?string
    {
        $id = app(PluginOfferBridgeRegistry::class)->identifier();
        if ($id === null) {
            return null;
        }
        $class = 'weapp\\' . $id . '\\service\\' . $shortName;

        return class_exists($class) ? $class : null;
    }

    private function ensureLoaded(): void
    {
        $id = app(PluginOfferBridgeRegistry::class)->identifier();
        if ($id === null) {
            return;
        }
        app(PluginService::class)->registerAutoloadPublic($id);
        $loader = app(PluginOfferBridgeRegistry::class)->autoloadClass();
        if ($loader === null) {
            return;
        }
        $class = 'weapp\\' . $id . '\\service\\' . $loader;
        if (class_exists($class) && method_exists($class, 'ensure')) {
            $class::ensure();
        }
    }
}
