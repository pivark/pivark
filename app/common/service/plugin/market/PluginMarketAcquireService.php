<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;

use app\common\service\plugin\package\PluginPackageAuditService;
use app\common\service\plugin\commerce\PluginCommerceService;
use app\common\service\plugin\security\PluginSecurityPolicyService;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\service\plugin\commerce\PluginDomainPurchaseGateService;
use app\common\service\plugin\commerce\PluginSkuFulfillmentService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginManifestService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\market\PluginMarketBlocklistService;
use app\common\service\plugin\market\PluginMarketCatalogService;
use app\common\service\plugin\market\PluginMarketShelfDirectory;
use app\common\service\license\LicensePortalService;
use app\common\support\ServiceResult;

use app\common\model\Plugin;
use app\common\service\audit\AuditLogService;
use app\common\service\payment\PaymentConfigService;
use app\common\support\LocalFile;

/** 插件市场获取：购买授权、下载安装、远程审计 */
final class PluginMarketAcquireService
{
    public function __construct(
        private readonly PluginService $plugins,
        private readonly PluginSkuCatalogService $skuCatalog,
        private readonly PluginSkuFulfillmentService $fulfillment,
        private readonly PluginCommerceService $commerce,
        private readonly EntitlementService $entitlement,
        private readonly PaymentConfigService $paymentConfig,
        private readonly PluginMarketBlocklistService $blocklist,
        private readonly PluginMarketCatalogService $marketCatalog,
    ) {
    }

    /**
     * 购买/领取授权 → 安装 → 可选启用
     *
     * @return ServiceResult
     */
    public function acquire(
        string $identifier,
        int $userId,
        string $channel = 'demo',
        bool $autoEnable = true,
        bool $autoInstall = true,
        string $skuId = ''
    ): ServiceResult {
        $identifier = strtolower(trim($identifier));
        $skuId      = trim($skuId);
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }

        $blockMsg = $this->blocklist->guardInstall($identifier);
        if ($blockMsg !== null) {
            return ServiceResult::fail($blockMsg);
        }

        $steps    = [];
        $channel  = $this->paymentConfig->resolveAdminPluginAcquireChannel($channel);

        $this->entitlement->enforceExpiredPlugins();
        $hadEntitlement = $this->entitlement->can($identifier);
        $grantedBy      = strtolower(trim((string) ($this->entitlement->summary($identifier)['granted_by'] ?? '')));

        // 未授权：一律经授权平台领取/购买（禁本地 grantFromMarketOffer / 站内首购下单）
        if (!$hadEntitlement) {
            return $this->portalAcquireRequired($identifier, $skuId, false);
        }

        // B3：本机预装授权可先用；升级须先在授权平台登录绑定，拒绝则保持当前版本
        if ($grantedBy === 'install' && $skuId === '' && $this->remoteNeedsUpgrade($identifier)) {
            return $this->portalAcquireRequired($identifier, '', true);
        }

        // 已授权 + 指定 SKU：加购套餐（站内商业化）
        if ($skuId !== '') {
            $manifest = $this->plugins->readManifest($identifier);
            if ($manifest !== null) {
                $skuRow = $this->fulfillment->findPurchasableSku($identifier, $skuId);
                $commercial = is_array($skuRow)
                    ? array_merge($this->skuCatalog->skuToCommercial($skuRow), [
                        'sku_id'       => $skuId,
                        'sku_name'     => (string) ($skuRow['name'] ?? ''),
                        'billing_type' => (string) ($skuRow['billing_type'] ?? ''),
                    ])
                    : $this->skuCatalog->resolveCommercial($identifier, $manifest);
                $tierMsg    = app(PluginDomainPurchaseGateService::class)->assertForPurchase($identifier, $manifest, $commercial);
                if ($tierMsg !== null) {
                    return ServiceResult::fail($tierMsg);
                }
            }

            return $this->fulfillPaidSkuPurchase(
                $identifier,
                $skuId,
                $userId,
                $channel,
                $autoEnable,
                $autoInstall,
                true
            );
        }

