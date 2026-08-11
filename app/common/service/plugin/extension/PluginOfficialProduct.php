<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

use app\common\service\plugin\registry\PluginExtensionRegistry;

/**
 * 官方品项 / 产品中心扩展点调度（POINT_OFFICIAL_PRODUCT）。
 * 替代 HostRuntimeProbe::officialProduct 旁路。
 */
final class PluginOfficialProduct
{
    /**
     * @param array<string, mixed> $ctx
     */
    public static function dispatch(string $operation, array $ctx = [], mixed $default = null): mixed
    {
        return app(PluginExtensionRegistry::class)->dispatchOfficialProduct($operation, $ctx, $default);
    }

    public static function productCenterAdminEnabled(): bool
    {
        return (bool) self::dispatch('product_center_enabled', [], false);
    }
}
