<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;

use app\common\service\plugin\extension\PluginOfficialProduct;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginManifestService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\commerce\PluginDomainPurchaseGateService;
use app\common\service\plugin\commerce\PluginMeteringService;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\service\plugin\commerce\PluginWalletService;
use app\common\service\license\LicensePortalService;
use app\common\service\plugin\extension\HostRuntimeProbe;
use app\common\service\plugin\manifest\PluginTaxonomyService;

/**
 * 市场卡片展示（从 PluginMarketCatalogService 抽出）
 * — format/icon/CTA/价签；列表编排/筛选仍在 CatalogService
 */
final class PluginMarketCardPresenter
{
    public function formatMarketItem(array $manifest, ?array $adminRow, ?array $remoteRow = null): array
    {
        $id           = (string) ($manifest['identifier'] ?? '');
        $commercial   = app(PluginSkuCatalogService::class)->resolveCommercial($id, $manifest);
        // 已有 browse/Feed 行则禁止再 indexByIdentifier（R7）
        $catalogRow   = is_array($remoteRow)
            ? $remoteRow
            : (app(PluginMarketShelfDirectory::class)->indexByIdentifier()[$id] ?? null);
        $installed    = (int) ($adminRow['installed'] ?? 0);
        $enabled      = (int) ($adminRow['enabled'] ?? 0);
        $entitlement  = is_array($adminRow['entitlement'] ?? null)
            ? $adminRow['entitlement']
            : app(EntitlementService::class)->summary($id);
        $entitled     = !empty($adminRow['entitled'])
            || !empty($entitlement['entitled'])
            || app(EntitlementService::class)->can($id);
        $grantedBy    = strtolower(trim((string) ($entitlement['granted_by'] ?? '')));
        $needsUpgrade = !empty($adminRow['needs_upgrade']);
        $installedVer = $installed > 0
            ? trim((string) ($adminRow['installed_version'] ?? ''))
            : '';
        $isPaid       = app(PluginMarketCatalogService::class)->commercialIsPaid($commercial);
        $model        = $commercial['model'];
        $price        = $commercial['price'];
        $billingType  = strtolower(trim($commercial['billing_type']));
        $priceLabel   = $commercial['price_label'] !== '' ? $commercial['price_label'] : $this->priceLabel($commercial);
        // 卡片价 SSOT：有 Feed/browse 行则认行（禁本地 commercial / License 盖价）
        if (is_array($catalogRow)) {
            $feedLabel = trim((string) ($catalogRow['price_label'] ?? ''));
            if ($feedLabel !== '') {
                $priceLabel = $feedLabel;
            }
            if (array_key_exists('price', $catalogRow) && $catalogRow['price'] !== '' && $catalogRow['price'] !== null) {
                $price = round((float) $catalogRow['price'], 2);
            }
            if (array_key_exists('is_paid', $catalogRow)) {
                $isPaid = (bool) $catalogRow['is_paid'];
            } elseif ($feedLabel !== '') {
                $isPaid = $price > 0 && !preg_match('/免费|试用/u', $feedLabel);
            }
        }
        $walletMeter  = app(PluginMeteringService::class)->summary($id);
        $purchasable  = app(PluginSkuCatalogService::class)->purchasableSkusFor(
            $id,
            $manifest,
            is_array($catalogRow) ? $catalogRow : null
        );
        $purchaseTier = app(PluginDomainPurchaseGateService::class)->resolvePurchaseTier($manifest, $commercial);
        $domainTier   = app(PluginDomainPurchaseGateService::class)->siteDomainTier();
        $tierBlocked  = !$entitled
            && app(PluginMarketCatalogService::class)->commercialIsPaid($commercial)
            && !app(PluginDomainPurchaseGateService::class)->tierMeets($purchaseTier, $domainTier);
        $review       = app(PluginMarketReviewService::class)->summary($id);

        // 展示/筛选 kind：发布目录优先（与品项分类同词），否则落本地 manifest
        $resolvedKind = strtolower(trim((string) ($manifest['kind'] ?? 'document-addon')));
        if (is_array($catalogRow) && trim((string) ($catalogRow['kind'] ?? '')) !== '') {
            $resolvedKind = strtolower(trim((string) $catalogRow['kind']));
        }
        if ($resolvedKind === '') {
            $resolvedKind = 'document-addon';
        }
        $kindLabel = '';
        if (is_array($catalogRow)) {
            $kindLabel = trim((string) ($catalogRow['kind_label'] ?? ''));
        }
        if ($kindLabel === '') {
            $kindLabel = app(PluginManifestService::class)->labelForKind($resolvedKind);
        }

        // ITEM-SSOT-002：展示名只认发布目录/品项；无目录名时用 identifier，禁止 plugin.json 盖名
        $name = is_array($catalogRow) ? trim((string) ($catalogRow['name'] ?? '')) : '';
        if ($name === '') {
            $name = $id;
        }
        $description = is_array($catalogRow) ? trim((string) ($catalogRow['description'] ?? '')) : '';
        if ($description === '') {
            $description = (string) ($manifest['description'] ?? '');
        }
        $version = is_array($catalogRow) ? trim((string) ($catalogRow['version'] ?? '')) : '';
        if ($version === '') {
            $version = (string) ($manifest['version'] ?? '1.0.0');
        }
        $author = is_array($catalogRow) ? trim((string) ($catalogRow['author'] ?? '')) : '';
        if ($author === '') {
            $author = (string) ($manifest['author'] ?? '');
        }
        $authorContact = is_array($catalogRow) ? trim((string) ($catalogRow['author_contact'] ?? '')) : '';
        if ($authorContact === '') {
            $authorContact = (string) ($manifest['author_contact'] ?? $manifest['contact'] ?? '');
        }

        $item = [
            'identifier'       => $id,
            'name'             => $name,
            'description'      => $description,
            'version'          => $version,
            'author'           => $author,
            'author_contact'   => $authorContact,
            'kind'             => $resolvedKind,
            'kind_label'       => $kindLabel,
            'tags'             => is_array($manifest['tags'] ?? null) ? array_values($manifest['tags']) : [],
            'publisher_type'   => $this->resolvePublisherType($manifest, is_array($catalogRow) ? $catalogRow : null),
            'publisher_label'  => $this->resolvePublisherLabel($manifest, is_array($catalogRow) ? $catalogRow : null),
            'package'          => (string) ($manifest['package'] ?? ''),
            'icon_color'       => $this->marketIconColor($id, $manifest, $adminRow, $remoteRow),
            'icon_image'       => $this->marketIconImage($id, $manifest, $adminRow, $remoteRow),
            'commercial_model' => $model,
            'billing_type'     => $billingType,
            'sku_id'           => $commercial['sku_id'],
            'sku_name'         => $commercial['sku_name'],
            'commercial_profile' => $commercial['commercial_profile'],
            'price'            => $price,
            'period_days'      => $commercial['period_days'],
            'quota_total'      => $commercial['quota_total'] ?? null,
            'price_label'      => $priceLabel,
            'wallet_meter'     => $walletMeter,
            'purchasable_skus' => array_map(static function (array $sku): array {
                return [
                    'sku_id'       => (string) ($sku['sku_id'] ?? ''),
                    'name'         => (string) ($sku['name'] ?? ''),
                    'billing_type' => (string) ($sku['billing_type'] ?? ''),
                    'price'        => round((float) ($sku['price'] ?? 0), 2),
                    'quota_total'  => isset($sku['quota_total']) ? (int) $sku['quota_total'] : null,
                    'price_label'  => (string) ($sku['price_label'] ?? ''),
                    'tier'         => (string) ($sku['tier'] ?? ''),
                ];
            }, $purchasable),
            'installed'        => $installed,
            'installed_version'=> $installedVer,
            'enabled'          => $enabled,
            'entitled'         => $entitled,
            'entitlement'      => $entitlement,
            'needs_upgrade'    => $needsUpgrade,
            'is_paid'          => $isPaid,
            'admin_spa_path'   => (string) ($adminRow['admin_spa_path'] ?? ''),
            'package_url'      => trim((string) ($remoteRow['package_url'] ?? '')),
            'remote_version'   => trim((string) ($remoteRow['version'] ?? ($adminRow['remote_version'] ?? ''))),
            'primary_action'   => $this->primaryAction(
                $installed,
                $enabled,
                $entitled,
                $needsUpgrade,
                $walletMeter,
                $purchasable,
                $grantedBy
            ),
            'trial_reclaimable' => $this->isTrialReclaimable($id, $commercial, $manifest),
            'purchase_hint'     => $this->purchaseHint($id, $commercial, $manifest, $entitled, $purchasable),
            'portal_purchase_url' => app(LicensePortalService::class)->activatePluginUrl($id),
            'portal_purchase_path' => app(LicensePortalService::class)->activatePluginPath($id),
            'marketplace_blocked' => app(PluginMarketBlocklistService::class)->isBlocked($id),
            'block_reason'        => app(PluginMarketBlocklistService::class)->blockReason($id),
            'purchase_tier'       => $purchaseTier,
            'purchase_tier_label' => app(PluginDomainPurchaseGateService::class)->tierLabel($purchaseTier),
            'domain_tier_blocked' => $tierBlocked,
            'domain_upgrade_hint' => $tierBlocked
                ? app(PluginDomainPurchaseGateService::class)->upgradeHint($purchaseTier)
                : '',
            'avg_rating'          => $review['avg_rating'],
            'review_count'        => $review['review_count'],
        ];
        if ($item['marketplace_blocked']) {
            $item['primary_action'] = 'blocked';
        } elseif ($tierBlocked && in_array($item['primary_action'], ['purchase', 'topup'], true)) {
            $item['primary_action'] = 'need_domain';
        }
        return app(PluginTaxonomyService::class)->enrichRow(
            $this->applyPlatformLicensePriceOverlay($id, $item),
            $manifest
        );
    }

