<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;

use app\common\service\enterprise\EnterpriseResourceService;

class DocumentEnterpriseResourceService
{

    public function __construct(
        private readonly EnterpriseResourceService $enterpriseResources,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enterpriseResources->isActive();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recall(string $query, int $entityId = 0, int $limit = 8, string $scope = ''): array
    {
        return $this->enterpriseResources->recall($query, $entityId, $limit, $scope);
    }
}
