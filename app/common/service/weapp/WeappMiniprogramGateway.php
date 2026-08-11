<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappMiniprogramGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\channel\MiniprogramChannelRegistry;
use app\common\service\channel\MiniprogramFeatureRegistry;
use app\common\service\channel\MiniprogramChannelService;
use app\common\service\channel\MiniprogramDeliveryService;

final class WeappMiniprogramGateway
{

    public function __construct(
        private readonly MiniprogramChannelService $miniprogramChannel,
        private readonly MiniprogramDeliveryService $miniprogramDelivery,
    ) {
    }

    public function miniprogramChannelIsLicensed(): bool
    {
        return $this->miniprogramChannel->isLicensed();
    }

    /** @return array{code:int,msg:string}|null */
    public function miniprogramChannelAssertLicensed(): ?array
    {
        return $this->miniprogramChannel->assertLicensed();
    }

    public function miniprogramChannelIsActive(): bool
    {
        return $this->miniprogramChannel->isChannelActive();
    }

    public function miniprogramChannelOpen(): void
    {
        $this->miniprogramChannel->openChannel();
    }

    public function miniprogramChannelClose(): void
    {
        $this->miniprogramChannel->closeChannel();
    }

    /** @return array<string, mixed> */
    public function miniprogramChannelAdminMeta(): array
    {
        return $this->miniprogramChannel->adminMeta();
    }

    /** @return array{code:int,msg:string,path?:string} */
    public function miniprogramDeliveryBuildSdkZip(?string $platform = null, ?string $edition = null): array
    {
        return $this->miniprogramDelivery->buildSdkZip($platform, $edition);
    }

    public function miniprogramFeatureRegister(
        string $identifier,
        string $label,
        bool $needsLogin = false,
        ?callable $enabledChecker = null,
    ): void {
        app(MiniprogramFeatureRegistry::class)->register($identifier, $label, $needsLogin, $enabledChecker);
    }

    /**
     * @param array{label?:string, guide_route?:string, social_auth_required?:bool} $entry
     */
    public function miniprogramHubRegister(string $identifier, array $entry): void
    {
        app(MiniprogramChannelRegistry::class)->registerHub($identifier, $entry);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function miniprogramMarketSkuRegister(string $sku, array $row): void
    {
        app(MiniprogramChannelRegistry::class)->registerMarketSku($sku, $row);
    }

    /** @return array<string, array<string, mixed>> */
    public function miniprogramMarketSkus(): array
    {
        return app(MiniprogramChannelRegistry::class)->marketSkus();
    }

    public function miniprogramHubPlugin(): string
    {
        return app(MiniprogramChannelRegistry::class)->hubPlugin();
    }
}
