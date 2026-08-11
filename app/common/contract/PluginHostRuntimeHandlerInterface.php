<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\contract;

interface PluginHostRuntimeHandlerInterface
{
    public function extensionId(): string;

    public function isHostRuntimeActive(): bool;

    public function licensePlatformTablesReady(): bool;

    public function registerPaymentFulfillments(): void;

    /** @return array<string, mixed>|null */
    public function pluginMarketPriceOverlay(string $pluginIdentifier): ?array;

    /** @return list<array<string, mixed>> */
    public function listChannelLicenseGrants(int $channelMemberId, int $limit = 200): array;

    /**
     * @param list<string> $siteKeys
     *
     * @return array<string, array<string, mixed>>
     */
    public function licenseRegistryBySiteKeys(array $siteKeys): array;

    /** @return array{site_key?:string,error?:string} */
    public function resolveSiteKeyForDomain(int $memberId, string $domain): array;

    public function memberOwnsSite(int $memberId, string $siteKey): bool;

    /** @return array<string, mixed>|null */
    public function findLicenseProduct(string $presetId): ?array;

    /**
     * @param list<string> $plugins
     * @param list<string> $coreFeatures
     */
    public function grantLicenseToSite(
        string $siteKey,
        array $plugins,
        string $coreTier,
        array $coreFeatures,
        string $note,
        string $licenseKind,
        ?int $expiresAt = null
    ): PluginHostRuntimeResult;

    /** @return array<string, mixed> */
    public function portalWidgetModuleFlags(): array;

    public function portalWidgetCommentHtml(int $docId): string;

    public function portalWidgetFavoriteBarHtml(int $docId): string;

    public function portalViewEsc(mixed $value): string;

    /** @param array{scene?:string,size_class?:string} $opts */
    public function renderPortalPayChannelButtons(array $opts = []): void;

    public function hostModulePresent(): bool;

    public function licenseCatalogReady(): bool;

    public function activate(
        string $siteKey,
        string $licenseCode,
        string $siteUrl = '',
        string $coreVersion = ''
    ): PluginHostRuntimeResult;

    public function sync(string $siteKey, string $siteUrl = '', string $coreVersion = ''): PluginHostRuntimeResult;

    /**
     * @param list<string> $plugins
     * @param array<string, mixed> $telemetry
     */
    public function heartbeat(
        string $siteKey,
        string $siteUrl = '',
        string $coreVersion = '',
        array $plugins = [],
        array $telemetry = []
    ): PluginHostRuntimeResult;

    /** @return list<array<string, mixed>> */
    public function pricingEditionCards(): array;

    /** @return array<string, mixed> */
    public function editionTermSummaries(): array;

    /** @return list<array<string, mixed>> */
    public function pricingEditionsFallback(): array;

    /** @return class-string|null */
    public function memberCenterAccountControllerClass(): ?string;

    /**
     * 官方宿主用量探测（供 HostRuntimeProbe::usageSnapshot，不含客户隐私明细）
     *
     * @return array<string, mixed>
     */
    public function usageTelemetry(): array;
}
