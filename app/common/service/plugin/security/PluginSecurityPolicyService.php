<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);



namespace app\common\service\plugin\security;



use app\common\service\plugin\extension\HostRuntimeProbe;
use app\common\service\plugin\package\PluginPackageSignatureService;

use app\common\service\user\PermissionService;



final class PluginSecurityPolicyService
{


    public function __construct(

        private readonly PermissionService $permissionService,

        private readonly PluginPackageSignatureService $pluginPackageSignatureService,

    ) {

    }



    /**

     * 本地上传 zip 校验；通过返回 null，否则返回拒绝原因

     */

    public function localUploadGuard(int $adminUserId = 0): ?string

    {

        if (!(bool) config('plugin.security.local_upload_enabled', true)) {

            return '本站已关闭本地上传 zip，请从插件市场安装';

        }



        if ((bool) config('plugin.security.local_upload_super_admin_only', false)) {

            if ($adminUserId < 1 || !$this->permissionService->isSuperAdmin($adminUserId)) {

                return '仅超级管理员可本地上传插件包';

            }

        }



        return null;

    }



    /**

     * 后台「我的插件」是否展示 Gateway / 快照等开发诊断。

     * 演示站、客户站、host_only 默认关；内核开发请设 PIVARK_PLUGIN_ADMIN_SECURITY_DIAG=1。

     */

    public function adminDiagnosticsPanel(): bool

    {

        $raw = env('PIVARK_PLUGIN_ADMIN_SECURITY_DIAG');

        if ($raw !== null && $raw !== '') {

            return filter_var($raw, FILTER_VALIDATE_BOOLEAN);

        }



        return (bool) config('plugin.security.gateway_direct_service_block', false);

    }



    /** 授权平台运营「市场合规」面板（platform host 或显式开启开发诊断） */

    public function marketCompliancePanel(): bool

    {

        if ($this->adminDiagnosticsPanel()) {

            return true;

        }



        return HostRuntimeProbe::isAnyHostRuntimeActive();

    }



    public function canViewTechnicalAudit(int $adminUserId = 0): bool

    {

        if ($this->adminDiagnosticsPanel() || $this->marketCompliancePanel()) {

            return true;

        }



        return $adminUserId > 0 && $this->permissionService->isSuperAdmin($adminUserId);

    }



    /**

     * @param array<string, string> $raw

     * @return array<string, string>

     */

    public function userBootFailures(array $raw): array

    {

        $out = [];

        foreach ($raw as $id => $message) {

            $out[(string) $id] = $this->userBootFailureMessage((string) $id, (string) $message);

        }



        return $out;

    }



    public function userBootFailureMessage(string $identifier, string $raw): string

    {

        unset($identifier);

        $raw = trim($raw);

        if ($raw === '') {

            return '插件无法正常启动，请尝试重新安装或联系插件提供方。';

        }

        if (

            str_contains($raw, '\\')

            || str_contains($raw, 'RuntimeException')

            || str_contains($raw, '::')

            || str_contains($raw, 'Stack trace')

        ) {

            return '插件无法正常启动，请尝试重新安装或联系插件提供方。';

        }



        return '插件无法正常启动：' . mb_substr($raw, 0, 120);

    }



    /**

     * @param array<string, mixed> $audit

     * @return array<string, mixed>

     */

    public function sanitizeAuditReport(array $audit, bool $verbose): array

    {

        if ($verbose) {

            return $audit;

        }



        $blocks = [];

        foreach ($audit['blocks'] ?? [] as $block) {

            $human = $this->humanizeAuditLine((string) $block, true);

            if ($human !== null && !in_array($human, $blocks, true)) {

                $blocks[] = $human;

            }

        }

        if ($blocks === [] && ($audit['level'] ?? '') === 'block') {

            $blocks[] = '插件包未通过安全审计，无法安装。请使用官方市场版本，或联系插件开发者整改后重新上传。';

        }



        $warns = [];

        foreach ($audit['warns'] ?? [] as $warn) {

            $human = $this->humanizeAuditLine((string) $warn, false);

            if ($human !== null && !in_array($human, $warns, true)) {

                $warns[] = $human;

            }

        }



        $audit['blocks']         = $blocks;

        $audit['warns']          = $warns;

        $audit['gateway_issues'] = [];



        return $audit;

    }



