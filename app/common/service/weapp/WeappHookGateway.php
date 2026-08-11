<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappHookGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\hook\HookService;

final class WeappHookGateway
{

    public function __construct(
        private readonly HookService $hooks,
    ) {
    }

    /** @param callable(array<string, mixed>): void $listener */
    public function hookOn(string $hook, callable $listener): void
    {
        $this->hooks->on($hook, $listener);
    }

    /** @param array<string, mixed> $context @return mixed */
    public function hookFire(string $name, array $context = []): mixed
    {
        return $this->hooks->fire($name, $context);
    }
}
