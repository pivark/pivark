<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappKernelGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\kernel\KernelModuleRegistry;

final class WeappKernelGateway
{

    public function __construct(
        private readonly KernelModuleRegistry $kernelModules,
    ) {
    }

    public function kernelEnsureModule(string $module): void
    {
        $this->kernelModules->ensureModule($module);
    }

    /** @return array<string, array<string, mixed>> */
    public function kernelModuleCatalog(): array
    {
        return $this->kernelModules->catalog();
    }

    public function kernelModuleIsActive(string $module): bool
    {
        return $this->kernelModules->isActive($module);
    }
}
