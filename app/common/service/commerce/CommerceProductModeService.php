<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\commerce;

use app\common\model\Plugin;
use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\registry\PluginCapabilitySlotConflictService;
use app\common\service\release\PivarkEditionService;

/**
 * 站内卖货模式只读视图（AD-029）
 *
 * 占位者由 manifest `capability_slots.commerce_product_mode` 声明；
 * 启用互斥执法在 PluginCapabilitySlotConflictService（功能槽，非插件名名单）。
 */
final class CommerceProductModeService
{
    public const MODE_B2C           = 'b2c';
    public const MODE_HOST_OFFICIAL = 'host_official';
    public const MODE_CONFLICT      = 'conflict';

    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            self::MODE_B2C           => '站内卖货（B2C）',
            self::MODE_HOST_OFFICIAL => '平台宿主（本站独属）',
            self::MODE_CONFLICT      => '站内卖货能力冲突（多个占位者同时启用）',
            default                  => $mode,
        };
    }

    public static function modeLabelForPlugin(string $pluginId): string
    {
        $id = strtolower(trim($pluginId));
        if (PluginDistributionPolicy::isHostOnly($id)) {
            return self::modeLabel(self::MODE_HOST_OFFICIAL);
        }
        if (self::holdsCommerceProductModeSlot($id)) {
            return self::modeLabel(self::MODE_B2C);
        }

        return $pluginId;
    }

    /** @return self::MODE_*|null */
    public static function resolveActiveMode(): ?string
    {
        $holders = self::enabledCommerceSlotHolders();
        if (count($holders) > 1) {
            return self::MODE_CONFLICT;
        }
        if ($holders === []) {
            return null;
        }

        $enabledId = $holders[0];
        if (PluginDistributionPolicy::isHostOnly($enabledId)) {
            return self::MODE_HOST_OFFICIAL;
        }

        return self::MODE_B2C;
    }

    public static function shopProductCenterNavAllowed(): bool
    {
        return self::resolveActiveMode() === self::MODE_B2C;
    }

    public static function platformEnableAllowed(): bool
    {
        return PluginDistributionPolicy::bundlePresent()
            && in_array(app(PivarkEditionService::class)->edition(), [
                PivarkEditionService::PLATFORM,
                PivarkEditionService::DEV,
            ], true);
    }

    /** 平台站冲突自愈：保留发行形态受限插件，关闭其它站内卖货槽占位者 */
    public static function healConflictPreferHostOnlyCommerce(): bool
    {
        if (self::resolveActiveMode() !== self::MODE_CONFLICT) {
            return false;
        }
        if (!app(PivarkEditionService::class)->isPlatform()) {
            return false;
        }

        $changed = false;
        $plugin  = app(PluginService::class);
        foreach (self::enabledCommerceSlotHolders() as $id) {
            if (PluginDistributionPolicy::isHostOnly($id)) {
                continue;
            }
            $plugin->disable($id);
            $changed = true;
        }

        return $changed;
    }

    /** @return list<string> */
    private static function enabledCommerceSlotHolders(): array
    {
        $plugin = app(PluginService::class);
        $slots  = app(PluginCapabilitySlotConflictService::class);
        $out    = [];
        foreach (Plugin::where('installed', 1)->where('enabled', 1)->order('id', 'asc')->column('identifier') as $id) {
            $id = strtolower(trim((string) $id));
            if ($id === '' || !$plugin->isEnabled($id)) {
                continue;
            }
            if ($slots->commerceProductModeSlots($plugin->readManifest($id)) === []) {
                continue;
            }
            $out[] = $id;
        }

        return $out;
    }

    private static function holdsCommerceProductModeSlot(string $identifier): bool
    {
        $plugin = app(PluginService::class);

        return app(PluginCapabilitySlotConflictService::class)
            ->commerceProductModeSlots($plugin->readManifest($identifier)) !== [];
    }
}
