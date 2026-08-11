<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\entitlement;

use app\common\model\Plugin;
use app\common\model\SitePluginEntitlement;
use app\common\service\audit\AuditLogService;
use app\common\service\event\EventBusService;
use app\common\service\plugin\package\PluginBundledPackageLocator;
use app\common\service\release\PivarkEditionService;
use app\common\service\plugin\registry\PluginCapabilityService;
use app\common\service\plugin\commerce\PluginGrantLedgerService;
use app\common\service\plugin\manifest\PluginManifestPolicyDiscovery;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\service\plugin\commerce\PluginSkuFulfillmentService;
use app\common\service\plugin\commerce\PluginWalletService;
use app\common\service\plugin\WeappContext;
use app\common\support\AppTime;
use app\common\support\DbAfterCommit;
use app\common\support\ServiceResult;
use think\facade\Db;

/** 插件授权写路径（grant/revoke/安装策略/过期巡检） */
final class EntitlementCommandService
{
    public function __construct(
        private readonly PluginGrantLedgerService $grantLedger,
        private readonly EntitlementQueryService $query,
    ) {
    }

    public function grant(
        string $identifier,
        ?string $expireAt = null,
        string $grantedBy = 'manual',
        string $licenseType = 'free'
    ): bool {
        $identifier = $this->query->resolveSlugIdentifier($identifier);
        if ($identifier === '') {
            return false;
        }
        $licenseType = self::normalizeLicenseType($licenseType);
        if (!app(PivarkEditionService::class)->allowsEntitlementGrant($identifier, $grantedBy, $licenseType)) {
            return false;
        }

        $now = AppTime::now();
        Db::transaction(function () use ($identifier, $expireAt, $grantedBy, $licenseType, $now): void {
            $exists = SitePluginEntitlement::where('plugin_identifier', $identifier)->find() !== null;
            $data = [
                'status'       => 'active',
                'license_type' => $licenseType,
                'expire_at'    => $expireAt,
                'granted_by'   => $grantedBy,
                'updated_at'   => $now,
            ];
            if ($exists) {
                SitePluginEntitlement::where('plugin_identifier', $identifier)->update($data);
            } else {
                $data['plugin_identifier'] = $identifier;
                $data['created_at']        = $now;
                SitePluginEntitlement::insert($data);
            }
            $pluginRow = Plugin::where('identifier', $identifier)->find();
            $pluginArr = $pluginRow instanceof Plugin ? $pluginRow->toArray() : (is_array($pluginRow) ? $pluginRow : null);
            $shouldEnable = $grantedBy === 'install'
                || $pluginArr === null
                || (int) ($pluginArr['enabled'] ?? 0) === 1;
            if ($shouldEnable) {
                Plugin::where('identifier', $identifier)->update([
                    'enabled'    => 1,
                    'updated_at' => $now,
                ]);
            }
            $this->syncPackageMirror($identifier, $data, $now);
        });

        DbAfterCommit::run(function () use ($identifier, $expireAt, $grantedBy, $licenseType): void {
            if ($grantedBy !== 'install') {
                app(AuditLogService::class)->operate('插件授权', 'admin.plugin', [
                    'identifier'   => $identifier,
                    'license_type' => $licenseType,
                    'expire_at'    => $expireAt,
                    'granted_by'   => $grantedBy,
                ]);
            }

            app(EventBusService::class)->dispatch('entitlement.granted', [
                'package'      => $identifier,
                'expire_at'    => $expireAt,
                'granted_by'   => $grantedBy,
                'license_type' => $licenseType,
            ]);

            $this->query->flushEntitlementCache();
            app(PluginCapabilityService::class)->refreshEntitlementSnapshot($identifier);
        });

        return true;
    }

    /**
     * 本机授权类型枚举（进门皮可带 commercial 等别名，落库只认这一套）
     */
    public static function normalizeLicenseType(string $licenseType): string
    {
        $licenseType = strtolower(trim($licenseType));
        return match ($licenseType) {
            '', 'commercial' => 'paid',
            'free', 'trial', 'paid', 'bundled', 'subscription' => $licenseType,
            default => 'paid',
        };
    }

