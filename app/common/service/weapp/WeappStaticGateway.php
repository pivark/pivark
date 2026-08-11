<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappStaticGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\static\StaticBuildQueueService;
use app\common\service\static\StaticHtmlDispatch;
use app\common\service\static\StaticHtmlService;

final class WeappStaticGateway
{

    public function __construct(
        private readonly StaticHtmlService $staticHtml,
        private readonly StaticBuildQueueService $staticBuildQueue,
        private readonly StaticHtmlDispatch $staticDispatch,
    ) {
    }

    public function staticHtmlEnabled(): bool
    {
        return $this->staticHtml->enabled();
    }

    public function staticBuildAsyncEnabled(): bool
    {
        return $this->staticBuildQueue->asyncBuildEnabled();
    }

    public function staticBuildSeedFramework(): void
    {
        $this->staticBuildQueue->seedFramework();
    }

    /** @param array<string, mixed> $work */
    public function staticBuildEnqueueWork(array $work, int $priority = 25): void
    {
        $this->staticBuildQueue->enqueueWork($work, $priority);
    }

    public function staticHtmlSyncAfterNavChange(): void
    {
        $this->staticHtml->syncAfterNavChange();
    }

    public function staticHtmlSyncAfterTagChange(int $tagId): void
    {
        $this->staticHtml->syncAfterTagChange($tagId);
    }

    public function staticDispatchAfterArticleChange(int $documentId, string $action): void
    {
        $this->staticDispatch->afterArticleChange($documentId, $action);
    }

    public function staticDispatchAfterProductItemChange(string $slug, string $status = ''): void
    {
        $this->staticDispatch->afterProductItemChange($slug, $status);
    }
}
