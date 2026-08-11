<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;
use app\common\support\ServiceResult;
use app\common\support\QueryLimit;

/** 后台全文索引管理（DI 入口） */
final class SearchIndexAdminGateway
{

    public function __construct(
        private readonly SearchIndexService $searchIndex,
    ) {
    }

    public function engineStatusForAdmin(): ServiceResult
    {
        return $this->searchIndex->engineStatusForAdmin();
    }

    public function reindexAll(): ServiceResult
    {
        return $this->searchIndex->reindexAll();
    }

    public function drainQueueForAdmin(int $limit = QueryLimit::QUEUE_DRAIN_INDEX): ServiceResult
    {
        return $this->searchIndex->drainQueueForAdmin($limit);
    }

    public function retryFailedQueueForAdmin(): ServiceResult
    {
        return $this->searchIndex->retryFailedQueueForAdmin();
    }
}
