<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappEventGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\event\EventBusService;

final class WeappEventGateway
{

    public function __construct(
        private readonly EventBusService $events,
    ) {
    }

    public function eventDispatch(string $event, array $payload = []): void
    {
        $this->events->dispatch($event, $payload);
    }

    /** @param array<string, mixed>|null $manifest */
    public function eventRegisterManifestSubscribes(string $identifier, ?array $manifest): void
    {
        $this->events->registerManifestSubscribes($identifier, $manifest);
    }
}
