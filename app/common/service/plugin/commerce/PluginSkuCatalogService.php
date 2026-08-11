<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);


namespace app\common\service\plugin\commerce;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\support\MoneyMath;

use app\common\support\ServiceResult;

use app\common\support\ProjectPaths;

use app\common\model\SitePluginWallet;
use app\common\service\plugin\registry\PluginCapabilityService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\market\PluginMarketShelfDirectory;

final class PluginSkuCatalogService
{
    public function __construct(
        private readonly PluginMarketShelfDirectory $remoteCatalog,
        private readonly PluginCommercialPackageService $commercialPackage,
    ) {
    }

    public static function defaultTrialDays(): int
    {
        return max(1, (int) config('plugin.commercial.default_trial_days', 90));
    }

    public const BILLING_FREE              = 'free';
    public const BILLING_LIMITED_FREE      = 'limited_free';
    public const BILLING_TRIAL_TIME        = 'trial_time';
    public const BILLING_TRIAL_QUOTA       = 'trial_quota';
    public const BILLING_PREPAID_PACK      = 'prepaid_pack';
    public const BILLING_SUBSCRIPTION_TIME = 'subscription_time';
    public const BILLING_SUBSCRIPTION_QUOTA = 'subscription_quota';
    public const BILLING_LIFETIME          = 'lifetime';

    /**
     * @return array{
     *   model:string,
     *   price:float,
     *   period_days:?int,
     *   sku_id:string,
     *   sku_name:string,
     *   billing_type:string,
     *   quota_total:?int,
     *   price_label:string,
     *   commercial_profile:string
     * }
     */
    /**
     * @param array<string, mixed>|null $manifest
     * @return array{
     *   model: string,
     *   price: float,
     *   period_days: int|null,
     *   sku_id: string,
     *   sku_name: string,
     *   billing_type: string,
     *   quota_total: int|null,
     *   price_label: string,
     *   commercial_profile: string
     * }
     */
    public function resolveCommercial(string $identifier, ?array $manifest = null): array
    {
        $identifier = strtolower(trim($identifier));
        $manifest   = $manifest ?? app(PluginService::class)->readManifest($identifier);
        if ($this->commercialPackage->isSourceOpen($identifier)) {
            return [
                'model'              => 'free',
                'price'              => 0.0,
                'period_days'        => null,
                'sku_id'             => '',
                'sku_name'           => '',
                'billing_type'       => self::BILLING_FREE,
                'quota_total'        => null,
                'price_label'        => '免费',
                'commercial_profile' => 'source_open',
                'auto_renew'         => false,
            ];
        }
        $catalogRow = $this->remoteCatalog->indexByIdentifier()[$identifier] ?? null;
        $active     = $this->resolveActiveSku($identifier, $manifest, is_array($catalogRow) ? $catalogRow : null);

        if ($active === null) {
            // Feed/browse 行已有价：直接投影（无 skus 且无 Feed 价 → 下方抛错，逼对齐 plugin.json）
            if (is_array($catalogRow)) {
                $feedLabel = trim((string) ($catalogRow['price_label'] ?? ''));
                $feedCommercial = is_array($catalogRow['commercial'] ?? null) ? $catalogRow['commercial'] : [];
                if ($feedLabel === '') {
                    $feedLabel = trim((string) ($feedCommercial['price_label'] ?? ''));
                }
                $feedPrice = array_key_exists('price', $catalogRow)
                    ? round((float) $catalogRow['price'], 2)
                    : round((float) ($feedCommercial['price'] ?? 0), 2);
                if ($feedLabel !== '' || array_key_exists('price', $catalogRow) || array_key_exists('price', $feedCommercial)) {
                    $billing = strtolower(trim((string) ($feedCommercial['billing_type'] ?? '')));
                    if ($billing === '') {
                        $billing = $feedPrice > 0 ? self::BILLING_LIFETIME : self::BILLING_FREE;
                    }

                    return [
                        'model'              => $feedPrice > 0 ? 'paid' : 'free',
                        'price'              => $feedPrice,
                        'period_days'        => null,
                        'sku_id'             => (string) ($feedCommercial['sku_id'] ?? ($catalogRow['sku_id'] ?? '')),
                        'sku_name'           => (string) ($catalogRow['sku_name'] ?? ''),
                        'billing_type'       => $billing,
                        'quota_total'        => null,
                        'price_label'        => $feedLabel !== '' ? $feedLabel : ($feedPrice > 0 ? ('¥' . number_format($feedPrice, 2)) : '免费'),
                        'commercial_profile' => $this->commercialProfile($identifier, $catalogRow),
                        'auto_renew'         => $this->manifestAutoRenew($manifest),
                    ];
                }
            }
            throw new \RuntimeException("插件 {$identifier} 缺少 commercial.skus（v1 commercial.model/price 已废弃）");
        }

        $mapped = $this->skuToCommercial($active);
        $mapped['sku_id']              = (string) ($active['sku_id'] ?? '');
        $mapped['sku_name']            = (string) ($active['name'] ?? '');
        $mapped['billing_type']        = (string) ($active['billing_type'] ?? '');
        $mapped['quota_total']         = isset($active['quota_total']) ? (int) $active['quota_total'] : null;
        $mapped['price_label']         = $this->priceLabel($active);
        $mapped['commercial_profile']  = $this->commercialProfile($identifier, is_array($catalogRow) ? $catalogRow : null);
        $mapped['auto_renew']          = $this->manifestAutoRenew($manifest);

        return $mapped;
    }

