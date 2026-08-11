<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\registry;
use app\common\service\plugin\commerce\PluginMeteringService;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\service\plugin\entitlement\EntitlementCommandService;
use app\common\service\plugin\entitlement\EntitlementQueryService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\package\PluginPeerVersionRequirementService;
use app\common\service\plugin\gateway\PluginGatewayPermissionService;
use app\common\support\AppTime;

use app\common\service\kernel\KernelModuleRegistry;

final class PluginCapabilityService
{
    public function __construct(
        private readonly EntitlementService $entitlementService,
        private readonly EntitlementQueryService $entitlementQuery,
        private readonly EntitlementCommandService $entitlementCommand,
        private readonly PluginSkuCatalogService $pluginSkuCatalogService,
        private readonly KernelModuleRegistry $kernelModuleRegistry,
    ) {
    }

    /**
     * @return list<string> commercial.features（小写）
     */
    public function declaredFeatures(string $identifier): array
    {
        $manifest = app(PluginService::class)->readManifest($identifier);
        if ($manifest === null) {
            return [];
        }

        return $this->featuresFromManifest($manifest);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function featuresFromManifest(array $manifest): array
    {
        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        $features   = $commercial['features'] ?? [];
        if (!is_array($features)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $features
        ))));
    }

    /**
     * 当前 SKU 实际授予的 features（manifest ∩ SKU.features）
     *
     * @return list<string> 空数组表示「未启用 feature 模型」或「未授权」
     */
    public function grantedFeatures(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !$this->entitlementService->can($identifier)) {
            return [];
        }

        $declared = $this->declaredFeatures($identifier);
        if ($declared === []) {
            return [];
        }

        $sku = $this->pluginSkuCatalogService->resolveEffectiveSkuRow($identifier);

        return $this->pluginSkuCatalogService->grantedFeaturesFromSku($sku, $declared);
    }

    /**
     * @return list<string> plugin.json dependencies（小写）
     */
    public function declaredDependencies(string $identifier): array
    {
        $manifest = app(PluginService::class)->readManifest($identifier);
        if ($manifest === null) {
            return [];
        }
        $deps = $manifest['dependencies'] ?? [];
        if (!is_array($deps)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $deps
        ))));
    }

    /**
     * @return list<string> plugin.json plugin_needs（小写 · ADR-28 硬依赖）
     */
    public function declaredPluginNeeds(string $identifier): array
    {
        $manifest = app(PluginService::class)->readManifest($identifier);
        if ($manifest === null) {
            return [];
        }
        $needs = $manifest['plugin_needs'] ?? [];
        if (!is_array($needs)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $needs
        ))));
    }

    /**
     * manifest 声明的插件间硬依赖（dependencies + plugin_needs）
     *
     * @return list<string>
     */
    public function declaredHardPluginNeeds(string $identifier): array
    {
        return array_values(array_unique(array_merge(
            $this->declaredDependencies($identifier),
            $this->declaredPluginNeeds($identifier),
        )));
    }

    /**
     * 已装插件中仍声明依赖 $targetIdentifier 的条目（通用反向扫描 · 卸载阻断）
     *
     * @return list<array{identifier:string,name:string}>
     */
    public function dependentsOf(string $targetIdentifier): array
    {
        $target = strtolower(trim($targetIdentifier));
        if ($target === '') {
            return [];
        }

        $out = [];
        foreach (app(PluginService::class)->listInstalledIdentifiers() as $id) {
            $id = strtolower(trim($id));
            if ($id === '' || $id === $target) {
                continue;
            }
            if (!in_array($target, $this->declaredHardPluginNeeds($id), true)) {
                continue;
            }
            $manifest = app(PluginService::class)->readManifest($id);
            $name     = is_array($manifest) ? trim((string) ($manifest['name'] ?? '')) : '';
            $out[]    = [
                'identifier' => $id,
                'name'       => $name !== '' ? $name : $id,
            ];
        }

        return $out;
    }

    /** 卸载前依赖检查：通过返回 null，否则返回拒绝原因 */
    public function uninstallGuardMessage(string $targetIdentifier): ?string
    {
        if (!(bool) config('plugin.security.capability_enforce_dependents_on_uninstall', true)) {
            return null;
        }

        $dependents = $this->dependentsOf($targetIdentifier);
        if ($dependents === []) {
            return null;
        }

        $labels = array_map(
            static fn (array $row): string => $row['name'] . ' (' . $row['identifier'] . ')',
            $dependents,
        );

        return '无法卸载：以下已安装插件仍声明依赖它 — ' . implode('、', $labels);
    }

    /** 安装前检查 plugin_needs（dependencies 仍走 enableGuardMessage） */
    public function installHardNeedsGuardMessage(string $identifier): ?string
    {
        $identifier = strtolower(trim($identifier));
        $installed  = array_map(
            static fn (string $id): string => strtolower(trim($id)),
            app(PluginService::class)->listInstalledIdentifiers(),
        );
        $missing = [];
        foreach ($this->declaredPluginNeeds($identifier) as $dep) {
            if ($dep === '' || $dep === $identifier) {
                continue;
            }
            if (!in_array($dep, $installed, true) || !app(PluginService::class)->isEnabled($dep)) {
                $missing[] = $dep;
            }
        }
        if ($missing === []) {
            return null;
        }

        return '硬依赖插件未就绪：' . implode('、', array_values(array_unique($missing)));
    }

    /**
     * @return list<string> plugin.json needs（小写）
     */
    public function declaredNeeds(string $identifier): array
    {
        return $this->kernelModuleRegistry->needsForPlugin($identifier);
    }

    /**
     * 插件已授权且 feature 在当前 SKU 授予范围内
     */
    public function canFeature(string $identifier, string $feature): bool
    {
        $identifier = strtolower(trim($identifier));
        $feature    = strtolower(trim($feature));
        if ($identifier === '' || $feature === '') {
            return false;
        }
        if (!$this->entitlementService->can($identifier)) {
            return false;
        }

        $declared = $this->declaredFeatures($identifier);
        if ($declared === []) {
            if ((bool) config('plugin.security.capability_enforce_features_runtime', false)) {
                $billable = app(PluginMeteringService::class)->billableActions($identifier);
                if ($billable !== [] && in_array($feature, array_map(
                    static fn (string $v): string => strtolower(trim($v)),
                    $billable
                ), true)) {
                    return false;
                }
            }

            return true;
        }
        if (!in_array($feature, $declared, true)) {
            return false;
        }

        $granted = $this->grantedFeatures($identifier);

        return in_array($feature, $granted, true);
    }

    /**
     * @return list<string> 未安装/未启用/未授权的依赖插件 identifier
     */
    public function unsatisfiedDependencies(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }

        $missing = [];
        $catalog  = $this->kernelModuleRegistry->catalog();
        foreach ($this->declaredDependencies($identifier) as $dep) {
            if ($dep === '' || $dep === $identifier) {
                continue;
            }
            if (isset($catalog[$dep])) {
                if (!$this->kernelModuleRegistry->isActive($dep)) {
                    $missing[] = $dep;
                }
                continue;
            }
            if (!is_dir(app(PluginService::class)->weappRoot() . $dep) || !app(PluginService::class)->isEnabled($dep)) {
                $missing[] = $dep;
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * 启用前依赖检查：通过返回 null，否则返回拒绝原因
     */
    public function enableGuardMessage(string $identifier): ?string
    {
        if (!(bool) config('plugin.security.capability_enforce_dependencies_on_enable', true)) {
            return null;
        }

        $missing = $this->unsatisfiedDependencies($identifier);
        if ($missing !== []) {
            return '依赖插件未就绪：' . implode('、', $missing);
        }

        $peerVersion = app(PluginPeerVersionRequirementService::class)->unsatisfiedForEnable($identifier);
        if ($peerVersion !== []) {
            return implode('；', $peerVersion);
        }

        return null;
    }

    /**
     * zip / 审包：manifest 能力与计量 action 一致性
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function auditManifest(array $manifest, string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        $issues     = [];

        $deps = $manifest['dependencies'] ?? [];
        if (is_array($deps)) {
            foreach ($deps as $dep) {
                $dep = strtolower(trim((string) $dep));
                if ($dep === '') {
                    continue;
                }
                if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $dep)) {
                    $issues[] = 'dependencies 含非法 identifier：' . $dep;
                }
            }
        }

        $declared = $this->featuresFromManifest($manifest);
        $billable = app(PluginMeteringService::class)->billableActions($identifier);
        foreach ($billable as $action) {
            $action = strtolower(trim($action));
            if ($action === '') {
                continue;
            }
            if ($declared === []) {
                $issues[] = '存在计量 action「' . $action . '」但 commercial.features 未声明';
                continue;
            }
            if (!in_array($action, $declared, true)) {
                $issues[] = '计量 action「' . $action . '」不在 commercial.features 内';
            }
        }

        $issues = array_merge(
            $issues,
            app(PluginGatewayPermissionService::class)->auditManifestGatewayPermissions($manifest)
        );
        $issues = array_merge(
            $issues,
            app(PluginCapabilitySlotConflictService::class)->auditManifestSlots($manifest)
        );
        $issues = array_merge(
            $issues,
            app(PluginPeerVersionRequirementService::class)->syntaxErrors($manifest)
        );

        return array_values(array_unique($issues));
    }

    /**
     * @return array{
     *   features:list<string>,
     *   granted_features:list<string>,
     *   active_sku_id:string,
     *   active_sku_name:string,
     *   dependencies:list<string>,
     *   needs:list<string>,
     *   missing_dependencies:list<string>,
     *   kernel_modules:list<array{id:string,label:string,active:bool}>
     * }
     */
    public function summary(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        $catalog    = $this->kernelModuleRegistry->catalog();
        $needs      = $this->declaredNeeds($identifier);
        $modules    = [];
        foreach ($needs as $need) {
            $meta      = is_array($catalog[$need] ?? null) ? $catalog[$need] : [];
            $modules[] = [
                'id'     => $need,
                'label'  => (string) ($meta['label'] ?? $need),
                'active' => $this->kernelModuleRegistry->isActive($need),
            ];
        }

        $sku = $this->pluginSkuCatalogService->resolveEffectiveSkuRow($identifier);
        $declared = $this->declaredFeatures($identifier);
        $granted  = $this->entitlementService->can($identifier)
            ? $this->pluginSkuCatalogService->grantedFeaturesFromSku($sku, $declared)
            : [];

        return [
            'features'              => $declared,
            'granted_features'      => $granted,
            'active_sku_id'         => is_array($sku) ? (string) ($sku['sku_id'] ?? '') : '',
            'active_sku_name'       => is_array($sku) ? (string) ($sku['name'] ?? '') : '',
            'dependencies'          => $this->declaredDependencies($identifier),
            'needs'                 => $needs,
            'missing_dependencies'  => $this->unsatisfiedDependencies($identifier),
            'kernel_modules'        => $modules,
            'snapshot'              => $this->readEntitlementSnapshot($identifier),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readEntitlementSnapshot(string $identifier): ?array
    {
        return $this->entitlementQuery->readCapabilitySnapshot($identifier);
    }

    /** 授权/SKU/启用后写入快照；无 entitlement 行时跳过 */
    public function refreshEntitlementSnapshot(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $this->entitlementQuery->rowByPluginIdentifier($identifier) === null) {
            return;
        }

        $payload = $this->currentSnapshotPayload($identifier);
        $payload['synced_at'] = AppTime::now();

        $this->entitlementCommand->updateCapabilitySnapshot($identifier, $payload);
    }

    public function clearEntitlementSnapshot(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        $this->entitlementCommand->clearCapabilitySnapshot($identifier);
    }

    /** 刷新所有已装且已授权插件快照（安全同步时调用） */
    public function refreshAllEntitlementSnapshots(): int
    {
        $count = 0;
        foreach (app(PluginService::class)->listInstalledIdentifiers() as $id) {
            if (!$this->entitlementService->can($id)) {
                continue;
            }
            $this->refreshEntitlementSnapshot($id);
            $count++;
        }

        return $count;
    }

    /**
     * 已启用且 SKU features 为 manifest 真子集（试用/低配 SKU）
     *
     * @return list<array{
     *   identifier:string,
     *   active_sku_id:string,
     *   granted_features:list<string>,
     *   denied_features:list<string>
     * }>
     */
    public function enabledFeatureSubsetGaps(): array
    {
        $out = [];
        foreach (app(PluginService::class)->listInstalledIdentifiers() as $id) {
            if (!app(PluginService::class)->isEnabled($id)) {
                continue;
            }
            $row = $this->featureSubsetGapRow($id);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   identifier:string,
     *   active_sku_id:string,
     *   granted_features:list<string>,
     *   denied_features:list<string>
     * }|null
     */
    public function featureSubsetGapRow(string $identifier): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !app(PluginService::class)->isEnabled($identifier) || !$this->entitlementService->can($identifier)) {
            return null;
        }

        $declared = $this->declaredFeatures($identifier);
        if ($declared === []) {
            return null;
        }

        $sku = $this->pluginSkuCatalogService->resolveEffectiveSkuRow($identifier);
        if (!is_array($sku) || !is_array($sku['features'] ?? null) || $sku['features'] === []) {
            return null;
        }

        $granted = $this->grantedFeatures($identifier);
        $denied  = array_values(array_diff($declared, $granted));
        if ($denied === []) {
            return null;
        }

        return [
            'identifier'       => $identifier,
            'active_sku_id'    => (string) ($sku['sku_id'] ?? ''),
            'granted_features' => $granted,
            'denied_features'  => $denied,
        ];
    }

    /**
     * 已启用但依赖未满足的插件（安全面板用）
     *
     * @return list<array{identifier:string,missing_dependencies:list<string>}>
     */
    public function enabledDependencyGaps(): array
    {
        $out = [];
        foreach (app(PluginService::class)->listInstalledIdentifiers() as $id) {
            if (!app(PluginService::class)->isEnabled($id)) {
                continue;
            }
            $missing = $this->unsatisfiedDependencies($id);
            if ($missing === []) {
                continue;
            }
            $out[] = [
                'identifier'           => $id,
                'missing_dependencies' => $missing,
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *   features:list<string>,
     *   granted_features:list<string>,
     *   active_sku_id:string,
     *   active_sku_name:string,
     *   missing_dependencies:list<string>
     * }
     */
    public function currentSnapshotPayload(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        $sku        = $this->pluginSkuCatalogService->resolveEffectiveSkuRow($identifier);
        $declared   = $this->declaredFeatures($identifier);
        $granted    = $this->entitlementService->can($identifier)
            ? $this->pluginSkuCatalogService->grantedFeaturesFromSku($sku, $declared)
            : [];

        return [
            'features'             => $declared,
            'granted_features'     => $granted,
            'active_sku_id'        => is_array($sku) ? (string) ($sku['sku_id'] ?? '') : '',
            'active_sku_name'      => is_array($sku) ? (string) ($sku['name'] ?? '') : '',
            'missing_dependencies' => $this->unsatisfiedDependencies($identifier),
        ];
    }

    /**
     * DB 快照与当前 live 能力不一致（SKU/manifest 变更后未刷新）
     *
     * @return list<array{
     *   identifier:string,
     *   snapshot_synced_at:string,
     *   drifts:list<array{field:string,snapshot:mixed,current:mixed}>
     * }>
     */
    public function enabledSnapshotDrifts(): array
    {
        $out = [];
        foreach (app(PluginService::class)->listInstalledIdentifiers() as $id) {
            if (!$this->entitlementService->can($id)) {
                continue;
            }
            $row = $this->snapshotDriftRow($id);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   identifier:string,
     *   snapshot_synced_at:string,
     *   drifts:list<array{field:string,snapshot:mixed,current:mixed}>
     * }|null
     */
    public function snapshotDriftRow(string $identifier): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !$this->entitlementService->can($identifier)) {
            return null;
        }

        $snapshot = $this->readEntitlementSnapshot($identifier);
        if ($snapshot === null) {
            return null;
        }

        $current = $this->currentSnapshotPayload($identifier);
        $drifts  = [];

        $fields = [
            'features'             => 'manifest.features',
            'granted_features'     => 'granted_features',
            'active_sku_id'        => 'active_sku_id',
            'missing_dependencies' => 'missing_dependencies',
        ];
        foreach ($fields as $key => $label) {
            $oldVal = array_key_exists($key, $snapshot) ? $snapshot[$key] : null;
            $newVal = $current[$key];
            if (is_array($oldVal) || is_array($newVal)) {
                $oldNorm = $this->normalizeStringList(is_array($oldVal) ? $oldVal : []);
                $newNorm = $this->normalizeStringList(is_array($newVal) ? $newVal : []);
                if ($oldNorm !== $newNorm) {
                    $drifts[] = ['field' => $label, 'snapshot' => $oldNorm, 'current' => $newNorm];
                }
                continue;
            }
            if ((string) $oldVal !== (string) $newVal) {
                $drifts[] = ['field' => $label, 'snapshot' => $oldVal, 'current' => $newVal];
            }
        }

        if ($drifts === []) {
            return null;
        }

        return [
            'identifier'         => $identifier,
            'snapshot_synced_at'   => (string) ($snapshot['synced_at'] ?? ''),
            'drifts'               => $drifts,
        ];
    }

    /**
     * @param list<string>|mixed $values
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $values
        ))));
    }
}
