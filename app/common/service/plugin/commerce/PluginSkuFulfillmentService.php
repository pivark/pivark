<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\market\PluginMarketShelfDirectory;

final class PluginSkuFulfillmentService
{
    public function __construct(
        private readonly PluginMarketShelfDirectory $pluginMarketRemoteCatalog,
        private readonly PluginSkuCatalogService $pluginSkuCatalogService,
        private readonly PluginWalletService $pluginWalletService,
    ) {
    }

    /**
     * 授权生效后，按 SKU 或当前市场 active_sku 写入插件侧配置（如 shop_license_tier）
     */
    public function applyForIdentifier(string $identifier, string $skuId = ''): void
    {
        $identifier = strtolower(trim($identifier));
        $skuId      = trim($skuId);
        if ($identifier === '') {
            return;
        }

        $sku = $skuId !== ''
            ? $this->findSku($identifier, $skuId)
            : $this->pluginSkuCatalogService->resolveActiveSku(
                $identifier,
                app(PluginService::class)->readManifest($identifier)
            );

        if (!is_array($sku)) {
            return;
        }

        $this->applySkuRow($identifier, $sku);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSku(string $identifier, string $skuId): ?array
    {
        $identifier = strtolower(trim($identifier));
        $skuId      = trim($skuId);
        if ($identifier === '' || $skuId === '') {
            return null;
        }

        $manifest   = app(PluginService::class)->readManifest($identifier);
        $catalogRow = $this->pluginMarketRemoteCatalog->indexByIdentifier()[$identifier] ?? null;
        foreach ($this->pluginSkuCatalogService->skusFor($identifier, $manifest, is_array($catalogRow) ? $catalogRow : null) as $row) {
            if (($row['sku_id'] ?? '') === $skuId) {
                return $this->normalizeSkuRow($row);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPurchasableSku(string $identifier, string $skuId): ?array
    {
        $sku = $this->findSku($identifier, $skuId);
        if ($sku === null) {
            return null;
        }

        return $this->pluginSkuCatalogService->isPurchasableSku($this->pluginSkuCatalogService->widenSkuRow($sku)) ? $sku : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeSkuRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        try {
            $decoded = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $sku
     */
    public function applySkuRow(string $identifier, array $sku): void
    {
        $identifier = strtolower(trim($identifier));
        $tier       = strtolower(trim((string) ($sku['tier'] ?? '')));
        if ($tier !== '') {
            app(PluginSkuFulfillmentRegistry::class)->applyTier($identifier, $tier);
        }

        if ($this->pluginWalletService->isMeteredPlugin($identifier)) {
            if ($this->pluginSkuCatalogService->isPurchasableSku($sku)) {
                // 付费档位由 PluginCommerceService::fulfillEntitlement → applySkuPurchase 充值
                return;
            }
            $this->pluginWalletService->applySkuGrantFromRow($identifier, $sku);
        }
    }

}
