<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\license;

use app\common\service\plugin\extension\HostRuntimeProbe;
use app\common\support\ServiceResult;

/**
 * 宿主站点授权 API（经已注册 HostRuntimeHandler；自 HostRuntimeProbe 迁出）。
 */
final class HostLicenseRuntime
{
    public static function activate(
        string $siteKey,
        string $licenseCode,
        string $siteUrl = '',
        string $coreVersion = ''
    ): ServiceResult {
        $handler = HostRuntimeProbe::firstActiveHostRuntimeHandler();
        if ($handler === null) {
            return ServiceResult::ok(null, '授权平台模块未安装');
        }

        return $handler->activate($siteKey, $licenseCode, $siteUrl, $coreVersion);
    }

    /**
     * @param list<string> $plugins
     * @param array<string, mixed> $telemetry
     */
    public static function heartbeat(
        string $siteKey,
        string $siteUrl = '',
        string $coreVersion = '',
        array $plugins = [],
        array $telemetry = []
    ): ServiceResult {
        $handler = HostRuntimeProbe::firstActiveHostRuntimeHandler();
        if ($handler === null) {
            return ServiceResult::ok(null, '授权平台模块未安装');
        }

        return $handler->heartbeat($siteKey, $siteUrl, $coreVersion, $plugins, $telemetry);
    }

    public static function sync(
        string $siteKey,
        string $siteUrl = '',
        string $coreVersion = ''
    ): ServiceResult {
        $handler = HostRuntimeProbe::firstActiveHostRuntimeHandler();
        if ($handler === null) {
            return ServiceResult::ok(null, '授权平台模块未安装');
        }

        return $handler->sync($siteKey, $siteUrl, $coreVersion);
    }
}
