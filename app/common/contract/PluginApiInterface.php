<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\contract;

/**
 * 插件对外 API 契约：跨插件调用须经 PluginApiRegistry，禁止直查他插件表。
 */
interface PluginApiInterface
{
    /** weapp 目录名，如 comment、gallery */
    public function pluginIdentifier(): string;

    /**
     * 动态调用已声明的公开方法（跨插件须经 PluginApiRegistry::invoke）。
     *
     * @param array<int|string, mixed> $params
     */
    public function call(string $method, array $params = []): mixed;
}