    /**

     * @param array<string, mixed> $audit

     */

    public function installBlockedMessage(array $audit, bool $verbose): string

    {

        if ($verbose) {

            $preview = array_slice($audit['blocks'] ?? [], 0, 5);



            return '安全审计未通过：' . implode('；', array_map('strval', $preview));

        }



        return '插件包未通过安全审计，无法安装。请使用官方市场版本，或联系插件开发者按 PivArk 插件规范整改后重新上传。';

    }



    /**

     * @return array<string, mixed>

     */

    public function summaryForAdmin(int $adminUserId = 0): array

    {

        $uploadBlock = $this->localUploadGuard($adminUserId);



        return [

            'local_upload_enabled'          => (bool) config('plugin.security.local_upload_enabled', true),

            'local_upload_super_admin_only' => (bool) config('plugin.security.local_upload_super_admin_only', false),

            'local_upload_allowed'          => $uploadBlock === null,

            'local_upload_block_reason'     => $uploadBlock ?? '',

            'sign_required'                 => $this->pluginPackageSignatureService->signRequired(),

            'sign_key_configured'           => $this->pluginPackageSignatureService->signingKey() !== '',

            'gateway_direct_service_block'  => (bool) config('plugin.security.gateway_direct_service_block', false),

            'audit_block_install'           => (bool) config('plugin.security.audit_block_install', true),

            'third_party_auto_enable'       => (bool) config('plugin.security.third_party_auto_enable', false),

            'verify_catalog_sha256'         => (bool) config('plugin.security.verify_catalog_sha256', true),

            'health_check_on_enable'        => (bool) config('plugin.security.health_check_on_enable', true),

            'sandbox_boot_on_enable'        => (bool) config('plugin.security.sandbox_boot_on_enable', true),

            'auto_safe_mode_on_mass_boot_fail' => (bool) config('plugin.security.auto_safe_mode_on_mass_boot_fail', true),

            'capability_enforce_dependencies_on_enable' => (bool) config('plugin.security.capability_enforce_dependencies_on_enable', true),

            'capability_enforce_features_runtime' => (bool) config('plugin.security.capability_enforce_features_runtime', false),

            'install_backup_zip'            => (bool) config('plugin.security.install_backup_zip', true),

            'capability_manifest_audit_block' => (bool) config('plugin.security.capability_manifest_audit_block', false),

            'cron_refresh_capability_snapshot' => (bool) config('plugin.security.cron_refresh_capability_snapshot', true),

            'gateway_enforce_env'           => 'PIVARK_PLUGIN_GATEWAY_ENFORCE',

            'gateway_enforce_recommended'   => true,

            'emergency_bypass_configured'   => trim((string) config('plugin.security.emergency_bypass_token', '')) !== '',

            'admin_diagnostics_panel'       => $this->adminDiagnosticsPanel(),

            'market_compliance_panel'       => $this->marketCompliancePanel(),

        ];

    }



    private function humanizeAuditLine(string $line, bool $isBlock): ?string

    {

        $line = trim($line);

        if ($line === '') {

            return null;

        }

        if (
            str_starts_with($line, 'Gateway：')
            || str_starts_with($line, '插件接口：')
            || str_contains($line, 'WeappCoreGateway')
            || str_contains($line, 'Weapp*Gateway')
            || (str_contains($line, 'app\\common\\service\\') && str_contains($line, '禁止'))
        ) {
            return $isBlock
                ? '插件包不符合本站插件接入规范，无法安装。'
                : null;
        }
        if (str_starts_with($line, '能力清单：')) {
            return $line;
        }

        return $line;
    }
}

