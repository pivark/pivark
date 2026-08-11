<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\license;

use app\common\enum\ApiErrorCode;
use app\common\service\plugin\extension\HostRuntimeProbe;
use app\common\support\ServiceResult;

final class LicenseActivateService
{

    public function __construct(
        private readonly LicenseActivateRemoteDeps $remote,
        private readonly LicenseActivateCoreDeps $core,
    ) {
    }

    /**
     * @return ServiceResult
     */
    public function activate(string $siteKey, string $licenseCode): ServiceResult
    {
        $siteKey = trim($siteKey);
        $code    = strtoupper(trim($licenseCode));

        if (!$this->remote->siteKeyService->isValid($siteKey)) {
            return ServiceResult::ok(null, '站点 ID 无效');
        }
        if ($code === '') {
            return ServiceResult::ok(null, '请输入授权码');
        }

        $localKey = $this->remote->siteKeyService->get();
        if ($localKey !== '' && $localKey !== $siteKey) {
            return ServiceResult::ok(null, '站点 ID 与当前安装不一致');
        }

        $remote = $this->remote->licenseRemoteClientService->activate(
            $siteKey,
            $code,
            trim((string) $this->core->configService->get('site_url', '')),
            $this->core->coreUpdateRemoteService->currentVersion()
        );
        if ($remote !== null) {
            if (!$remote->isOk()) {
                return $remote;
            }

            $entry = is_array($remote->dataArray() ?? null) ? $remote->dataArray() : [];

            return $this->applyEntry($siteKey, $code, $entry, 'remote', (string) ($remote->message() ?? '授权已生效'));
        }

        if ($this->core->pivarkEditionService->requiresRemoteLicenseActivate()) {
            if (!$this->remote->licenseRemoteClientService->isConfigured()) {
                return ServiceResult::ok(null, '未连接授权平台，无法联网激活');
            }

            return ServiceResult::ok(null, '授权码无效或授权平台拒绝激活');
        }

        if (!$this->core->pivarkEditionService->allowsLocalLicenseCodes()) {
            return ServiceResult::ok(null, '当前版本不支持本地授权码');
        }

        $entry = $this->lookupCode($code);
        if ($entry === null) {
            return ServiceResult::ok(null, '授权码无效或已停用');
        }

        return $this->applyEntry($siteKey, $code, $entry, 'local', '授权已生效');
    }

    /**
     * @param array<string, mixed> $entry 授权平台 sync 合并结果
     * @return ServiceResult
     */
    public function applySyncPayload(array $entry): ServiceResult
    {
        $this->remote->siteKeyService->ensure();
        $code = strtoupper(trim((string) ($entry['license_code'] ?? 'OFFICIAL-SYNC')));
        if ($code === '') {
            $code = 'OFFICIAL-SYNC';
        }

        return $this->applyEntry($this->remote->siteKeyService->get(), $code, $entry, 'sync', '授权已从授权平台同步');
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $this->remote->siteKeyService->ensure();
        $active = [];
        foreach ($this->core->entitlementService->listEntitledAdmin() as $row) {
            $summary = is_array($row['entitlement'] ?? null) ? $row['entitlement'] : [];
            $active[] = [
                'identifier'   => (string) ($row['identifier'] ?? ''),
                'name'         => (string) ($row['name'] ?? ''),
                'status_label' => (string) ($summary['status_label'] ?? ''),
                'license_type' => (string) ($summary['license_type'] ?? ''),
                'expire_at'    => (string) ($summary['expire_at'] ?? ''),
            ];
        }

        $core = $this->core->siteCoreLicenseService->status();

        return [
            'site_key'        => $this->remote->siteKeyService->get(),
            'site_domain'     => $this->remote->licensePortalService->siteDomain(),
            'portal_url'          => $this->remote->licensePortalService->manageUrl(),
            'purchase_portal_url' => $this->remote->licensePortalService->purchaseUrl(),
            'sync_available'  => $this->remote->licenseRemoteClientService->isConfigured() && !HostRuntimeProbe::isAnyHostRuntimeActive(),
            'last_sync_at'    => app(LicenseSyncService::class)->lastSyncAt(),
            'plan_label'      => $this->planLabel($core),
            'plugins'         => $active,
            'edition'         => $this->core->pivarkEditionService->edition(),
            'local_codes'     => $this->core->pivarkEditionService->allowsLocalLicenseCodes(),
            'platform_remote' => $this->remote->licenseRemoteClientService->isConfigured(),
            'platform_host'   => HostRuntimeProbe::isAnyHostRuntimeActive(),
            'core_license'    => $core,
        ];
    }