    /**
     * 落权 + SKU 投影（授权码/同步/手工/订单履约共用；禁平行第二套写后逻辑）
     */
    public function grantWithSkuApply(
        string $identifier,
        ?string $expireAt,
        string $grantedBy,
        string $licenseType,
        string $skuId = ''
    ): bool {
        if (!$this->grant($identifier, $expireAt, $grantedBy, $licenseType)) {
            return false;
        }
        app(PluginSkuFulfillmentService::class)->applyForIdentifier($identifier, trim($skuId));

        return true;
    }

    public function revoke(string $identifier): void
    {
        $identifier = $this->query->resolveSlugIdentifier($identifier);
        if ($identifier === '') {
            return;
        }

        $now = AppTime::now();
        Db::transaction(function () use ($identifier, $now): void {
            SitePluginEntitlement::where('plugin_identifier', $identifier)->update([
                'status'     => 'disabled',
                'updated_at' => $now,
            ]);
            Plugin::where('identifier', $identifier)->update([
                'enabled'    => 0,
                'updated_at' => $now,
            ]);
            $this->syncPackageMirror($identifier, [
                'status'       => 'disabled',
                'license_type' => 'free',
                'expire_at'    => null,
                'granted_by'   => 'revoke',
                'updated_at'   => $now,
            ], $now);
        });

        DbAfterCommit::run(function () use ($identifier): void {
            app(AuditLogService::class)->operate('撤销插件授权', 'admin.plugin', ['identifier' => $identifier]);

            app(EventBusService::class)->dispatch('entitlement.revoked', ['package' => $identifier]);

            $this->query->flushEntitlementCache();
            app(PluginCapabilityService::class)->clearEntitlementSnapshot($identifier);
            app(PluginWalletService::class)->syncOnEntitlementLost($identifier);
        });
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function applyInstallPolicy(string $identifier, array $manifest): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }

        $commercial  = app(PluginSkuCatalogService::class)->resolveCommercial($identifier, $manifest);
        $model       = (string) $commercial['model'];
        $billingType = strtolower(trim($commercial['billing_type']));

        if (in_array($model, ['free', 'bundled'], true)) {
            $this->grant($identifier, null, 'install', $model === 'bundled' ? 'bundled' : 'free');
            app(PluginSkuFulfillmentService::class)->applyForIdentifier($identifier);

            return;
        }

        if ($model === 'subscription' || in_array($billingType, [
            PluginSkuCatalogService::BILLING_LIMITED_FREE,
            PluginSkuCatalogService::BILLING_TRIAL_TIME,
            PluginSkuCatalogService::BILLING_TRIAL_QUOTA,
        ], true)) {
            $prev    = SitePluginEntitlement::where('plugin_identifier', $identifier)->find();
            $prevRow = $this->query->entitlementRow($prev);
            $mistake = $this->isMistakenInstallPermanentFree($prevRow);
            if ($this->query->can($identifier) && !$mistake) {
                app(PluginSkuFulfillmentService::class)->applyForIdentifier($identifier);

                return;
            }
            if ($prevRow !== null && (string) ($prevRow['status'] ?? '') === 'expired') {
                return;
            }
            if (
                $prevRow !== null
                && $this->grantLedger->hasLimitedFreeGrant($identifier)
                && !$mistake
            ) {
                return;
            }
            $effectiveBilling = $billingType !== ''
                ? $billingType
                : PluginSkuCatalogService::BILLING_LIMITED_FREE;
            $days             = $this->resolveTrialPeriodDays($commercial, $effectiveBilling);
            if ($days > 0 && $this->shouldAutoGrantTrialOnInstall()) {
                $expireTs = strtotime('+' . $days . ' days');
                $expire = AppTime::format('Y-m-d H:i:s', $expireTs !== false ? $expireTs : null);
                $this->grant($identifier, $expire, 'install', 'trial');
                app(PluginSkuFulfillmentService::class)->applyForIdentifier($identifier);
                if ($effectiveBilling === PluginSkuCatalogService::BILLING_LIMITED_FREE) {
                    $this->grantLedger->recordLimitedFree($identifier, 'install', 'expire=' . $expire);
                }
            } else {
                $this->revoke($identifier);
            }

            return;
        }

        if ($model === 'paid') {
            if ($this->query->can($identifier)) {
                return;
            }
            $this->revoke($identifier);

            return;
        }

