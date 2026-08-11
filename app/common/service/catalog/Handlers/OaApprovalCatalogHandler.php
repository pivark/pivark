<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\catalog\Handlers;

use app\common\service\catalog\CatalogQueryService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\support\catalog\CatalogQueryHandlerInterface;
use app\common\support\DbTable;

/**
 * OA 审批流域（预留）
 * 表：oa_approvals(biz_type, status, applicant_id, …)
 */
final class OaApprovalCatalogHandler implements CatalogQueryHandlerInterface
{
    public function domain(): string
    {
        return 'oa_approvals';
    }

    public function isAvailable(): bool
    {
        return app(EntitlementService::class)->can('oa') && self::tableExists();
    }

    public function list(array $params): array
    {
        if (!$this->isAvailable()) {
            return app(CatalogQueryService::class)->emptyList($params);
        }

        return app(CatalogQueryService::class)->emptyList($params);
    }

    public function filterOptions(array $context = []): array
    {
        return [];
    }

    private static function tableExists(): bool
    {
        return DbTable::exists('oa_approvals');
    }
}
