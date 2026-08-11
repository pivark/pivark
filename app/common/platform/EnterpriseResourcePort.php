<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\platform;

use app\common\service\enterprise\EnterpriseResourceService;
use app\common\support\ServiceResult;

/**
 * Platform Port：插件经 {@see Platform::enterpriseResource()} 读写企业经营资料索引。
 */
final class EnterpriseResourcePort
{

    public function isActive(): bool
    {
        return app(EnterpriseResourceService::class)->isActive();
    }

    /**
     * @param array<string, mixed> $payload upload_id|file_path|path|url · scope · asset_type · entity_id · consumer
     */
    public function attach(array $payload): ServiceResult
    {
        return app(EnterpriseResourceService::class)->attach($payload);
    }

    /**
     * @param array<string, mixed> $options q|query · entity_id · limit · scope
     * @return list<array<string, mixed>>
     */
    public function recall(array $options = []): array
    {
        return app(EnterpriseResourceService::class)->recall(
            (string) ($options['q'] ?? $options['query'] ?? ''),
            (int) ($options['entity_id'] ?? 0),
            max(1, (int) ($options['limit'] ?? 8)),
            (string) ($options['scope'] ?? ''),
        );
    }

    /** @return array{total:int,active:int,expiring:int} */
    public function stats(string $scope = ''): array
    {
        return app(EnterpriseResourceService::class)->stats($scope);
    }
}