        $this->grant($identifier, null, 'install', 'free');
    }

    /**
     * @param list<string>|null $identifiers
     */
    public function reconcileEnhancementPackInstallGrants(?array $identifiers = null): ServiceResult
    {
        if ($identifiers === null) {
            $identifiers = [];
            foreach (app(PluginBundledPackageLocator::class)->listEnhancementPackIdentifiers() as $id) {
                $id = strtolower(trim((string) $id));
                if ($id !== '') {
                    $identifiers[] = $id;
                }
            }
        }

        $fixed = [];
        foreach (array_values(array_unique(array_filter(array_map(
            static fn ($id): string => strtolower(trim((string) $id)),
            $identifiers
        )))) as $identifier) {
            if (!is_dir(app(PluginService::class)->weappRoot() . $identifier)) {
                continue;
            }
            $manifest = app(PluginService::class)->readManifest($identifier);
            if ($manifest === null) {
                continue;
            }
            $before = $this->query->summary($identifier);
            $this->applyInstallPolicy($identifier, $manifest);
            $after = $this->query->summary($identifier);
            if (
                $before['license_type'] !== $after['license_type']
                || ($before['expire_at'] ?? null) !== ($after['expire_at'] ?? null)
            ) {
                $fixed[] = $identifier;
            }
        }

        return ServiceResult::ok(
            ['fixed' => $fixed],
            $fixed === [] ? '无需修正' : '已修正 ' . \count($fixed) . ' 个插件安装授权'
        );
    }

    public function grantManual(string $identifier, string $licenseType = 'paid', int $trialDays = 0): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }
        if (!is_dir(app(PluginService::class)->weappRoot() . $identifier)) {
            return ServiceResult::fail('插件目录不存在');
        }

        $licenseType = strtolower(trim($licenseType));
        if (!app(PivarkEditionService::class)->allowsManualGrant($identifier, $licenseType)) {
            return ServiceResult::fail('开源版不支持手工授予商业插件，请通过授权平台授权码激活');
        }

        if (!in_array($licenseType, ['free', 'trial', 'paid', 'bundled'], true)) {
            $licenseType = 'paid';
        }

        $expire = null;
        if ($trialDays > 0) {
            $trialTs   = strtotime('+' . $trialDays . ' days');
            $expire      = AppTime::format('Y-m-d H:i:s', $trialTs !== false ? $trialTs : null);
            $licenseType = 'trial';
        }

        if (!$this->grantWithSkuApply($identifier, $expire, 'manual', $licenseType)) {
            return ServiceResult::fail('授权被拒绝，请检查站点版本或许可策略');
        }

        return ServiceResult::ok(null, '已授权' . ($expire ? '（至 ' . $expire . '）' : '（永久）'));
    }

    public function revokeManual(string $identifier): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }

        $this->revoke($identifier);

        return ServiceResult::ok(null, '已撤销授权并停用插件');
    }

    /**
     * 仅当当前授权来源匹配时撤销（市场 acquire 失败补偿；禁宽撤误伤手工/授权码 · J100）
     */
    public function revokeIfGrantedBy(string $identifier, string $expectedGrantedBy): ServiceResult
    {
        $identifier = $this->query->resolveSlugIdentifier($identifier);
        $expectedGrantedBy = strtolower(trim($expectedGrantedBy));
        if ($identifier === '' || $expectedGrantedBy === '') {
            return ServiceResult::fail('插件标识或授权来源无效');
        }

        $row = SitePluginEntitlement::where('plugin_identifier', $identifier)->find();
        if ($row === null) {
            return ServiceResult::ok(null, '无授权记录可撤销');
        }
        $arr = $row instanceof SitePluginEntitlement ? $row->toArray() : (array) $row;
        $current = strtolower(trim((string) ($arr['granted_by'] ?? '')));
        if ($current !== $expectedGrantedBy) {
            return ServiceResult::ok(
                null,
                '跳过撤销：当前授权来源为 ' . ($current !== '' ? $current : 'unknown')
                    . '，非本流程 ' . $expectedGrantedBy
            );
        }

        $this->revoke($identifier);

        return ServiceResult::ok(null, '已撤销本流程开通的授权');
    }

    /**
     * @param array<string, mixed> $commercial
     */
    public function resolveTrialPeriodDays(array $commercial, string $billingType): int
    {
        $billingType = strtolower(trim($billingType));
        $days        = (int) ($commercial['period_days'] ?? 0);
        if ($billingType === PluginSkuCatalogService::BILLING_TRIAL_QUOTA) {
            $days = max($days, 90);
        }
        if ($days <= 0) {
            $days = PluginSkuCatalogService::defaultTrialDays();
        }

        return $days;
    }

    public function refreshExpiredStatuses(): int
    {
        $now = AppTime::now();

        return (int) SitePluginEntitlement::where('status', 'active')
            ->whereNotNull('expire_at')
            ->where('expire_at', '<', $now)
            ->update(['status' => 'expired', 'updated_at' => $now]);
    }

    /**
     * @return array{expired:int,disabled:int}
     */
    public function enforceExpiredPlugins(): array
    {
        $now = AppTime::now();
        /** @var list<string> $lostIds */
        $lostIds = [];
        SitePluginEntitlement::where('status', 'active')
            ->whereNotNull('expire_at')
            ->where('expire_at', '<', $now)
            ->order('id', 'asc')
            ->chunk(200, function ($rows) use (&$lostIds): void {
                foreach ($rows as $row) {
                    $arr = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
                    $id  = strtolower(trim((string) ($arr['plugin_identifier'] ?? '')));
                    if ($id !== '') {
                        $lostIds[] = $id;
                    }
                }
            });
        $lostIds = array_values(array_unique($lostIds));

        $expired = $this->refreshExpiredStatuses();

        foreach ($lostIds as $identifier) {
            app(PluginWalletService::class)->syncOnEntitlementLost($identifier);
            $row = $this->query->entitlementRow(
                SitePluginEntitlement::where('plugin_identifier', $identifier)->find()
            );
            if ($row !== null) {
                $this->syncPackageMirror($identifier, [
                    'status'       => 'expired',
                    'license_type' => (string) ($row['license_type'] ?? 'trial'),
                    'expire_at'    => $row['expire_at'] ?? null,
                    'granted_by'   => (string) ($row['granted_by'] ?? ''),
                    'updated_at'   => $now,
                ], $now);
            }
        }

        $this->query->flushEntitlementCache();

        if ($lostIds !== []) {
            $this->queueStaticRebuildAfterEntitlementLost($lostIds);
        }

        return ['expired' => $expired, 'disabled' => 0];
    }

    /**
     * @return array{synced:int,lifted:int,slug_created:int}
     */
    public function reconcilePackageMirrors(): array
    {
        $synced = 0;
        $lifted = 0;
        $created = 0;
        $now     = AppTime::now();

        $officialSlugs = self::entitlementMirrorIdentifiers();

        foreach ($officialSlugs as $slug) {
            $package = app(WeappContext::class)->packageForIdentifier($slug);
            if ($package === '' || $package === $slug) {
                continue;
            }

            $slugRowModel = SitePluginEntitlement::where('plugin_identifier', $slug)->find();
            $pkgRowModel  = SitePluginEntitlement::where('plugin_identifier', $package)->find();

            if ($slugRowModel !== null) {
                $slugRow = $this->query->entitlementRow($slugRowModel);
                if ($slugRow === null) {
                    continue;
                }
                $data = [
                    'status'       => (string) ($slugRow['status'] ?? 'active'),
                    'license_type' => (string) ($slugRow['license_type'] ?? 'free'),
                    'expire_at'    => $slugRow['expire_at'] ?? null,
                    'granted_by'   => (string) ($slugRow['granted_by'] ?? ''),
                    'updated_at'   => $now,
                ];
                $this->syncPackageMirror($slug, $data, $now);
                $synced++;
                continue;
            }

            if ($pkgRowModel === null) {
                continue;
            }

            $pkgRow = $this->query->entitlementRow($pkgRowModel);
            if ($pkgRow === null) {
                continue;
            }

            $lift = [
                'plugin_identifier' => $slug,
                'status'            => (string) ($pkgRow['status'] ?? 'active'),
                'license_type'      => (string) ($pkgRow['license_type'] ?? 'free'),
                'expire_at'         => $pkgRow['expire_at'] ?? null,
                'granted_by'        => (string) ($pkgRow['granted_by'] ?? 'migration'),
                'created_at'        => (string) ($pkgRow['created_at'] ?? $now),
                'updated_at'        => $now,
            ];
            SitePluginEntitlement::insert($lift);
            $created++;
            $this->syncPackageMirror($slug, [
                'status'       => $lift['status'],
                'license_type' => $lift['license_type'],
                'expire_at'    => $lift['expire_at'],
                'granted_by'   => $lift['granted_by'],
                'updated_at'   => $now,
            ], $now);
            $lifted++;
        }

        $this->refreshExpiredStatuses();
        $this->query->flushEntitlementCache();

        return ['synced' => $synced, 'lifted' => $lifted, 'slug_created' => $created];
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function isMistakenInstallPermanentFree(?array $row): bool
    {
        if ($row === null) {
            return false;
        }
        if ((string) ($row['granted_by'] ?? '') !== 'install') {
            return false;
        }
        if ((string) ($row['license_type'] ?? '') !== 'free') {
            return false;
        }

        return empty($row['expire_at']);
    }

    private function shouldAutoGrantTrialOnInstall(): bool
    {
        if (filter_var(config('plugin.commercial.auto_grant_trial_on_install', true), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $env = strtolower(trim((string) env('PIVARK_ENV', 'dev')));

        return in_array($env, ['dev', 'demo-local'], true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncPackageMirror(string $slug, array $data, string $now): void
    {
        $package = app(WeappContext::class)->packageForIdentifier($slug);
        if ($package === '' || $package === $slug) {
            return;
        }

        $pkgExists = SitePluginEntitlement::where('plugin_identifier', $package)->find() !== null;
        // identifier 行的 plugin_identifier 不写入 package 镜像行（会撞 uk_spe_plugin）
        $pkgData = $data;
        unset($pkgData['plugin_identifier']);
        if ($pkgExists) {
            SitePluginEntitlement::where('plugin_identifier', $package)->update($pkgData);
        } else {
            $pkgData['plugin_identifier'] = $package;
            $pkgData['created_at']        = $now;
            SitePluginEntitlement::insert($pkgData);
        }
    }

    /**
     * @param list<string> $identifiers
     */
    private function queueStaticRebuildAfterEntitlementLost(array $identifiers): void
    {
        if (!filter_var(config('plugin.commercial.rebuild_static_on_entitlement_lost', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        $queue = app(\app\common\service\static\StaticBuildQueueService::class);
        if (!$queue->asyncBuildEnabled()) {
            return;
        }
        foreach (array_unique($identifiers) as $identifier) {
            if ($identifier === '' || !$this->pluginLossRequiresStaticRebuild($identifier)) {
                continue;
            }
            $queue->enqueueWork(['t' => 'framework'], 30);
            break;
        }
        $queue->enqueueWork(['t' => 'incremental', 'hours' => 24], 20);
    }

    private function pluginLossRequiresStaticRebuild(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }
        try {
            $manifest = app(\app\common\service\plugin\PluginService::class)->readManifest($identifier);
        } catch (\Throwable) {
            return false;
        }
        $kind = strtolower(trim((string) ($manifest['kind'] ?? '')));

        return in_array(
            $kind,
            [
                \app\common\service\plugin\PluginManifestService::KIND_DOCUMENT_ADDON,
                \app\common\service\plugin\PluginManifestService::KIND_APPLICATION,
            ],
            true,
        ) || app(\app\common\service\plugin\registry\PluginExtensionRegistry::class)->hasDocumentAddonBridge($identifier);
    }

    /** @return list<string> */
    private static function entitlementMirrorIdentifiers(): array
    {
        return PluginManifestPolicyDiscovery::mergeIdentifierLists(
            config('pivark.entitlement_package_mirror_identifiers'),
            PluginManifestPolicyDiscovery::entitlementMirrorIdentifiers(),
        );
    }

    /** 写入 capability 快照 JSON（仅 capability_snapshot_json 列；无 entitlement 行时跳过） */
    public function updateCapabilitySnapshot(string $identifier, array $payload): void
    {
        $identifier = app(EntitlementQueryService::class)->resolveSlugIdentifier($identifier);
        if ($identifier === '' || SitePluginEntitlement::where('plugin_identifier', $identifier)->find() === null) {
            return;
        }

        SitePluginEntitlement::where('plugin_identifier', $identifier)->update([
            'capability_snapshot_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at'               => AppTime::now(),
        ]);
    }

    public function clearCapabilitySnapshot(string $identifier): void
    {
        $identifier = app(EntitlementQueryService::class)->resolveSlugIdentifier($identifier);
        if ($identifier === '') {
            return;
        }

        SitePluginEntitlement::where('plugin_identifier', $identifier)->update([
            'capability_snapshot_json' => null,
            'updated_at'               => AppTime::now(),
        ]);
    }
}
