<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappPluginGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\support\ServiceResult;
use app\common\support\ProjectPaths;
use app\common\contract\PluginApiException;
use app\common\service\plugin\entitlement\EntitlementQueryService;
use app\common\contract\ImportIntentDelegateInterface;
use app\common\contract\PluginApiInterface;
use app\common\service\plugin\registry\PluginFrontTemplateRegistry;
use app\common\service\cron\PluginCronTaskRegistry;
use app\common\service\plugin\registry\PluginApiRegistry;
use app\common\service\plugin\registry\PluginCapabilityService;
use app\common\service\plugin\registry\PluginRouteService;
use app\common\service\plugin\extension\PluginEditorSurfaceService;
use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\service\plugin\security\PluginFileAccessGuard;
use app\common\service\plugin\gateway\PluginGatewayCallerContext;
use app\common\service\plugin\commerce\PluginMeteringService;
use app\common\service\plugin\registry\PluginExtensionRegistry;
use app\common\service\plugin\registry\WeappLogicalTableRegistry;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\weapp\PluginWeappAccess;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\service\plugin\commerce\PluginSkuFulfillmentRegistry;
use app\common\service\plugin\commerce\PluginSkuFulfillmentService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\product\OfferBridgeFacade;
use app\common\service\product\ProductL1Access;
use app\common\service\plugin\weapp\WeappPluginSaveSupport;

final class WeappPluginGateway
{

    public function __construct(
        private readonly PluginCapabilityService $pluginCapability,
        private readonly PluginApiRegistry $pluginApiRegistry,
        private readonly PluginRouteService $pluginRoute,
        private readonly WeappSchemaRunner $schemaRunner,
        private readonly PluginService $plugin,
        private readonly PluginEditorSurfaceService $pluginEditorSurface,
        private readonly WeappPluginSaveSupport $pluginSaveSupport,
        private readonly PluginMeteringService $pluginMetering,
        private readonly PluginSkuCatalogService $pluginSkuCatalog,
        private readonly PluginSkuFulfillmentRegistry $pluginSkuFulfillmentRegistry,
        private readonly OfferBridgeFacade $offerBridge,
        private readonly PluginSkuFulfillmentService $pluginSkuFulfillment,
    ) {
    }

    /** @return array<string, mixed> */
    public function pluginCapabilitySummary(string $identifier): array
    {
        return $this->pluginCapability->summary($identifier);
    }

    public function pluginRegisterAutoloadPublic(string $identifier): void
    {
        PluginService::registerAutoloadPublic($identifier);
    }

    public function pluginApiRegistryRegister(string $apiName, PluginApiInterface $api): void
    {
        $this->pluginApiRegistry->register($apiName, $api);
    }

    public function pluginApiResolve(string $pluginId, string $apiName): ?PluginApiInterface
    {
        return $this->pluginApiRegistry->resolve($pluginId, $apiName);
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function pluginApiInvoke(string $pluginId, string $apiName, string $method, array $params = []): mixed
    {
        try {
            return $this->pluginApiRegistry->invoke($pluginId, $apiName, $method, $params);
        } catch (PluginApiException) {
            return null;
        }
    }

    /**
     * @param array<string, string>   $pattern
     * @param list<class-string>|null $middleware
     */
    public function pluginRouteRegister(
        string $method,
        string $path,
        string $handler,
        array $pattern = [],
        ?array $middleware = null,
        bool $completeMatch = false,
    ): void {
        $this->pluginRoute->register($method, $path, $handler, $pattern, $middleware, $completeMatch);
    }

    public function weappSchemaApply(string $identifier): void
    {
        $this->schemaRunner->apply($identifier);
    }

    /** @return array<string, mixed>|null */
    public function pluginReadManifest(string $identifier): ?array
    {
        return $this->plugin->readManifest($identifier);
    }

    public function pluginEditorManifestDefaultSlot(string $identifier): string
    {
        return $this->pluginEditorSurface->manifestDefaultSlot($identifier);
    }

    public function pluginEditorResolveSlot(string $identifier): string
    {
        return $this->pluginEditorSurface->resolveSlot($identifier);
    }

    public function pluginEditorSaveSiteSlot(string $identifier, string $slot): ServiceResult
    {
        return $this->pluginEditorSurface->saveSiteSlot($identifier, $slot);
    }

    /** @return list<array<string, mixed>> */
    public function pluginEditorSurfaceSlotCards(string $identifier): array
    {
        return $this->pluginEditorSurface->surfaceSlotCards($identifier);
    }

    public function pluginAfterConfigSaved(string $scope = 'meta'): void
    {
        $this->pluginSaveSupport->afterConfigSaved($scope);
    }

    public function pluginIsEnabled(string $identifier): bool
    {
        return $this->plugin->isEnabled($identifier);
    }

    /**
     * document-addon / 报价桥是否可参与前台桥接（权益 + weapp 目录）
     *
     * @see \app\common\contract\WeappPluginBridgeTrait
     * @see \app\common\service\plugin\gateway\WeappPluginBridgeTrait
     */
    public function pluginBridgeEnabled(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if (ProductL1Access::isKernel($identifier)) {
            return ProductL1Access::allowsFrontBridge();
        }

        return app(EntitlementService::class)->can($identifier)
            && is_dir(ROOT_PATH . 'weapp/' . $identifier);
    }

    /** @return array<string, mixed>|null */
    public function pluginPresentationForAdmin(string $identifier): ?array
    {
        return $this->plugin->presentationForAdmin($identifier);
    }

    /** @return list<array<string, mixed>> */
    public function pluginDiscover(): array
    {
        return $this->plugin->discover();
    }

    public function pluginWeappRoot(): string
    {
        return $this->plugin->weappRoot();
    }

    /** 插件 runtime 目录内可写绝对路径（第三方 enforce 时校验白名单） */
    public function pluginWritablePath(string $relative): string
    {
        $caller = PluginGatewayCallerContext::currentIdentifier();
        if ($caller === null || $caller === '') {
            throw new \RuntimeException('pluginWritablePath 仅允许插件侧调用');
        }
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            throw new \RuntimeException('pluginWritablePath 相对路径非法');
        }
        $path = ProjectPaths::runtimeDir() . 'weapp/' . $caller . '/' . $relative;
        PluginFileAccessGuard::assertCallerWritablePath($path);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $path;
    }

