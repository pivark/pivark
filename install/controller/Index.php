<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace install\controller;

use app\common\support\AdminApiResponse;
use install\service\InstallService;
use app\common\support\InstallGate;
use app\common\support\ProjectPaths;
use app\common\support\ServiceResult;
use app\common\support\SiteUrl;
use think\facade\Request;
use think\Response;

class Index
{
    public function index(): Response
    {
        if (InstallGate::isInstalled()) {
            return redirect(SiteUrl::adminSpa('/auth/login'));
        }
        app(InstallService::class)->prepareReinstallWizard();
        $installServerSoftware = (string) Request::server('SERVER_SOFTWARE', 'Unknown');
        $view = ProjectPaths::installViewFile('index.php');
        if (!is_readable($view)) {
            return Response::create('安装视图缺失：' . $view, 'html', 500);
        }
        ob_start();
        include $view;

        return Response::create((string) ob_get_clean(), 'html', 200);
    }

    public function check()
    {
        return AdminApiResponse::fromResult(
            ServiceResult::ok(app(InstallService::class)->environmentReport()),
        );
    }

    public function getConfig()
    {
        $type = trim((string) Request::get('type', 'nginx'));
        $domain = (string) (Request::host() ?: 'localhost');
        $config = app(InstallService::class)->generateServerConfig($type, $domain);

        if ($config === '') {
            return AdminApiResponse::fail('不支持的配置类型');
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($config));
    }

    public function resolveExternalLinks()
    {
        return AdminApiResponse::fromResult(
            ServiceResult::ok([
                'links' => app(InstallService::class)->resolveCompletionExternalLinks(),
            ]),
        );
    }

    public function testDb()
    {
        if ($blocked = $this->guardInstallWizardOpen()) {
            return $blocked;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }

        // notice/warning 污染 body → 前端 JSON 解析失败，误报「请求失败」
        $level = ob_get_level();
        ob_start();
        try {
            $result = app(InstallService::class)->testDatabase(Request::post());
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            return AdminApiResponse::fail($e->getMessage() !== '' ? $e->getMessage() : '测试连接异常', 500);
        }
        $dirty = (string) ob_get_clean();
        unset($dirty);

        return AdminApiResponse::admin($result);
    }

    public function run()
    {
        if ($blocked = $this->guardInstallWizardOpen()) {
            return $blocked;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }

        return AdminApiResponse::admin(app(InstallService::class)->finalize(Request::post()));
    }

    public function runStep()
    {
        if ($blocked = $this->guardInstallWizardOpen()) {
            return $blocked;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误', 405);
        }

        $step = trim((string) Request::post('step', ''));
        // 装步偶发 notice/warning 会污染 body → 前端 JSON.parse 失败误报「HTTP 200」
        $level = ob_get_level();
        ob_start();
        try {
            $result = app(InstallService::class)->runInstallStep(Request::post(), $step);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            return AdminApiResponse::fail($e->getMessage() !== '' ? $e->getMessage() : '安装步骤异常', 500);
        }
        $dirty = (string) ob_get_clean();
        unset($dirty);

        return AdminApiResponse::admin($result);
    }

    private function guardInstallWizardOpen(): ?Response
    {
        if (InstallGate::isInstalled()) {
            return AdminApiResponse::forbidden(
                '系统已安装；重装请删除 data/install.lock 后访问 /install',
                403,
            );
        }

        return null;
    }
}
