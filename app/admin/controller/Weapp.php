<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\service\user\PermissionService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\WeappContext;
use app\common\service\weapp\WeappAdminGateway;
use app\common\support\AdminSpa;
use think\facade\Request;
use think\facade\Session;
use think\Response;

class Weapp extends Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly WeappContext $weappContext,
        private readonly PermissionService $permission,
        private readonly PluginService $plugin,
        private readonly WeappAdminGateway $weappAdmin,
    ) {
        parent::__construct($csrf);
    }

    /**
     * /admin/weapp/:plugin/:action
     */
    public function dispatch(string $plugin = '', string $action = 'index'): Response
    {
        if (Request::isGet() && !Request::isAjax()) {
            return AdminSpa::respond();
        }

        $plugin = $plugin !== '' ? $plugin : (string) Request::param('plugin', '');
        $action = $action !== '' ? $action : (string) Request::param('action', 'index');
        $plugin = trim(str_replace('\\', '', $plugin), '/');
        $action = preg_replace('/[^a-zA-Z0-9_]/', '', $action) ?: 'index';

        $row = $this->weappContext->resolveRouteKey($plugin);
        if ($row === null) {
            return $this->jsonFail('插件未安装：' . $plugin, 404);
        }

        $identifier = $this->weappContext->identifierFromRow($row);
        $actionKey  = strtolower($action);

        // 授权门：过期/撤销后禁止进入插件后台（含漏写 entitled() 的写接口）
        if (!$this->weappAdmin->pluginAdminEntitled($identifier)) {
            if (Request::isAjax() || Request::isPost()) {
                return AdminApiResponse::fail('插件未授权');
            }

            return Response::create('插件未授权', 'html', 403);
        }

        $adminUser = Session::get('admin_user', []);
        $required  = $this->permission->resolveRequiredPermission($identifier, $actionKey);
        if ($required !== null && !$this->permission->can((int) ($adminUser['id'] ?? 0), $required)) {
            if (Request::isAjax() || Request::isPost()) {
                return AdminApiResponse::fail('无操作权限');
            }

            return Response::create('无操作权限', 'html', 403);
        }

        $adminClass = 'weapp\\' . str_replace('-', '_', $identifier) . '\\admin\\AdminController';
        if (!class_exists($adminClass)) {
            $this->plugin->registerAutoloadPublic($identifier);
        }
        if (!class_exists($adminClass)) {
            return $this->jsonFail('插件后台未就绪：' . $identifier, 501);
        }

        $controller = new $adminClass();
        if (!method_exists($controller, $action)) {
            return $this->jsonFail('动作不存在：' . $action, 404);
        }

        $result = $controller->{$action}();
        if ($result instanceof Response) {
            return $result;
        }

        return AdminApiResponse::admin($result);
    }
}
