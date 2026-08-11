<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\service\plugin\extension\ExtensionTraceService;

/** 插件 SKU 档位履约扩展（plugin.sku_tier_apply） */
final class PluginSkuFulfillmentRegistry
{
    /** @var array<string, callable(string): void> */
    private static array $tierHandlers = [];

    public function reset(): void
    {
        self::$tierHandlers = [];
    }

    /** @param callable(string): void $handler */
    public function registerTierApply(string $identifier, callable $handler): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        self::$tierHandlers[$identifier] = $handler;
    }

    public function applyTier(string $identifier, string $tier): void
    {
        $identifier = strtolower(trim($identifier));
        $tier       = strtolower(trim($tier));
        if ($identifier === '' || $tier === '') {
            return;
        }
        if (!isset(self::$tierHandlers[$identifier])) {
            return;
        }
        app(ExtensionTraceService::class)->log('plugin.sku_tier_apply', $identifier, ['tier' => $tier]);
        (self::$tierHandlers[$identifier])($tier);
    }
}
