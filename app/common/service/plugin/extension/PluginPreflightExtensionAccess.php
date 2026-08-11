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

/** 插件中心 preflight 扩展标记（各插件 boot 注册，内核合并） */
final class PluginPreflightExtensionAccess
{
    /** @return array<string, mixed> */
    public static function collectFlags(): array
    {
        return app(PluginExtensionRegistry::class)->collectPluginPreflightFlags();
    }
}