    /**
     * @param array<string, mixed>|null $manifest
     */
    private function manifestAutoRenew(?array $manifest): bool
    {
        if (!is_array($manifest)) {
            return false;
        }
        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];

        return filter_var($commercial['auto_renew'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 上架脚手架：plugin.json → commercial.skus（首次发布品项/listing 用；日常改价走品项/商家中心）
     *
     * @param array<string, mixed>|null $manifest
     * @return list<array<string, mixed>>
     */
    public function manifestSkus(?array $manifest): array
    {
        if ($manifest === null) {
            return [];
        }
        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        $skus       = $commercial['skus'] ?? null;
        if (!is_array($skus) || $skus === []) {
            return [];
        }

        return $this->normalizeSkuList($skus);
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @return array<string, mixed>
     */
    public function manifestCommercialBlock(?array $manifest): array
    {
        if ($manifest === null) {
            return [];
        }

        return is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @param array<string, mixed>|null $catalogRow
     * @return list<array<string, mixed>>
     */
    public function skusFor(string $identifier, ?array $manifest = null, ?array $catalogRow = null): array
    {
        $identifier = strtolower(trim($identifier));
        $manifest   = $manifest ?? app(PluginService::class)->readManifest($identifier);
        $catalogRow = $catalogRow ?? ($this->remoteCatalog->indexByIdentifier()[$identifier] ?? null);

        if ($this->commercialPackage->isSourceOpen($identifier)) {
            return [];
        }

        if (PluginOfficialProduct::productCenterAdminEnabled()) {
            $fromHostItem = $this->skusFromHostItemCatalog($identifier);
            if ($fromHostItem !== []) {
                return $fromHostItem;
            }
        }

        $fromCatalog = $this->skusFromCatalogRow($catalogRow);
        if ($fromCatalog !== []) {
            return $fromCatalog;
        }

        $fromHostItem = $this->skusFromHostItemCatalog($identifier);
        if ($fromHostItem !== []) {
            return $fromHostItem;
        }

        $manifestSkus = $this->manifestSkus($manifest);
        if ($manifestSkus !== []) {
            return $manifestSkus;
        }

        return [];
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @param array<string, mixed>|null $catalogRow
     * @return array<string, mixed>|null
     */
    public function resolveActiveSku(string $identifier, ?array $manifest = null, ?array $catalogRow = null): ?array
    {
        $skus = $this->skusFor($identifier, $manifest, $catalogRow);
        if ($skus === []) {
            return null;
        }

        $activeId = $this->resolveActiveSkuId($identifier, $manifest, $catalogRow);
        if ($activeId === '' && count($skus) === 1) {
            return $skus[0];
        }

        foreach ($skus as $sku) {
            if (($sku['sku_id'] ?? '') === $activeId) {
                return $sku;
            }
        }

        return $skus[0];
    }

    /**
     * 优先站点钱包 active_sku_id，否则 catalog/default active_sku
     *
     * @param array<string, mixed>|null $manifest
     * @param array<string, mixed>|null $catalogRow
     * @return array<string, mixed>|null
     */
    public function resolveEffectiveSkuRow(string $identifier, ?array $manifest = null, ?array $catalogRow = null): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }

        $walletSkuId = trim((string) (SitePluginWallet::where('plugin_identifier', $identifier)->value('active_sku_id') ?? ''));
        if ($walletSkuId !== '') {
            $fromWallet = app(PluginSkuFulfillmentService::class)->findSku($identifier, $walletSkuId);
            if (is_array($fromWallet)) {
                return $fromWallet;
            }
        }

        return $this->resolveActiveSku($identifier, $manifest, $catalogRow);
    }

    /**
     * @param array<string, mixed>|null $sku
     * @param list<string> $declaredFeatures manifest commercial.features
     * @return list<string>
     */
    public function grantedFeaturesFromSku(?array $sku, array $declaredFeatures): array
    {
        if ($declaredFeatures === []) {
            return [];
        }
        if (!is_array($sku)) {
            return $declaredFeatures;
        }
        $raw = $sku['features'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return $declaredFeatures;
        }

        $wanted = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $raw
        ))));

