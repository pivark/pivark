<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 宿主运行时 Service 结果契约（weapp 可 type-hint，避免直引 support\ServiceResult）
 */
declare(strict_types=1);

namespace app\common\contract;

interface PluginHostRuntimeResult
{
    public function isOk(): bool;

    public function message(): ?string;
}
