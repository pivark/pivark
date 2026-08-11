<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\lifecycle;

use app\common\contract\WeappPluginLifecycle;

/**
 * 将历史 weapp Plugin 实例适配为 {@see WeappPluginLifecycle}，供 PHPStan 与 Gateway 统一调用。
 */
final class WeappPluginLifecycleAdapter implements WeappPluginLifecycle
{
    public function __construct(private readonly object $target)
    {
    }

    public static function from(object $target): WeappPluginLifecycle
    {
        return $target instanceof WeappPluginLifecycle ? $target : new self($target);
    }

    public function install(): void
    {
        $this->invoke('install');
    }

    public function enable(): void
    {
        $this->invoke('enable');
    }

    public function disable(): void
    {
        $this->invoke('disable');
    }

    public function uninstall(): void
    {
        $this->invoke('uninstall');
    }

    public function boot(): void
    {
        $this->invoke('boot');
    }

    private function invoke(string $method): void
    {
        if (!method_exists($this->target, $method)) {
            return;
        }
        $callable = [$this->target, $method];
        if (is_callable($callable)) {
            $callable();
        }
    }
}
