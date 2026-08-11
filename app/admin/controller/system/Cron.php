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
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\support\AdminBatchSupport;
use app\common\service\cron\CronService;
use app\common\service\event\DomainEventDispatchAdminService;
use think\facade\Request;

class Cron extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly CronService $cron,
        private readonly DomainEventDispatchAdminService $domainEventDispatch,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            $paged = $this->cron->listAdminPaged(Request::get());

            return AdminApiResponse::list(['total' => $paged['total'],
                'list'  => $paged['list'],
                'page'  => $paged['page'],
                'limit' => $paged['limit']]);;
        }

        return $this->renderView('cron/index', [
            'list'     => $this->cron->listAdmin(),
            'logs'     => $this->cron->listLogsAdmin(),
            'handlers' => $this->cron->handlers(),
        ]);
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->cron->saveAdmin(Request::post()));
    }

    public function status()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->cron->updateStatusAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('status', 0)
        ));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->cron->deleteAdmin((int) Request::post('id', 0)));
    }

    public function run()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->cron->runNowAdmin((int) Request::post('id', 0)));
    }

    /** GET — 领域事件队列面板 */
    public function eventPanel()
    {
        return AdminApiResponse::fromResult(ServiceResult::ok($this->domainEventDispatch->panel()));;
    }

    /** POST — 切换 event_bus_async */
    public function eventAsync()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->domainEventDispatch->setAsyncEnabled((int) Request::post('enabled', 0) === 1));
    }

    /** GET — DLQ 列表 */
    public function eventDlq()
    {
        $page  = max(1, (int) Request::get('page', 1));
        $limit = min(max((int) Request::get('limit', 20), 1), 100);

        return AdminApiResponse::fromResult(ServiceResult::ok($this->domainEventDispatch->listDlqAdmin($page, $limit)));;
    }

    /** POST — 删除 DLQ 行 */
    public function eventDlqDelete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        $ids = AdminBatchSupport::parsePostIds();
        $n = $this->domainEventDispatch->deleteDlqByIds($ids);

        return AdminApiResponse::fromResult(ServiceResult::ok(['deleted' => $n], '已删除 '));;
    }

    /** POST — DLQ 重放回队列 */
    public function eventDlqReplay()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        $ids = AdminBatchSupport::parsePostIds();

        return AdminApiResponse::admin($this->domainEventDispatch->replayDlqByIds($ids));
    }

    /** POST — 立即消费事件队列 */
    public function eventDrainQueue()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        $limit = min(2000, max(1, (int) Request::post('limit', 200)));

        return AdminApiResponse::admin($this->domainEventDispatch->drainQueueForAdmin($limit));
    }
}