        $ensure = $this->ensurePackageOnDisk($identifier);
        if (!$ensure->isOk()) {
            return $ensure;
        }
        $ensureData = $ensure->dataArray();
        if (!empty($ensureData['step'])) {
            $steps[] = (string) $ensureData['step'];
        }

        $manifest = $this->plugins->readManifest($identifier);
        if ($manifest === null || empty($manifest['_manifest_valid'])) {
            return ServiceResult::fail('插件不存在或清单无效');
        }

        $steps[] = '已有有效授权';
        /** @var array{granted_now:bool,order_no:string,had_before:bool} $compensate */
        $compensate = ['granted_now' => false, 'order_no' => '', 'had_before' => true];

        $installErr = $this->installWithSagaOrRetry(
            $identifier,
            $manifest,
            $autoInstall,
            $autoEnable,
            $steps,
            true,
            $compensate
        );
        if ($installErr !== null) {
            return ServiceResult::fail(trim($installErr->message() . '（已入队安装重试，系统将自动处理）'));
        }

        app(AuditLogService::class)->operate('市场获取插件', 'admin.plugin', [
            'identifier' => $identifier,
            'user_id'    => $userId,
            'channel'    => $channel,
            'steps'      => $steps,
        ]);

        return ServiceResult::ok(['steps' => $steps], '已完成：' . implode(' → ', $steps));
    }

    /**
     * 未授权 / 预装升级绑定时引导授权平台门户（携带 site_key）；授权落库只认 sync/activate/离线文件
     */
    private function portalAcquireRequired(string $identifier, string $skuId = '', bool $preinstallBind = false): ServiceResult
    {
        $portal = app(LicensePortalService::class);
        $url    = $portal->activatePluginUrl($identifier, $skuId);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return ServiceResult::fail(
                '尚未配置授权平台地址（PIVARK_LICENSE_PLATFORM_URL）。请先配置授权平台地址，或导入离线授权文件后再安装。'
            );
        }

        $msg = $preinstallBind
            ? '升级预装插件需先在授权平台注册/登录并绑定本机安装。完成后回本站「同步授权平台」再升级；取消则保持当前版本可用。'
            : '请先在授权平台领取或购买授权，完成后回到本站「同步授权平台」，再点安装';

        return ServiceResult::ok([
            'need_portal'     => true,
            'portal_url'      => $url,
            'preinstall_bind' => $preinstallBind,
            'steps'           => [$preinstallBind ? '升级需先在授权平台绑定预装插件' : '需先在授权平台领取或购买授权'],
        ], $msg);
    }

    private function remoteNeedsUpgrade(string $identifier): bool
    {
        $manifest = $this->plugins->readManifest($identifier);
        if ($manifest === null) {
            return false;
        }
        $row = $this->pluginRow(Plugin::where('identifier', $identifier)->find());
        if ($row === null || (int) ($row['installed'] ?? 0) !== 1) {
            return false;
        }
        foreach ($this->plugins->listAdmin() as $item) {
            if (($item['identifier'] ?? '') === $identifier && !empty($item['needs_upgrade'])) {
                return true;
            }
        }
        $remoteRow = app(PluginMarketShelfDirectory::class)->indexByIdentifier()[$identifier] ?? null;
        $remoteVer = is_array($remoteRow) ? trim((string) ($remoteRow['version'] ?? '')) : '';
        $localVer  = trim((string) ($manifest['version'] ?? ''));

        return $remoteVer !== '' && $localVer !== ''
            && app(PluginMarketShelfDirectory::class)->versionNewer($remoteVer, $localVer);
    }

    /**
     * @param list<string> $steps
     * @return ServiceResult dataArray 含 paid、pending_payment、order_no、redirect 等
     */
    private function createPaidEntitlementFlow(
        string $identifier,
        int $userId,
        string $channel,
        string $skuId,
        array &$steps
    ): ServiceResult {
        $channel = $this->paymentConfig->normalizeChannel($channel);
        if ($channel === PaymentConfigService::CHANNEL_BALANCE && $this->paymentConfig->shouldUseDemo('balance')) {
            $channel = PaymentConfigService::CHANNEL_WECHAT;
        }

        $order = $this->commerce->createEntitlementOrder(
            $identifier,
            max(1, $userId),
            $channel,
            null,
            $skuId
        );
        if (!$order->isOk()) {
            return ServiceResult::fail($order->message());
        }

        $interpreted = $this->commerce->interpretEntitlementOrderAfterCreate($order, $steps);
        $interpretedData = $interpreted->dataArray();
        if (!($interpretedData['paid'] ?? false)) {
            return $interpreted;
        }

        return $interpreted;
    }

    /**
     * 指定 SKU 下单（首购或加购套餐）
     *
     * @return ServiceResult
     */
    private function fulfillPaidSkuPurchase(
        string $identifier,
        string $skuId,
        int $userId,
        string $channel,
        bool $autoEnable,
        bool $autoInstall,
        bool $alreadyEntitled
    ): ServiceResult {
        if ($this->fulfillment->findPurchasableSku($identifier, $skuId) === null) {
            return ServiceResult::fail('SKU 无效或不可购买');
        }

        $steps = [];
        $paidFlow = $this->createPaidEntitlementFlow(
            $identifier,
            max(1, $userId),
            $this->paymentConfig->resolveAdminPluginAcquireChannel($channel),
            $skuId,
            $steps
        );
        if (!$paidFlow->isOk()) {
            return $paidFlow;
        }
        if (($paidFlow->dataArray()['pending_payment'] ?? false) === true) {
            return $paidFlow;
        }

        if ($alreadyEntitled) {
            $steps[] = '套餐已充值';
            app(AuditLogService::class)->operate('市场加购插件套餐', 'admin.plugin', [
                'identifier' => $identifier,
                'sku_id'     => $skuId,
                'user_id'    => $userId,
                'steps'      => $steps,
            ]);

            return ServiceResult::ok(['steps' => $steps], '已完成：' . implode(' → ', $steps));
        }

        $steps[] = '授权已开通';

        $paidData   = $paidFlow->dataArray();
        $compensate = [
            'granted_now' => !$alreadyEntitled,
            'order_no'    => trim((string) ($paidData['order_no'] ?? '')),
            'had_before'  => $alreadyEntitled,
        ];

        $ensure = $this->ensurePackageOnDisk($identifier);
        if (!$ensure->isOk()) {
            $note = $this->compensateAcquireFailure($identifier, $compensate);

            return ServiceResult::fail(trim($ensure->message() . $note));
        }
        $ensureData = $ensure->dataArray();
        if (!empty($ensureData['step'])) {
            $steps[] = (string) $ensureData['step'];
        }

        $manifest = $this->plugins->readManifest($identifier);
        if ($manifest === null || empty($manifest['_manifest_valid'])) {
            $note = $this->compensateAcquireFailure($identifier, $compensate);

            return ServiceResult::fail('插件不存在或清单无效' . $note);
        }

        $installErr = $this->installWithSagaOrRetry(
            $identifier,
            $manifest,
            $autoInstall,
            $autoEnable,
            $steps,
            true,
            $compensate
        );
        if ($installErr !== null) {
            return ServiceResult::fail(trim($installErr->message() . '（已入队安装重试，系统将自动处理）'));
        }

        app(AuditLogService::class)->operate('市场购买插件 SKU', 'admin.plugin', [
            'identifier' => $identifier,
            'sku_id'     => $skuId,
            'user_id'    => $userId,
            'steps'      => $steps,
        ]);

        return ServiceResult::ok(['steps' => $steps], '已完成：' . implode(' → ', $steps));
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $steps
     * @param array{granted_now:bool,order_no:string,had_before:bool} $compensate
     * @return ServiceResult|null
     */
    private function installWithSagaOrRetry(
        string $identifier,
        array $manifest,
        bool $autoInstall,
        bool $autoEnable,
        array &$steps,
        bool $withRemoteUpgrade,
        array $compensate
    ): ?ServiceResult {
        $sagaId = app(PluginMarketAcquireReliabilityService::class)->begin($identifier, $compensate, [
            'auto_install'        => $autoInstall,
            'auto_enable'         => $autoEnable,
            'with_remote_upgrade' => $withRemoteUpgrade,
        ]);
        app(PluginMarketAcquireReliabilityService::class)->markStep($sagaId, 'granted');

        $installErr = $this->appendInstallAndEnableSteps(
            $identifier,
            $manifest,
            $autoInstall,
            $autoEnable,
            $steps,
            $withRemoteUpgrade
        );
        if ($installErr !== null) {
            app(PluginMarketAcquireReliabilityService::class)->markStep($sagaId, 'install_failed');
            app(PluginMarketAcquireReliabilityService::class)->enqueue('resume_install', [
                'identifier'          => $identifier,
                'auto_install'        => $autoInstall,
                'auto_enable'         => $autoEnable,
                'with_remote_upgrade' => $withRemoteUpgrade,
                'compensate'          => $compensate,
                'saga_id'             => $sagaId,
            ], (string) $installErr->message());

            return $installErr;
        }

        app(PluginMarketAcquireReliabilityService::class)->complete($sagaId);

        return null;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $steps
     * @return ServiceResult|null
     */
    private function appendInstallAndEnableSteps(
        string $identifier,
        array $manifest,
        bool $autoInstall,
        bool $autoEnable,
        array &$steps,
        bool $withRemoteUpgrade
    ): ?ServiceResult {
        if ($autoInstall) {
            $row       = $this->pluginRow(Plugin::where('identifier', $identifier)->find());
            $installed = $row !== null && (int) ($row['installed'] ?? 0) === 1;
            if (!$installed) {
                $install = $this->plugins->installFromWeapp($identifier);
                if (!$install->isOk()) {
                    return ServiceResult::fail($install->message());
                }
                $steps[] = '已安装';
            } elseif ($withRemoteUpgrade) {
                if (!$this->entitlement->can($identifier)) {
                    return ServiceResult::fail('授权已过期或未生效，请续费后再升级');
                }
                $needsUpgrade = false;
                foreach ($this->plugins->listAdmin() as $item) {
                    if (($item['identifier'] ?? '') === $identifier) {
                        $needsUpgrade = !empty($item['needs_upgrade']);
                        break;
                    }
                }
                if (!$needsUpgrade) {
                    $remoteRow = app(PluginMarketShelfDirectory::class)->indexByIdentifier()[$identifier] ?? null;
                    $remoteVer = is_array($remoteRow) ? trim((string) ($remoteRow['version'] ?? '')) : '';
                    $localVer  = trim((string) ($manifest['version'] ?? ''));
                    if ($remoteVer !== '' && $localVer !== ''
                        && app(PluginMarketShelfDirectory::class)->versionNewer($remoteVer, $localVer)) {
                        $needsUpgrade = true;
                    }
                }
                if ($needsUpgrade) {
                    $remoteRow = app(PluginMarketShelfDirectory::class)->indexByIdentifier()[$identifier] ?? null;
                    $remoteVer = is_array($remoteRow) ? trim((string) ($remoteRow['version'] ?? '')) : '';
                    $localVer  = trim((string) ($manifest['version'] ?? ''));
                    $remoteNewer = $remoteVer !== '' && $localVer !== ''
                        && app(PluginMarketShelfDirectory::class)->versionNewer($remoteVer, $localVer);

                    if ($remoteNewer) {
                        $ladder = app(PluginUpgradeLadderService::class)->apply($identifier);
                        if (!$ladder->isOk()) {
                            return ServiceResult::fail((string) ($ladder->message() ?? '插件阶梯升级失败'));
                        }
                        $applied = is_array($ladder->dataArray()['applied'] ?? null)
                            ? $ladder->dataArray()['applied']
                            : [];
                        $steps[] = $applied === []
                            ? '已升级'
                            : ('已按阶梯升级：V' . implode(' → V', $applied));
                    } else {
                        $upgrade = $this->plugins->upgrade($identifier);
                        if (!$upgrade->isOk()) {
                            return ServiceResult::fail($upgrade->message());
                        }
                        $steps[] = '已升级';
                    }
                } else {
                    $steps[] = '已安装';
                }
            } else {
                $steps[] = '已安装';
            }
        }

        if ($autoEnable && $this->resolveAutoEnable($manifest)) {
            $row = $this->pluginRow(Plugin::where('identifier', $identifier)->find());
            if ($row !== null && (int) ($row['enabled'] ?? 0) !== 1 && $this->entitlement->can($identifier)) {
                $enable = $this->plugins->enable($identifier);
                if ($enable->isOk()) {
                    $steps[] = '已启用';
                }
            } elseif ($row !== null && (int) ($row['enabled'] ?? 0) === 1) {
                $steps[] = '已在运行';
            }
        } elseif ($autoEnable && $autoInstall && !$this->resolveAutoEnable($manifest)) {
            $steps[] = '已安装，未自动启用（第三方插件需手动启用）';
        }

        return null;
    }

    /**
     * 第三方（personal/enterprise）默认不自动 enable，除非 PIVARK_PLUGIN_THIRD_PARTY_AUTO_ENABLE=1
     *
     * @param array<string,mixed> $manifest
     */
    private function resolveAutoEnable(array $manifest): bool
    {
        if ((bool) config('plugin.security.third_party_auto_enable', false)) {
            return true;
        }

        $publisher = app(PluginManifestService::class)->resolvePublisherType($manifest);

        return $publisher === PluginManifestService::TYPE_OFFICIAL;
    }

    /**
     * 下载远程 package_url 并静态审计（不安装）
     *
     * @return ServiceResult
     */
    public function auditRemotePackage(string $identifier): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }

        $blockMsg = $this->blocklist->guardInstall($identifier);
        if ($blockMsg !== null) {
            return ServiceResult::fail($blockMsg);
        }

        $url = app(PluginMarketShelfDirectory::class)->packageUrl($identifier);
        if ($url === '') {
            return ServiceResult::fail('市场目录未提供 package_url');
        }

        $download = $this->downloadPackageToTemp($identifier, $url);
        if (!$download->isOk()) {
            return ServiceResult::fail($download->message());
        }

        $downloadData = $download->dataArray();
        $tmp = (string) ($downloadData['path'] ?? '');
        try {
            $audit = app(PluginPackageAuditService::class)->auditZipFile(
                $tmp,
                PluginPackageAuditService::AUDIT_CONTEXT_MARKET_CATALOG
            );
            $audit = app(PluginPackageAuditService::class)->applyCatalogSha256(
                $audit,
                app(PluginMarketShelfDirectory::class)->packageSha256($identifier)
            );
            $verbose = app(PluginSecurityPolicyService::class)->canViewTechnicalAudit();
            $audit = app(PluginSecurityPolicyService::class)->sanitizeAuditReport($audit, $verbose);

            return ServiceResult::ok([
                    'identifier'  => $identifier,
                    'package_url' => $url,
                    'audit'       => $audit,
                ], 'ok');
        } finally {
            LocalFile::unlinkIfExists($tmp);
        }
    }

    private function ensurePackageOnDisk(string $identifier): ServiceResult
    {
        return $this->packageFetch()->ensurePackageOnDisk($identifier);
    }

    private function downloadAndInstallPackage(string $identifier, string $url, bool $replaceExisting = false): ServiceResult
    {
        return $this->packageFetch()->downloadAndInstallPackage($identifier, $url, $replaceExisting);
    }

    private function downloadPackageToTemp(string $identifier, string $url): ServiceResult
    {
        return $this->packageFetch()->downloadPackageToTemp($identifier, $url);
    }

    private function resolveLocalPublicPackagePath(string $url): ?string
    {
        return $this->packageFetch()->resolveLocalPublicPackagePath($url);
    }

    private function resolvePackageUrl(string $url): string
    {
        return $this->packageFetch()->resolvePackageUrl($url);
    }

    private function packageFetch(): PluginMarketPackageFetchService
    {
        return app(PluginMarketPackageFetchService::class);
    }
    /**
     * 自动更新 / Cron：拉取远程包并升级（或首次安装）
     *
     * @return ServiceResult
     */
    public function applyRemotePackageForUpdate(string $identifier, string $packageUrl): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }

        $installed = $this->pluginRow(Plugin::where('identifier', $identifier)->where('installed', 1)->find()) !== null;
        $dl        = $this->downloadAndInstallPackage($identifier, $packageUrl, true);
        if (!$dl->isOk()) {
            return $dl;
        }

        if ($installed) {
            return $this->plugins->upgrade($identifier);
        }

        return $this->plugins->installFromWeapp($identifier);
    }

    /**
     * Cron 续跑：仅重试安装/启用（不再开通授权）
     */
    public function resumeInstallOnly(
        string $identifier,
        bool $autoInstall = true,
        bool $autoEnable = true,
        bool $withRemoteUpgrade = true,
    ): ServiceResult {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }

        // 续装与 acquire 同门：禁跳过 blocklist（J87）；WARN 期由 guardInstall 放行（J96）
        $blockMsg = $this->blocklist->guardInstall($identifier);
        if ($blockMsg !== null) {
            return ServiceResult::fail($blockMsg);
        }

        $manifest = $this->plugins->readManifest($identifier);
        if ($manifest === null || empty($manifest['_manifest_valid'])) {
            return ServiceResult::fail('插件不存在或清单无效');
        }

        $steps = [];
        $installErr = $this->appendInstallAndEnableSteps(
            $identifier,
            $manifest,
            $autoInstall,
            $autoEnable,
            $steps,
            $withRemoteUpgrade
        );
        if ($installErr !== null) {
            return $installErr;
        }

        app(AuditLogService::class)->operate('市场获取安装重试成功', 'admin.plugin', [
            'identifier' => $identifier,
            'steps'      => $steps,
        ]);

        return ServiceResult::ok(['steps' => $steps], '安装重试成功：' . implode(' → ', $steps));
    }

    /**
     * @param array{granted_now:bool,order_no:string,had_before:bool} $ctx
     */
    public function runAcquireFailureCompensation(string $identifier, array $ctx): string
    {
        return $this->compensateAcquireFailure($identifier, $ctx);
    }

    /**
     * 安装失败补偿：撤销本流程新开通的授权或退款
     *
     * @param array{granted_now:bool,order_no:string,had_before:bool} $ctx
     */
    private function compensateAcquireFailure(string $identifier, array $ctx): string
    {
        $notes = [];

        $orderNo = trim((string) ($ctx['order_no'] ?? ''));
        if ($orderNo !== '') {
            $refund = $this->commerce->refundEntitlementOrder($orderNo, '市场获取安装失败自动退款', 0, false);
            if ($refund->isOk()) {
                $notes[] = '已自动退款';
            } else {
                $msg = (string) ($refund->message() ?? '退款失败');
                $notes[] = '退款失败：' . $msg;
                app(PluginMarketAcquireReliabilityService::class)->enqueue('refund_order', [
                    'order_no' => $orderNo,
                    'reason'   => '市场获取安装失败自动退款（重试）',
                ], $msg);
            }
        } elseif (($ctx['granted_now'] ?? false) && !($ctx['had_before'] ?? false)) {
            // 只撤本流程 market 授权；禁 revokeManual 宽撤误伤手工/授权码（J100）
            $revoke = app(EntitlementService::class)->revokeIfGrantedBy($identifier, 'market');
            if ($revoke->isOk()) {
                $notes[] = (string) ($revoke->message() ?? '已撤销本流程开通的授权');
            } else {
                $msg = (string) ($revoke->message() ?? '撤销授权失败');
                $notes[] = '撤销授权失败：' . $msg;
                app(PluginMarketAcquireReliabilityService::class)->enqueue('revoke_entitlement', [
                    'identifier' => $identifier,
                    'granted_by' => 'market',
                ], $msg);
            }
        }

        return $notes === [] ? '' : '（' . implode('；', $notes) . '）';
    }

    /** @return array<string, mixed>|null */
    private function pluginRow(mixed $row): ?array
    {
        return $row instanceof Plugin ? $row->toArray() : null;
    }
}
