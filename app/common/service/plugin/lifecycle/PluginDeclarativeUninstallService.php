<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\lifecycle;

use app\common\support\ServiceResult;

/**
 * 声明式卸载：manifest lifecycle.uninstall_mode=declarative 时跳过 PHP uninstall() 回调。
 * 数据清理仍由 PluginService::uninstall 的 SQL/Schema/purge 档位负责。
 */
final class PluginDeclarativeUninstallService
{
    /**
     * @param array<string, mixed> $manifest
     */
    public function isDeclarative(array $manifest): bool
    {
        $lifecycle = is_array($manifest['lifecycle'] ?? null) ? $manifest['lifecycle'] : [];

        return strtolower(trim((string) ($lifecycle['uninstall_mode'] ?? ''))) === 'declarative';
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function validateDeclarativeManifest(array $manifest): ?string
    {
        if (!$this->isDeclarative($manifest)) {
            return null;
        }
        $uninstall = is_array($manifest['uninstall'] ?? null) ? $manifest['uninstall'] : [];
        if (($uninstall['allow_php_callback'] ?? false) === true) {
            return 'declarative 模式禁止 allow_php_callback=true';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function skipPhpCallback(array $manifest): ServiceResult
    {
        $err = $this->validateDeclarativeManifest($manifest);
        if ($err !== null) {
            return ServiceResult::fail($err);
        }
        if (!$this->isDeclarative($manifest)) {
            return ServiceResult::fail('非 declarative 卸载');
        }

        return ServiceResult::ok(null, '声明式卸载：已跳过 PHP uninstall 回调');
    }
}
