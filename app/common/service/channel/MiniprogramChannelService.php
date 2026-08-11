<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\channel;

use app\common\support\MoneyMath;

use app\common\support\ServiceResult;
use app\common\service\channel\MiniprogramConfigService;
use app\common\service\channel\MiniprogramChannelRegistry;

use app\common\service\config\ConfigService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\service\plugin\PluginService;
use app\common\support\SiteUrl;

/** 小程序渠道：授权（多 SKU → 单 hub）、开关、后台元数据 */
final class MiniprogramChannelService
{

    public function __construct(
        private readonly MiniprogramChannelRegistry $miniprogramChannelRegistry,
        private readonly EntitlementService $entitlementService,
        private readonly MiniprogramConfigService $miniprogramConfigService,
        private readonly ConfigService $configService,
        private readonly PluginSkuCatalogService $pluginSkuCatalogService,
        private readonly PluginService $pluginService,
    ) {
    }

    public function hubPlugin(): string
    {
        return $this->miniprogramChannelRegistry->hubPlugin();
    }

    public function isSkuLicensed(string $sku): bool
    {
        return $this->entitlementService->can(strtolower(trim($sku)));
    }

    public function isLicensed(): bool
    {
        if ($this->isSkuLicensed($this->hubPlugin())) {
            return true;
        }
        foreach ($this->miniprogramChannelRegistry->allSkuIdentifiers() as $sku) {
            if ($this->isSkuLicensed($sku)) {
                return true;
            }
        }

        return false;
    }

    public function isEditionLicensed(string $edition): bool
    {
        $edition = strtolower(trim($edition));
        foreach ($this->miniprogramChannelRegistry->marketSkus() as $sku => $row) {
            if (!is_array($row)) {
                continue;
            }
            $skuEdition = strtolower(trim((string) ($row['edition'] ?? '')));
            if ($skuEdition === $edition && $this->isSkuLicensed((string) $sku)) {
                return true;
            }
        }

        return $edition === MiniprogramConfigService::EDITION_CONTENT && $this->isSkuLicensed($this->hubPlugin());
    }

    public function assertLicensed(?string $edition = null): ?array
    {
        if ($edition !== null && $edition !== '' && !$this->isEditionLicensed($edition)) {
            return ServiceResult::fail('当前版本未授权，请在应用市场购买对应小程序版本');
        }
        if ($this->isLicensed()) {
            return null;
        }

        return ServiceResult::fail('请先在「插件云 → 应用市场」购买「微信小程序」');
    }

    public function isChannelActive(): bool
    {
        return $this->isLicensed() && $this->miniprogramConfigService->isWechatChannelOpen();
    }

    public function openChannel(): void
    {
        $this->configService->set('mp_wechat_channel_open', '1');
        $this->configService->forgetRequestCache();
    }

    public function closeChannel(): void
    {
        $this->configService->set('mp_wechat_channel_open', '0');
        $this->configService->forgetRequestCache();
    }

    /** 市场购买 SKU 后写入站点版本 */
    public function applySkuEdition(string $sku): void
    {
        $resolved = $this->miniprogramChannelRegistry->resolveMarketSku($sku);
        if ($resolved === null) {
            return;
        }
        $edition = $resolved['edition'];
        if (!in_array($edition, [MiniprogramConfigService::EDITION_CONTENT, MiniprogramConfigService::EDITION_SHOP], true)) {
            return;
        }
        $this->configService->set('mp_wechat_edition', $edition);
        $this->configService->forgetRequestCache();
    }

    /** @return array<string, mixed> */
    public function adminMeta(): array
    {
        $hub = $this->hubPlugin();
        // hub 未注册（如未装 mp-wechat）时仍须返回可渲染 meta，禁止 resolveCommercial('') 500
        $commercial = $hub === ''
            ? [
                'model'              => 'free',
                'price'              => 0.0,
                'period_days'        => null,
                'sku_id'             => '',
                'sku_name'           => '',
                'billing_type'       => PluginSkuCatalogService::BILLING_FREE,
                'quota_total'        => null,
                'price_label'        => '免费',
                'commercial_profile' => 'hub_missing',
                'auto_renew'         => false,
            ]
            : $this->pluginSkuCatalogService->resolveCommercial($hub);

        return [
            'identifier'        => $hub,
            'licensed'          => $hub !== '' && $this->isLicensed() ? 1 : 0,
            'channel_active'    => $hub !== '' && $this->isChannelActive() ? 1 : 0,
            'entitlement'       => $this->entitlementService->summary($hub),
            'commercial'        => $commercial,
            'price_label'       => $this->priceLabel($commercial),
            'market_vue_path'   => '/plugin/cloud?kind=miniprogram',
            'guide_vue_path'    => $this->miniprogramChannelRegistry->guideRoute($hub),
            'wechat_vue_path'   => '/system/miniprogram/wechat',
            'decor_vue_path'    => '/system/miniprogram/decor',
            'sdk_download_path' => '/spa/mp-wechat-sdk',
            'current_edition'   => $this->miniprogramConfigService->edition(),
            'editions'          => $this->miniprogramChannelRegistry->editionsForAdmin(),
        ];
    }

    public function hubInstalledAndEnabled(): bool
    {
        return $this->pluginService->isEnabled($this->hubPlugin());
    }

    /**
     * @param array{model:string,price:float,period_days:?int} $commercial
     */
    private function priceLabel(array $commercial): string
    {
        $model = strtolower(trim((string) ($commercial['model'] ?? 'free')));
        $price = (float) ($commercial['price'] ?? 0);
        $days  = (int) ($commercial['period_days'] ?? 0);

        if (in_array($model, ['free', 'bundled'], true)) {
            return '免费';
        }
        if ($price <= 0 && $days > 0) {
            return '限时免费 · ' . $days . ' 天';
        }
        if ($price <= 0) {
            return '免费';
        }
        if ($days > 0) {
            return MoneyMath::formatYuan($price, true, 0) . ' / ' . $days . ' 天';
        }

        return MoneyMath::formatYuan($price, true);
    }
}