    /**
     * 有更新后重算主 CTA：唯一真源 primaryAction（含 B3 预装→purchase）
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function recomputePrimaryAction(array $item, bool $needsUpgrade): array
    {
        $wallet = is_array($item['wallet_meter'] ?? null)
            ? $item['wallet_meter']
            : ['remaining' => null, 'unlimited' => false, 'metered' => false];
        $skus = is_array($item['purchasable_skus'] ?? null) ? $item['purchasable_skus'] : [];
        $grantedBy = '';
        if (is_array($item['entitlement'] ?? null)) {
            $grantedBy = strtolower(trim((string) ($item['entitlement']['granted_by'] ?? '')));
        }
        $item['primary_action'] = $this->primaryAction(
            (int) ($item['installed'] ?? 0),
            (int) ($item['enabled'] ?? 0),
            !empty($item['entitled']),
            $needsUpgrade,
            $wallet,
            $skus,
            $grantedBy
        );
        if (!empty($item['marketplace_blocked'])) {
            $item['primary_action'] = 'blocked';
        } elseif (!empty($item['domain_tier_blocked'])
            && in_array($item['primary_action'], ['purchase', 'topup'], true)) {
            $item['primary_action'] = 'need_domain';
        }

        return $item;
    }

    /**
     * @param array<string, mixed>      $manifest
     * @param array<string, mixed>|null $catalogRow
     */
    private function resolvePublisherType(array $manifest, ?array $catalogRow): string
    {
        $fromFeed = is_array($catalogRow) ? trim((string) ($catalogRow['publisher_type'] ?? '')) : '';
        if ($fromFeed !== '') {
            return $fromFeed;
        }

        return (string) ($manifest['publisher_type'] ?? '');
    }

