<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\cron;

use app\common\service\infra\RateLimitGateway;
use app\common\support\ServiceResult;

/**
 * 计划任务 Webhook v1 API 可注入门面（Phase 2 DI 收尾）。
 */
final class CronTickPublicGateway
{
    public function webhookToken(): string
    {
        return app(CronService::class)->webhookToken();
    }

    /** @return array<string, mixed> */
    public function runDueJobs(bool $force = false): array
    {
        return app(CronService::class)->runDueJobs($force);
    }

    public function guardWebhookRate(string $ip): ?ServiceResult
    {
        return app(RateLimitGateway::class)->check('cron.tick', $ip);
    }
}
