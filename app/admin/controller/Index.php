<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\common\support\AdminApiResponse;
use app\common\service\admin\AdminCacheService;
use app\common\service\admin\AdminDashboardService;
use app\common\service\auth\CsrfService;
use app\common\service\config\ConfigService;
use app\common\support\AdminSpa;
use app\common\support\ServiceResult;
use app\common\support\SiteUrl;
use think\Response;

class Index extends Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly AdminCacheService $adminCache,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        $path = strtolower(trim((string) request()->pathinfo(), '/'));
        if ($path === 'index/index' || $path === 'index') {
            return redirect(\app\common\support\SiteUrl::adminSpa());
        }

        return \app\common\support\AdminSpa::respond();
    }

    /** GET — Vue History 路由回退（须置于路由表末尾） */
    public function spaFallback()
    {
        return AdminSpa::respond();
    }

    public function welcome()
    {
        return redirect(SiteUrl::adminSpa());
    }

    public function clearCache(): Response
    {
        return AdminApiResponse::admin($this->adminCache->clearAdminCaches());
    }

    /** POST — 无 import/export 权限时前端上报（REST · 原 Spa::reportDataAccessDenied） */
    public function reportDataAccessDenied(): Response
    {
        if (!\think\facade\Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        /** @var \app\common\service\export\AdminDataAccessAuditService $audit */
        $audit = \app\common\support\AppService::make(\app\common\service\export\AdminDataAccessAuditService::class);
        $audit->logClientDenied(
            trim((string) \think\facade\Request::post('permission', '')),
            trim((string) \think\facade\Request::post('kind', 'export')),
            trim((string) \think\facade\Request::post('path', '')),
            array_filter([
                'ids_count' => max(0, (int) \think\facade\Request::post('ids_count', 0)) ?: null,
                'profile'   => trim((string) \think\facade\Request::post('profile', '')) ?: null,
            ], static fn ($v) => $v !== null && $v !== ''),
        );

        return AdminApiResponse::fromResult(ServiceResult::ok(null, '已记录'));
    }
}