    /**
     * @param array<string, mixed> $core
     */
    public function planLabel(array $core): string
    {
        $tier = strtolower(trim((string) ($core['tier'] ?? '')));
        if ($tier === 'enterprise') {
            return '旗舰版';
        }
        if (in_array($tier, ['pro', 'professional'], true)) {
            return '专业版';
        }
        if (!empty($core['remove_brand']) || !empty($core['core_update_allowed'])) {
            return '专业版';
        }
        $features = $this->core->siteCoreLicenseService->normalizeFeatureList($core['features'] ?? []);
        if ($features !== []) {
            return '专业版';
        }

        return '开源版';
    }

    /**
     * @param array<string, mixed> $entry
     * @return ServiceResult
     */
    private function applyEntry(
        string $siteKey,
        string $code,
        array $entry,
        string $source,
        string $successMsg
    ): ServiceResult {
        $entry   = app(LicenseBundledEnhancementService::class)->mergePluginsForEntry($entry);
        $plugins = $this->remote->licenseRemoteClientService->grantLocallyFromRemote($entry, $code);
        $this->core->siteCoreLicenseService->applyFromActivatePayload($entry, $code);

        if ($plugins === [] && !$this->entryHasCoreBenefits($entry)) {
            return ServiceResult::ok(null, '授权码未返回可生效的插件或站点权益');
        }

        $this->core->auditLogService->operate(
            $source === 'sync' ? '授权平台同步' : '授权码激活',
            'admin.license',
            [
            'license_code' => $code,
            'plugins'      => $plugins,
            'site_key'     => $siteKey,
            'source'       => $source,
            'core_tier'    => (string) ($entry['core_tier'] ?? ''),
            'core_features'=> $entry['core_features'] ?? [],
            ]
        );

        // 档位变化后门禁会放行「产品中心」；须作废侧栏路由缓存，否则仍显示开通前的菜单树
        try {
            app(\app\common\service\admin\AdminSpaMenuRouteCacheService::class)->bustAll();
        } catch (\Throwable) {
            // ignore
        }
        // 前台导航/品项目录缓存：授 Pro 后刷新即显
        try {
            app(\app\common\service\infra\FrontCacheInvalidator::class)->bumpGeneration();
            app(\app\common\service\site\SiteNavService::class)->bustAdminFlatCache();
            app(\app\common\service\site\SiteNavService::class)->forgetRequestCache();
            app(\app\common\service\catalog\CatalogQueryService::class)->bumpCache('items');
        } catch (\Throwable) {
            // ignore
        }

        return ServiceResult::ok([
                'license_code'  => $code,
                'plugins'       => $plugins,
                'label'         => (string) ($entry['label'] ?? ''),
                'source'        => $source,
                'core_tier'     => (string) ($entry['core_tier'] ?? ''),
                'core_features' => $this->core->siteCoreLicenseService->normalizeFeatureList($entry['core_features'] ?? []),
            ], $successMsg);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function entryHasCoreBenefits(array $entry): bool
    {
        $tier = strtolower(trim((string) ($entry['core_tier'] ?? '')));
        if ($tier !== '') {
            return true;
        }

        return $this->core->siteCoreLicenseService->normalizeFeatureList($entry['core_features'] ?? []) !== [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupCode(string $code): ?array
    {
        $map = config('release.license_codes.codes', []);
        if (!is_array($map)) {
            return null;
        }
        $entry = $map[$code] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $plugins = $entry['plugins'] ?? [];
        if (!is_array($plugins)) {
            $plugins = [];
        }
        $coreFeatures = $this->core->siteCoreLicenseService->normalizeFeatureList($entry['core_features'] ?? []);
        $coreTier     = strtolower(trim((string) ($entry['core_tier'] ?? '')));

        if ($plugins === [] && $coreTier === '' && $coreFeatures === []) {
            return null;
        }

        return [
            'label'         => (string) ($entry['label'] ?? $code),
            'plugins'       => array_values(array_map('strval', $plugins)),
            'license_type'  => (string) ($entry['license_type'] ?? 'commercial'),
            'expire_at'     => isset($entry['expire_at']) ? (string) $entry['expire_at'] : null,
            'core_tier'     => $coreTier,
            'core_features' => $coreFeatures,
        ];
    }
}