        return array_values(array_intersect($declaredFeatures, $wanted));
    }

    /**
     * @param array<string, mixed> $sku
     */
    public function priceLabel(array $sku): string
    {
        $type  = strtolower(trim((string) ($sku['billing_type'] ?? '')));
        $price = round((float) ($sku['price'] ?? 0), 2);
        $days  = (int) ($sku['duration_days'] ?? $sku['period_days'] ?? 0);
        $quota = isset($sku['quota_total']) ? (int) $sku['quota_total'] : null;
        $period = strtolower(trim((string) ($sku['period'] ?? '')));

        if ($type === self::BILLING_FREE) {
            return '免费';
        }
        if ($type === self::BILLING_LIMITED_FREE) {
            return $days > 0 ? ('限时免费 · ' . $days . ' 天') : '限时免费';
        }
        if ($type === self::BILLING_TRIAL_TIME) {
            return $days > 0 ? ('试用 · ' . $days . ' 天') : '试用';
        }
        if ($type === self::BILLING_TRIAL_QUOTA) {
            return $quota !== null && $quota > 0 ? ('试用 · ' . $quota . ' 次') : '试用';
        }
        if ($type === self::BILLING_LIFETIME) {
            return $price > 0 ? (MoneyMath::formatYuan($price, true, 0) . ' · 买断') : '买断';
        }
        if ($type === self::BILLING_PREPAID_PACK) {
            if ($price <= 0) {
                return '免费';
            }
            $label = MoneyMath::formatYuan($price, true, 0);
            if ($quota !== null && $quota > 1) {
                $label .= ' / ' . $quota . ' 次';
            } elseif ($quota === 1) {
                $label .= ' / 次';
            }

            return $label;
        }
        if ($type === self::BILLING_SUBSCRIPTION_QUOTA) {
            $label = MoneyMath::formatYuan($price, true, 0);
            if ($period === 'month') {
                $label .= ' / 月';
            } elseif ($period === 'year') {
                $label .= ' / 年';
            }
            if ($quota !== null && $quota > 0) {
                $label .= ' · ' . $quota . ' 次';
            }

            return $label;
        }
        if ($type === self::BILLING_SUBSCRIPTION_TIME) {
            $label = MoneyMath::formatYuan($price, true);
            if ($days > 0) {
                $label .= ' / ' . $days . ' 天';
            }

            return $label;
        }
        if ($price <= 0) {
            return '免费';
        }

        return MoneyMath::formatYuan($price, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPurchasableSkus(): array
    {
        $out = [];
        foreach (app(PluginService::class)->discover() as $manifest) {
            $identifier = strtolower(trim((string) ($manifest['identifier'] ?? '')));
            if ($identifier === '' || $this->commercialPackage->isSourceOpen($identifier)) {
                continue;
            }
            $catalogRow = $this->remoteCatalog->indexByIdentifier()[$identifier] ?? null;
            foreach ($this->purchasableSkusFor($identifier, $manifest, is_array($catalogRow) ? $catalogRow : null) as $sku) {
                $commercial = $this->skuToCommercial($sku);
                $out[]      = [
                    'identifier'          => $identifier,
                    'sku_id'              => (string) ($sku['sku_id'] ?? ''),
                    'sku_name'            => (string) ($sku['name'] ?? ''),
                    'name'                => (string) ($manifest['name'] ?? $identifier),
                    'package'             => (string) ($manifest['package'] ?? ''),
                    'billing_type'        => (string) ($sku['billing_type'] ?? ''),
                    'model'               => $commercial['model'],
                    'price'               => $commercial['price'],
                    'period_days'         => $commercial['period_days'],
                    'quota_total'         => isset($sku['quota_total']) ? (int) $sku['quota_total'] : null,
                    'price_label'         => (string) ($sku['price_label'] ?? $this->priceLabel($sku)),
                    'commercial_profile'  => $this->commercialProfile($identifier, is_array($catalogRow) ? $catalogRow : null),
                    'tier'                => (string) ($sku['tier'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $commercial
     * @return array<string, mixed>
     */
    public function buildCatalogPluginEntry(string $identifier, array $manifest, array $commercial): array
    {
        $identifier       = strtolower(trim($identifier));
        $manifestBlock    = $this->manifestCommercialBlock($manifest);
        $manifestSkus     = $this->manifestSkus($manifest);
        $defaults         = $this->defaultsFor($identifier);
        $entry            = [
            'identifier'     => $identifier,
            'name'           => (string) ($manifest['name'] ?? $identifier),
            'version'        => (string) ($manifest['version'] ?? '1.0.0'),
            'description'    => (string) ($manifest['description'] ?? ''),
            'kind'           => (string) ($manifest['kind'] ?? ''),
            'publisher_type' => (string) ($manifest['publisher_type'] ?? 'official'),
            'package_url'    => (string) ($commercial['package_url'] ?? ''),
            'icon'           => (string) ($manifest['icon'] ?? ''),
            'icon_color'     => (string) ($manifest['color'] ?? ''),
            'icon_image'     => is_file(rtrim(ProjectPaths::root(), '/\\') . '/public/static/market/icons/' . $identifier . '.svg')
                ? '/static/market/icons/' . $identifier . '.svg'
                : '',
            'distribution'   => [
                'mode'       => 'encoded_commercial',
                'encryption' => 'pivark',
            ],
        ];

        $hostBundle = PluginOfficialProduct::dispatch('plugin_catalog_bundle', [
            'identifier' => $identifier,
        ], null);
        if (is_array($hostBundle)) {
            if (trim((string) ($hostBundle['commercial_profile'] ?? '')) !== '') {
                $entry['commercial_profile'] = (string) $hostBundle['commercial_profile'];
            }
            if (is_array($hostBundle['meter'] ?? null)) {
                $entry['meter'] = $hostBundle['meter'];
            }
            if (is_array($hostBundle['skus'] ?? null) && $hostBundle['skus'] !== []) {
                $entry['skus'] = $this->normalizeSkuList($hostBundle['skus']);
            }
            if (is_array($hostBundle['policy'] ?? null)) {
                $entry['policy'] = $hostBundle['policy'];
            }
        } elseif ($manifestSkus !== []) {
            $entry['skus'] = $manifestSkus;
            if (trim((string) ($manifestBlock['commercial_profile'] ?? '')) !== '') {
                $entry['commercial_profile'] = (string) $manifestBlock['commercial_profile'];
            }
            if (is_array($manifestBlock['meter'] ?? null)) {
                $entry['meter'] = $manifestBlock['meter'];
            }
            if (is_array($manifestBlock['policy'] ?? null)) {
                $entry['policy'] = $manifestBlock['policy'];
            }
        } elseif (is_array($defaults)) {
            if (isset($defaults['commercial_profile'])) {
                $entry['commercial_profile'] = (string) $defaults['commercial_profile'];
            }
            if (is_array($defaults['meter'] ?? null)) {
                $entry['meter'] = $defaults['meter'];
            }
            if (is_array($defaults['skus'] ?? null)) {
                $entry['skus'] = $defaults['skus'];
            }
            if (is_array($defaults['policy'] ?? null)) {
                $entry['policy'] = $defaults['policy'];
            }
        }

        $resolved = $this->resolveCommercial($identifier, $manifest);
        $entry['commercial'] = [
            'model'       => $resolved['model'],
            'price'       => $resolved['price'],
            'period_days' => $resolved['period_days'],
            'sku_id'      => $resolved['sku_id'],
            'price_label' => $resolved['price_label'],
        ];

        return $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAdminCatalog(): array
    {
        $remoteIdx = $this->remoteCatalog->indexByIdentifier();
        $out       = [];
        foreach (app(PluginService::class)->discover() as $manifest) {
            $identifier = strtolower(trim((string) ($manifest['identifier'] ?? '')));
            if ($identifier === '') {
                continue;
            }
            $catalogRow = $remoteIdx[$identifier] ?? null;
            $skus       = $this->skusFor($identifier, $manifest, is_array($catalogRow) ? $catalogRow : null);
            $active     = $this->resolveActiveSku($identifier, $manifest, is_array($catalogRow) ? $catalogRow : null);
            $out[]      = [
                'identifier'          => $identifier,
                'name'                => (string) ($manifest['name'] ?? $identifier),
                'commercial_profile'  => $this->commercialProfile($identifier, is_array($catalogRow) ? $catalogRow : null, $manifest),
                'meter'               => $this->meterFor($identifier, $manifest, is_array($catalogRow) ? $catalogRow : null),
                'skus'                => array_map(function (array $sku): array {
                    $sku['price_label'] = $this->priceLabel($sku);

                    return $sku;
                }, $skus),
                'policy'              => $this->resolvePolicy($identifier, $manifest, is_array($catalogRow) ? $catalogRow : null, $active),
                'active_price_label'  => $active !== null ? $this->priceLabel($active) : '—',
                'catalog_source'      => $this->pricingSource($identifier, $manifest),
                'policy_source'       => $this->policySource($identifier, $manifest, is_array($catalogRow) ? $catalogRow : null),
                'wallet'              => app(PluginMeteringService::class)->summary($identifier),
                'exclusive_trial_granted' => app(PluginWalletService::class)->grantedExclusiveTrialSkuId($identifier),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['identifier'], (string) $b['identifier']));

        return $out;
    }

    /**
     * @param array<string, mixed> $sku
     */
    public function isExclusiveTrialSku(array $sku): bool
    {
        if (empty($sku['exclusive'])) {
            return false;
        }
        $type = strtolower(trim((string) ($sku['billing_type'] ?? '')));

        return in_array($type, [self::BILLING_TRIAL_TIME, self::BILLING_TRIAL_QUOTA], true);
    }

    /**
     * @return ServiceResult
     */
    public function setActiveSku(string $identifier, string $skuId): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        $skuId      = trim($skuId);
        if ($identifier === '' || $skuId === '') {
            return ServiceResult::fail('参数无效');
        }

        $manifest = app(PluginService::class)->readManifest($identifier);
        if ($manifest === null) {
            return ServiceResult::fail('插件不存在');
        }

        $skus = $this->skusFor($identifier, $manifest);
        $found = false;
        foreach ($skus as $sku) {
            if (($sku['sku_id'] ?? '') === $skuId) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return ServiceResult::fail('SKU 不存在：' . $skuId);
        }

        $result = app(PluginSkuCatalogStorageService::class)->updatePluginPolicy($identifier, ['active_sku' => $skuId]);
        if ($result->isOk()) {
            app(PluginCapabilityService::class)->refreshEntitlementSnapshot($identifier);
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|null $catalogRow
     * @param array<string, mixed>|null $manifest
     */
    public function commercialProfile(string $identifier, ?array $catalogRow = null, ?array $manifest = null): string
    {
        if ($this->commercialPackage->isSourceOpen($identifier)) {
            return 'source_open';
        }
        $manifest ??= app(PluginService::class)->readManifest($identifier);
        $manifestBlock = $this->manifestCommercialBlock($manifest);
        if (trim((string) ($manifestBlock['commercial_profile'] ?? '')) !== '') {
            return trim((string) $manifestBlock['commercial_profile']);
        }
        if (is_array($catalogRow) && trim((string) ($catalogRow['commercial_profile'] ?? '')) !== '') {
            return trim((string) $catalogRow['commercial_profile']);
        }
        $defaults = $this->defaultsFor($identifier);

        return is_array($defaults) ? trim((string) ($defaults['commercial_profile'] ?? '')) : '';
    }

    /**
     * @param array<string, mixed> $sku
     * @return array{model:string,price:float,period_days:?int}
     */
    public function skuToCommercial(array $sku): array
    {
        $type  = strtolower(trim((string) ($sku['billing_type'] ?? '')));
        $price = round((float) ($sku['price'] ?? 0), 2);
        $days  = (int) ($sku['duration_days'] ?? $sku['period_days'] ?? 0);

        return match ($type) {
            self::BILLING_FREE => ['model' => 'free', 'price' => 0.0, 'period_days' => null],
            self::BILLING_LIMITED_FREE => ['model' => 'subscription', 'price' => 0.0, 'period_days' => $days > 0 ? $days : self::defaultTrialDays()],
            self::BILLING_TRIAL_TIME => ['model' => 'subscription', 'price' => 0.0, 'period_days' => $days > 0 ? $days : 3],
            self::BILLING_TRIAL_QUOTA => ['model' => 'subscription', 'price' => 0.0, 'period_days' => self::defaultTrialDays()],
            self::BILLING_SUBSCRIPTION_TIME => ['model' => 'subscription', 'price' => $price, 'period_days' => $days > 0 ? $days : 365],
            self::BILLING_SUBSCRIPTION_QUOTA => ['model' => 'subscription', 'price' => $price, 'period_days' => $this->periodToDays((string) ($sku['period'] ?? 'month'))],
            self::BILLING_LIFETIME, self::BILLING_PREPAID_PACK => ['model' => 'paid', 'price' => $price, 'period_days' => null],
            default => ['model' => $price > 0 ? 'paid' : 'free', 'price' => $price, 'period_days' => $days > 0 ? $days : null],
        };
    }

    /**
     * @param array<string, mixed> $sku
     */
    public function isPurchasableSku(array $sku): bool
    {
        $sku = $this->widenSkuRow($sku);

        $type  = strtolower(trim((string) ($sku['billing_type'] ?? '')));
        $price = round((float) ($sku['price'] ?? 0), 2);
        if (in_array($type, [self::BILLING_FREE, self::BILLING_LIMITED_FREE, self::BILLING_TRIAL_TIME, self::BILLING_TRIAL_QUOTA], true)) {
            return false;
        }

        return $price > 0;
    }

    /**
     * 打断 manifest SKU 字面量推断，供购买/履约路径复用。
     *
     * @param array<string, mixed> $sku
     * @return array<string, mixed>
     */
    public function widenSkuRow(array $sku): array
    {
        try {
            $decoded = json_decode(json_encode($sku, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $sku;
        }

        return is_array($decoded) ? $decoded : $sku;
    }

    /**
     * 限免 / 试用 SKU（货架详情购买卡展示，不含永久免费）
     *
     * @param array<string, mixed>|null $manifest
     * @param array<string, mixed>|null $catalogRow
     * @return list<array<string, mixed>>
     */
    public function trialSkusFor(string $identifier, ?array $manifest = null, ?array $catalogRow = null): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }

        $out = [];
        foreach ($this->skusFor($identifier, $manifest, $catalogRow) as $sku) {
            if (!$this->isTrialSku($sku)) {
                continue;
            }
            if ($this->isExclusiveTrialSku($sku) && app(PluginWalletService::class)->hasExclusiveTrialGranted($identifier)) {
                continue;
            }
            $sku['price_label'] = $this->priceLabel($sku);
            $out[]              = $sku;
        }

        // 试用档至多一档：时长试用与次数试用互斥，禁止并排成「选套餐」造成叠加误解
        return $this->collapseTrialSkusToOne($identifier, $out, $manifest, $catalogRow);
    }

    /**
     * @param list<array<string, mixed>> $trials
     * @param array<string, mixed>|null  $manifest
     * @param array<string, mixed>|null  $catalogRow
     * @return list<array<string, mixed>>
     */
    private function collapseTrialSkusToOne(string $identifier, array $trials, ?array $manifest, ?array $catalogRow): array
    {
        if (count($trials) <= 1) {
            return $trials;
        }

        $preferred = $this->resolveActiveSkuId($identifier, $manifest, $catalogRow);
        if ($preferred !== '') {
            foreach ($trials as $sku) {
                if (trim((string) ($sku['sku_id'] ?? '')) === $preferred) {
                    return [$sku];
                }
            }
        }

        foreach ($trials as $sku) {
            if (strtolower(trim((string) ($sku['billing_type'] ?? ''))) === self::BILLING_TRIAL_QUOTA) {
                return [$sku];
            }
        }

        return [$trials[0]];
    }

    /**
     * @param array<string, mixed> $sku
     */
    public function isTrialSku(array $sku): bool
    {
        $type = strtolower(trim((string) ($sku['billing_type'] ?? '')));

        return in_array($type, [
            self::BILLING_LIMITED_FREE,
            self::BILLING_TRIAL_TIME,
            self::BILLING_TRIAL_QUOTA,
        ], true);
    }

    /**
     * 市场可购 SKU（不含试用/限免）
     *
     * @param array<string, mixed>|null $manifest
     * @param array<string, mixed>|null $catalogRow
     * @return list<array<string, mixed>>
     */
    public function purchasableSkusFor(string $identifier, ?array $manifest = null, ?array $catalogRow = null): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }

        $out = [];
        foreach ($this->skusFor($identifier, $manifest, $catalogRow) as $sku) {
            if (!$this->isPurchasableSku($sku)) {
                continue;
            }
            $sku['price_label'] = $this->priceLabel($sku);
            $out[]              = $sku;
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $catalogRow
     * @return list<array<string, mixed>>
     */
    private function skusFromCatalogRow(?array $catalogRow): array
    {
        if (!is_array($catalogRow) || !is_array($catalogRow['skus'] ?? null) || $catalogRow['skus'] === []) {
            return [];
        }

        return $this->normalizeSkuList($catalogRow['skus']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function skusFromHostItemCatalog(string $identifier): array
    {
        $bundle = PluginOfficialProduct::dispatch('plugin_catalog_bundle', [
            'identifier' => strtolower(trim($identifier)),
        ], null);
        if (!is_array($bundle) || !is_array($bundle['skus'] ?? null) || $bundle['skus'] === []) {
            return [];
        }

        return $this->normalizeSkuList($bundle['skus']);
    }

    /**
     * 上架脚手架：plugin.json commercial.skus / meter / policy
     *
     * @return array<string, mixed>|null
     */
    private function defaultsFor(string $identifier): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($this->commercialPackage->isSourceOpen($identifier)) {
            return [
                'commercial_profile' => 'source_open',
                'skus'               => [],
                'policy'             => ['active_sku' => ''],
            ];
        }

        $manifest   = app(PluginService::class)->readManifest($identifier);
        $commercial = $this->manifestCommercialBlock($manifest);
        $skus       = $this->manifestSkus($manifest);
        if ($skus === []) {
            return null;
        }

        $out = ['skus' => $skus];
        if (trim((string) ($commercial['commercial_profile'] ?? '')) !== '') {
            $out['commercial_profile'] = (string) $commercial['commercial_profile'];
        }
        if (is_array($commercial['meter'] ?? null) && $commercial['meter'] !== []) {
            $out['meter'] = $commercial['meter'];
        }
        if (is_array($commercial['policy'] ?? null) && $commercial['policy'] !== []) {
            $out['policy'] = $commercial['policy'];
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $skus
     * @return list<array<string, mixed>>
     */
    private function normalizeSkuList(array $skus): array
    {
        $out = [];
        foreach ($skus as $sku) {
            if (!is_array($sku)) {
                continue;
            }
            $id = trim((string) ($sku['sku_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $sku['sku_id'] = $id;
            $out[]         = $sku;
        }

        return $out;
    }

    private function periodToDays(string $period): int
    {
        return match (strtolower(trim($period))) {
            'year'  => 365,
            'month' => 30,
            default => 30,
        };
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @param array<string, mixed>|null $catalogRow
     */
    private function resolveActiveSkuId(string $identifier, ?array $manifest, ?array $catalogRow): string
    {
        if (is_array($catalogRow) && is_array($catalogRow['policy'] ?? null)) {
            $fromCatalog = trim((string) ($catalogRow['policy']['active_sku'] ?? ''));
            if ($fromCatalog !== '') {
                return $fromCatalog;
            }
        }

        $manifestBlock = $this->manifestCommercialBlock($manifest);
        if (is_array($manifestBlock['policy'] ?? null)) {
            $policy = $manifestBlock['policy'];
            $fromManifest = trim((string) ($policy['active_sku'] ?? $policy['display_default_sku'] ?? ''));
            if ($fromManifest !== '') {
                return $fromManifest;
            }
        }

        $defaults = $this->defaultsFor($identifier);
        if (is_array($defaults['policy'] ?? null)) {
            return trim((string) ($defaults['policy']['active_sku'] ?? ''));
        }

        return '';
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @param array<string, mixed>|null $catalogRow
     * @param array<string, mixed>|null $active
     * @return array<string, mixed>
     */
    private function resolvePolicy(string $identifier, ?array $manifest, ?array $catalogRow, ?array $active): array
    {
        if (is_array($catalogRow['policy'] ?? null)) {
            return $catalogRow['policy'];
        }
        $manifestBlock = $this->manifestCommercialBlock($manifest);
        if (is_array($manifestBlock['policy'] ?? null)) {
            return $manifestBlock['policy'];
        }
        $defaults = $this->defaultsFor($identifier);
        if (is_array($defaults['policy'] ?? null)) {
            return $defaults['policy'];
        }

        return ['active_sku' => (string) ($active['sku_id'] ?? '')];
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @param array<string, mixed>|null $catalogRow
     * @return array<string, mixed>|null
     */
    private function meterFor(string $identifier, ?array $manifest, ?array $catalogRow): ?array
    {
        $manifestBlock = $this->manifestCommercialBlock($manifest);
        if (is_array($manifestBlock['meter'] ?? null) && $manifestBlock['meter'] !== []) {
            return $manifestBlock['meter'];
        }
        if (is_array($catalogRow['meter'] ?? null) && $catalogRow['meter'] !== []) {
            return $catalogRow['meter'];
        }
        $defaults = $this->defaultsFor($identifier);
        $meter    = is_array($defaults) ? ($defaults['meter'] ?? null) : null;

        return is_array($meter) && $meter !== [] ? $meter : null;
    }

    /**
     * @param array<string, mixed>|null $manifest
     */
    private function pricingSource(string $identifier, ?array $manifest): string
    {
        if ($this->manifestSkus($manifest) !== []) {
            return 'manifest';
        }

        return 'none';
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @param array<string, mixed>|null $catalogRow
     */
    private function policySource(string $identifier, ?array $manifest, ?array $catalogRow): string
    {
        if (is_array($catalogRow['policy'] ?? null) && trim((string) ($catalogRow['policy']['active_sku'] ?? '')) !== '') {
            return 'site_catalog';
        }
        $manifestBlock = $this->manifestCommercialBlock($manifest);
        if (is_array($manifestBlock['policy'] ?? null) && trim((string) ($manifestBlock['policy']['active_sku'] ?? '')) !== '') {
            return 'manifest';
        }
        $defaults = $this->defaultsFor($identifier);
        if (is_array($defaults['policy'] ?? null)) {
            return 'defaults';
        }

        return 'auto';
    }
}
