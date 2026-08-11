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
 * weapp/{id}/Plugin.php 生命周期钩子（install/enable/disable/uninstall/boot）。
 */
interface WeappPluginLifecycle
{
    public function install(): void;

    public function enable(): void;

    public function disable(): void;

    public function uninstall(): void;

    public function boot(): void;
}
