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
use app\common\service\export\AdminDataExportSupport;
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\service\audit\AuditLogService;
use app\common\service\audit\LogService;
use app\common\support\AdminBatchSupport;
use think\facade\Request;

class Log extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly LogService $log,
        private readonly AuditLogService $auditLog,
        private readonly AdminDataExportSupport $adminDataExport,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            $result = $this->log->listAdmin(Request::get());
            return AdminApiResponse::list(['total' => $result['total'],
                'list'  => $result['list']]);
        }

        return $this->renderView('log/index', [
            'modules' => $this->log->listModules(),
        ]);
    }

    public function detail()
    {
        $id  = (int) Request::get('id', 0);
        $row = $this->log->findAdmin($id);
        if (!$row) {
            return AdminApiResponse::fail('记录不存在');
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($row));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $ids = AdminBatchSupport::parsePostIds();
        if ($ids === []) {
            return AdminApiResponse::fail('请选择要删除的记录');
        }

        $count = $this->log->deleteByIds($ids);
        if ($count < 1) {
            return AdminApiResponse::fail('未删除任何记录');
        }

        $this->auditLog->operate('删除操作日志', 'admin.log', ['ids' => $ids, 'count' => $count]);

        return AdminApiResponse::fromResult(ServiceResult::ok(['count' => $count], "已删除 {$count} 条记录"));
    }

    public function cleanup()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $days  = max(7, (int) Request::post('days', 90));
        $count = $this->log->deleteOlderThanDays($days);
        $this->auditLog->operate('清理操作日志', 'admin.log', ['days' => $days, 'count' => $count]);

        return AdminApiResponse::fromResult(ServiceResult::ok(['count' => $count], "已删除 {$count} 条 {$days} 天前的记录"));
    }

    public function purgeAll()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $count = $this->log->deleteAll();
        $this->auditLog->operate('清空操作日志', 'admin.log', ['count' => $count]);

        return AdminApiResponse::fromResult(ServiceResult::ok(['count' => $count], "已清空全部 {$count} 条记录"));
    }

    public function export()
    {
        $pack = $this->log->exportAdminCsv(Request::get());

        return $this->adminDataExport->respondPack(
            $pack,
            'text/csv; charset=UTF-8',
            'admin.log.list',
            ['export_scope' => 'filter'],
            '导出 CSV',
            'log.list',
        );
    }
}