    /**
     * @param array<string, mixed>      $manifest
     * @param array<string, mixed>|null $catalogRow
     */
    private function resolvePublisherLabel(array $manifest, ?array $catalogRow): string
    {
        $fromFeed = is_array($catalogRow) ? trim((string) ($catalogRow['publisher_label'] ?? '')) : '';
        if ($fromFeed !== '') {
            return $fromFeed;
        }
        $type = $this->resolvePublisherType($manifest, $catalogRow);
        $fromManifest = trim((string) ($manifest['publisher_label'] ?? ''));
        if ($fromManifest !== '') {
            return $fromManifest;
        }

        return app(PluginManifestService::class)->labelForType($type);
    }

    /**
     * 市场卡片价：有 Feed/browse 行价则认行；宿主 License overlay 不再盖卡（R4，batch2 继续收 Sku）
     * 仅附着 official_item_* 元数据（不改价）
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function applyPlatformLicensePriceOverlay(string $identifier, array $item): array
    {
        if (!PluginOfficialProduct::productCenterAdminEnabled()) {
            return $item;
        }

        foreach (HostRuntimeProbe::activeHostRuntimeHandlers() as $handler) {
            $overlay = $handler->pluginMarketPriceOverlay($identifier);
            if (!is_array($overlay)) {
                continue;
            }
            // 禁盖价：卡片价 SSOT = 品项/Feed 行（已在 format 写入）
            if (!empty($overlay['item_id'])) {
                $item['official_item_id'] = (int) $overlay['item_id'];
            }
            if (trim((string) ($overlay['item_code'] ?? '')) !== '') {
                $item['official_item_code'] = (string) $overlay['item_code'];
            }
            break;
        }

        return $item;
    }

    /**
     * @param array<string, mixed>      $remoteRow
     * @param array<string, mixed>|null $adminRow
     * @return array<string, mixed>
     */
    public function formatRemoteOnlyItem(array $remoteRow, ?array $adminRow): array
    {
        $id = (string) ($remoteRow['identifier'] ?? '');
        $commercial = is_array($remoteRow['commercial'] ?? null) ? $remoteRow['commercial'] : [];
        if (is_array($remoteRow['skus'] ?? null) && $remoteRow['skus'] !== []) {
            $commercial['skus'] = $remoteRow['skus'];
        }
        if (trim((string) ($remoteRow['commercial_profile'] ?? '')) !== '') {
            $commercial['commercial_profile'] = (string) $remoteRow['commercial_profile'];
        }
        if (is_array($remoteRow['policy'] ?? null)) {
            $commercial['policy'] = $remoteRow['policy'];
        }
        if (is_array($remoteRow['meter'] ?? null)) {
            $commercial['meter'] = $remoteRow['meter'];
        }
        $pseudoManifest = [
            'identifier'  => $id,
            'name'        => (string) ($remoteRow['name'] ?? $id),
            'description' => (string) ($remoteRow['description'] ?? ''),
            'version'     => (string) ($remoteRow['version'] ?? '1.0.0'),
            'author'      => (string) ($remoteRow['author'] ?? ''),
            'kind'        => (string) ($remoteRow['kind'] ?? 'document-addon'),
            'tags'        => is_array($remoteRow['tags'] ?? null) ? $remoteRow['tags'] : [],
            'commercial'  => $commercial,
            'publisher_type' => (string) ($remoteRow['publisher_type'] ?? 'official'),
            'icon'        => (string) ($remoteRow['icon'] ?? ''),
            'color'       => (string) ($remoteRow['icon_color'] ?? ($remoteRow['color'] ?? '#5fb878')),
        ];
        $item = $this->formatMarketItem($pseudoManifest, $adminRow, $remoteRow);
        $item['catalog_source'] = 'remote_only';
        $item['on_disk']          = false;
        if (!$item['installed'] && empty($item['entitled'])) {
            $item['primary_action'] = 'purchase';
        }
        if (!empty($item['marketplace_blocked'])) {
            $item['primary_action'] = 'blocked';
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $commercial
     */
    private function priceLabel(array $commercial): string
    {
        $label = trim((string) ($commercial['price_label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        return app(PluginSkuCatalogService::class)->priceLabel([
            'billing_type' => (string) ($commercial['billing_type'] ?? $commercial['model'] ?? 'free'),
            'price'        => (float) ($commercial['price'] ?? 0),
            'duration_days'=> (int) ($commercial['period_days'] ?? 0),
            'quota_total'  => $commercial['quota_total'] ?? null,
        ]);
    }

    /**
     * 到期后是否仍可市场自助领取限免/试用（互斥试用已领过则 false）
     *
     * @param array<string, mixed>      $commercial
     * @param array<string, mixed>|null $manifest
     */
    private function isTrialReclaimable(string $identifier, array $commercial, ?array $manifest): bool
    {
        $billingType = strtolower(trim((string) ($commercial['billing_type'] ?? '')));
        $model       = strtolower(trim((string) ($commercial['model'] ?? '')));
        $isTimedOffer = in_array($billingType, [
            PluginSkuCatalogService::BILLING_LIMITED_FREE,
            PluginSkuCatalogService::BILLING_TRIAL_TIME,
            PluginSkuCatalogService::BILLING_TRIAL_QUOTA,
        ], true) || ($billingType === '' && $model === 'subscription');

        if (!$isTimedOffer) {
            return false;
        }

        if ($billingType === PluginSkuCatalogService::BILLING_LIMITED_FREE
            || ($billingType === '' && $model === 'subscription')) {
            return true;
        }

        $activeSku = app(PluginSkuCatalogService::class)->resolveActiveSku($identifier, $manifest);
        if (is_array($activeSku) && app(PluginSkuCatalogService::class)->isExclusiveTrialSku($activeSku)) {
            return !app(PluginWalletService::class)->hasExclusiveTrialGranted($identifier);
        }

        return true;
    }

    /**
     * 互斥试用已用尽时展示首选付费 SKU 文案
     *
     * @param array<string, mixed>      $commercial
     * @param array<string, mixed>|null $manifest
     * @param list<array<string, mixed>> $purchasableSkus
     */
    private function purchaseHint(
        string $identifier,
        array $commercial,
        ?array $manifest,
        bool $entitled,
        array $purchasableSkus
    ): string {
        if ($entitled || $purchasableSkus === []) {
            return '';
        }
        if ($this->isTrialReclaimable($identifier, $commercial, $manifest)) {
            return '';
        }
        $first = $purchasableSkus[0];

        return app(PluginSkuCatalogService::class)->priceLabel($first);
    }

    /**
     * @param array{remaining:?int,unlimited:bool,metered:bool} $walletMeter
     * @param list<array<string, mixed>>                       $purchasableSkus
     */
    private function primaryAction(
        int $installed,
        int $enabled,
        bool $entitled,
        bool $needsUpgrade,
        array $walletMeter = ['remaining' => null, 'unlimited' => false, 'metered' => false],
        array $purchasableSkus = [],
        string $grantedBy = ''
    ): string {
        // B3：本机预装授权可先用；有更新时须先在授权平台绑定（purchase），禁止直接 upgrade
        $installOnly = $grantedBy === 'install';
        if ($needsUpgrade && $installed && $entitled && !$installOnly) {
            return 'upgrade';
        }
        if ($needsUpgrade && $installed && $installOnly) {
            return 'purchase';
        }
        if ($entitled && $purchasableSkus !== [] && !empty($walletMeter['metered'])
            && empty($walletMeter['unlimited']) && ($walletMeter['remaining'] ?? 0) <= 0) {
            return 'topup';
        }
        if (!$entitled) {
            return 'purchase';
        }
        if (!$installed) {
            return 'install';
        }
        if (!$enabled) {
            return 'enable';
        }

        return 'manage';
    }

    private function marketIconImage(string $identifier, array $manifest, ?array $adminRow, ?array $remoteRow = null): string
    {
        $image = trim((string) ($adminRow['icon_image'] ?? ''));
        if ($image === '') {
            $image = trim((string) ($remoteRow['icon_image'] ?? ''));
        }
        if ($image !== '') {
            return $this->normalizeMarketIconUrl($image, $identifier);
        }

        return app(PluginService::class)->marketVisual($identifier, $manifest)['image'];
    }

    /**
     * @param array<string, mixed>      $manifest
     * @param array<string, mixed>|null $adminRow
     * @param array<string, mixed>|null $remoteRow
     */
    private function marketIconColor(string $identifier, array $manifest, ?array $adminRow, ?array $remoteRow = null): string
    {
        $color = trim((string) ($adminRow['icon_color'] ?? ''));
        if ($color === '') {
            $color = trim((string) ($remoteRow['icon_color'] ?? ($remoteRow['color'] ?? '')));
        }
        if ($color === '') {
            $color = app(PluginService::class)->marketVisual($identifier, $manifest)['color'];
        }

        return $color !== '' ? $color : '#5fb878';
    }

    private function normalizeMarketIconUrl(string $image, string $identifier = ''): string
    {
        $image = trim($image);
        if ($image === '') {
            return $this->localMarketIconUrl($identifier);
        }
        if (str_starts_with($image, '/weapp/')) {
            if (preg_match('#^/weapp/([a-z][a-z0-9_-]{0,49})/(.+)$#', $image, $m)) {
                return \app\common\support\WeappPublicAsset::url($m[1], $m[2]);
            }
        }

        $slug = $this->extractMarketIconSlug($image, $identifier);
        $local = $this->localMarketIconUrl($slug);
        if ($local !== '') {
            return $local;
        }

        if (str_starts_with($image, '/static/market/icons/')) {
            return \app\common\support\WeappPublicAsset::absoluteAssetUrl($image);
        }

        return $image;
    }

    private function extractMarketIconSlug(string $image, string $identifier): string
    {
        if (preg_match('#/static/market/icons/([a-z][a-z0-9_-]{0,49})\\.svg#i', $image, $m)) {
            return strtolower($m[1]);
        }

        $identifier = strtolower(trim($identifier));

        return preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $identifier) ? $identifier : '';
    }

    private function localMarketIconUrl(string $identifier): string
    {
        $identifier = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
        if ($identifier === '') {
            return '';
        }

        $root = \app\common\support\ProjectPaths::root();
        $file = $root . 'public/static/market/icons/' . $identifier . '.svg';
        if (!is_file($file)) {
            return '';
        }

        return \app\common\support\WeappPublicAsset::absoluteAssetUrl(
            '/static/market/icons/' . $identifier . '.svg',
        );
    }
}