    public function pluginMeteringCanConsume(string $identifier, string $action): ServiceResult
    {
        return $this->pluginMetering->canConsume($identifier, $action);
    }

    public function pluginMeteringConsume(string $identifier, string $action, int $units = 1, string $ref = ''): ServiceResult
    {
        return $this->pluginMetering->consume($identifier, $action, $units, $ref);
    }

    /** @return list<array<string, mixed>> */
    public function pluginPurchasableSkus(string $identifier): array
    {
        return $this->pluginSkuCatalog->purchasableSkusFor($identifier);
    }

    /** @param callable(string): void $handler */
    public function pluginSkuTierApplyRegister(string $identifier, callable $handler): void
    {
        $this->pluginSkuFulfillmentRegistry->registerTierApply($identifier, $handler);
    }

    public function pluginIsInstalled(string $identifier): bool
    {
        return $this->plugin->isInstalled($identifier);
    }

    public function pluginEnable(string $identifier): ServiceResult
    {
        return $this->plugin->enable($identifier);
    }

    public function pluginInstallFromWeapp(string $identifier): ServiceResult
    {
        return $this->plugin->installFromWeapp($identifier);
    }

    public function pluginDisable(string $identifier): ServiceResult
    {
        return $this->plugin->disable($identifier);
    }

    public function pluginFrontTemplateRegister(string $identifier, string $absoluteRoot): void
    {
        app(PluginFrontTemplateRegistry::class)->register($identifier, $absoluteRoot);
    }

    /**
     * 插件 boot：登记品项报价桥接（薄封装 PluginOfferBridgeRegistry，禁止插件直引 Registry）
     *
     * @param list<string> $legacyPaymentScenes
     */
    public function pluginOfferBridgeRegister(
        string $identifier,
        ?string $autoloadClass = null,
        array $legacyPaymentScenes = [],
        string $adminOrderSceneLabel = '',
    ): void {
        app(PluginOfferBridgeRegistry::class)->register(
            $identifier,
            $autoloadClass,
            $legacyPaymentScenes,
            $adminOrderSceneLabel,
        );
    }

    public function pluginCronLegacyHandlerRegister(
        string $handlerKey,
        string $identifier,
        string $taskId,
        string $description,
    ): void {
        app(PluginCronTaskRegistry::class)->registerLegacyHandler($handlerKey, $identifier, $taskId, $description);
    }

    /** @param list<mixed> $args */
    public function pluginWeappInvokeStatic(
        string $identifier,
        string $serviceClass,
        string $method,
        array $args,
        ?object $instance = null,
    ): mixed {
        return PluginWeappAccess::invokeStatic($identifier, $serviceClass, $method, $args, $instance);
    }

    public function offerBridge(): OfferBridgeFacade
    {
        return $this->offerBridge;
    }

    public function pluginSkuFulfillmentAvailable(): bool
    {
        return class_exists(PluginSkuFulfillmentService::class);
    }

    public function pluginSkuFulfillmentApplyForIdentifier(string $identifier): void
    {
        if (!$this->pluginSkuFulfillmentAvailable()) {
            return;
        }
        $this->pluginSkuFulfillment->applyForIdentifier($identifier);
    }

    /** @return array<string, mixed>|null */
    public function entitlementRowByPluginIdentifier(string $identifier): ?array
    {
        return app(EntitlementQueryService::class)->rowByPluginIdentifier($identifier);
    }

    /** @return list<string> */
    public function pluginSkuBillingTrialModes(): array
    {
        return [
            PluginSkuCatalogService::BILLING_FREE,
            PluginSkuCatalogService::BILLING_LIMITED_FREE,
            PluginSkuCatalogService::BILLING_TRIAL_TIME,
            PluginSkuCatalogService::BILLING_TRIAL_QUOTA,
        ];
    }

    /** @param array<string, string> $logicalToPhysical */
    public function pluginLogicalTableRegister(string $identifier, array $logicalToPhysical): void
    {
        app(WeappLogicalTableRegistry::class)->register($identifier, $logicalToPhysical);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function pluginExtensionRegisterDocumentAddonBridge(
        string $identifier,
        object $handler,
        int $priority = 100,
        array $meta = [],
    ): void {
        app(PluginExtensionRegistry::class)->register(
            PluginExtensionRegistry::POINT_DOCUMENT_ADDON_BRIDGE,
            $identifier,
            $handler,
            $priority,
            $meta,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function pluginExtensionRegister(
        string $pointId,
        string $identifier,
        callable|object $handler,
        int $priority = 100,
        array $meta = [],
    ): void {
        app(PluginExtensionRegistry::class)->register($pointId, $identifier, $handler, $priority, $meta);
    }

    public function pluginExtensionRegisterImportIntentDelegate(ImportIntentDelegateInterface $delegate, int $priority = 100): void
    {
        app(PluginExtensionRegistry::class)->registerImportIntentDelegate($delegate, $priority);
    }

    /** @param callable(array<string,mixed>): string $handler */
    public function pluginCronTaskRegister(string $identifier, string $taskId, callable $handler): void
    {
        app(PluginCronTaskRegistry::class)->register($identifier, $taskId, $handler);
    }
}
