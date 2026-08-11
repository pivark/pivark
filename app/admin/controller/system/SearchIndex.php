<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use app\common\service\search\DocumentSearchTextBackfillService;
use app\common\service\search\SearchIndexAdminGateway;
use think\facade\Request;

/** 全文索引：连通测试、Meili 同步、search_text 全文重建 */
class SearchIndex extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly SearchIndexAdminGateway $searchIndex,
        private readonly DocumentSearchTextBackfillService $searchTextBackfill,
    ) {
        parent::__construct($csrf);
    }

    public function engineStatus()
    {
        return AdminApiResponse::fromResult($this->searchIndex->engineStatusForAdmin());
    }

    /** 将已发布的 search_text 推送到 Meili（不改写插件块） */
    public function reindex()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::fromResult($this->searchIndex->reindexAll());
    }

    public function drainQueue()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $limit = min(2000, max(1, (int) Request::post('limit', 300)));

        return AdminApiResponse::fromResult($this->searchIndex->drainQueueForAdmin($limit));
    }

    public function retryFailedQueue()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::fromResult($this->searchIndex->retryFailedQueueForAdmin());
    }

    public function rebuildSearchTextStatus()
    {
        return AdminApiResponse::fromResult(ServiceResult::ok($this->searchTextBackfill->status()));
    }

    /** 启动或继续分批重建 search_text（含 document-addon） */
    public function rebuildSearchTextBatch()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $start = in_array(strtolower(trim((string) Request::post('start', '0'))), ['1', 'true', 'yes'], true);
        if ($start) {
            $this->searchTextBackfill->scheduleFullRebuild(
                trim((string) Request::post('reason', 'admin_manual')),
            );
        }
        $lastId = max(0, (int) Request::post('last_id', 0));
        $limit  = min(500, max(50, (int) Request::post('limit', 200)));
        $result = $this->searchTextBackfill->processBatch($lastId, $limit, true);
        if (!empty($result->dataArray()['done'])) {
            $syncMeili = in_array(strtolower(trim((string) Request::post('sync_meili', '1'))), ['1', 'true', 'yes'], true);
            if ($syncMeili) {
                $finish = $this->searchTextBackfill->finishWithOptionalReindex(true);
                $payload = $result->dataArray();
                $payload['reindex'] = $finish['reindex'] ?? null;
                $msg = (string) ($finish['msg'] ?? $result->message());

                return AdminApiResponse::admin(ServiceResult::ok($payload, $msg));
            }
            $this->searchTextBackfill->clearSchedule();
        }

        return AdminApiResponse::admin($result);
    }
}
