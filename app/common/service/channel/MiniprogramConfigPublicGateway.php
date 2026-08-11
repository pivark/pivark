<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\channel;

use app\common\service\site\SiteSlideService;

/**
 * 小程序配置/页面块 v1 API 可注入门面（Phase 2 DI）。
 */
final class MiniprogramConfigPublicGateway
{

    public const SLOT_HOME_CAROUSEL = SiteSlideService::SLOT_HOME_CAROUSEL;

    /** @return array<string, mixed> */
    public function wechatPayload(): array
    {
        return app(MiniprogramConfigService::class)->publicWechatPayload();
    }

    /** @return array<string, mixed> */
    public function bootstrapPayload(): array
    {
        return app(MiniprogramBootstrapService::class)->payload();
    }

    /** @return array<string, mixed> */
    public function homePagePayload(): array
    {
        return app(MiniprogramPageConfigService::class)->publicHomePayload();
    }

    /** @return array<string, mixed> */
    public function tagsPagePayload(): array
    {
        return app(MiniprogramPageConfigService::class)->publicTagsPayload();
    }

    /** @return array<string, mixed> */
    public function productsPagePayload(): array
    {
        return app(MiniprogramPageConfigService::class)->publicProductsPayload();
    }

    /** @return array<string, mixed> */
    public function minePagePayload(): array
    {
        return app(MiniprogramPageConfigService::class)->publicMinePayload();
    }

    /** @return array<string, mixed> */
    public function slides(string $slot = ''): array
    {
        $slot = trim($slot);
        if ($slot === '') {
            $slot = self::SLOT_HOME_CAROUSEL;
        }

        return app(SiteSlideService::class)->listPublic($slot);
    }
}
