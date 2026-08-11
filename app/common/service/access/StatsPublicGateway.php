<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\access;

use app\common\support\ServiceResult;

/**
 * 访问统计公开面：仅行为信标。看板读数走后台 AccessStats（需登录）。
 */
final class StatsPublicGateway
{
    /**
     * @param array<string, mixed> $payload
     */
    public function recordBeacon(array $payload): ServiceResult
    {
        return app(AccessStatsService::class)->recordBeacon($payload);
    }
}
