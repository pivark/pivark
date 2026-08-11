<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\admin\controller;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use think\facade\View;
use think\facade\Session;
use think\Response;

class Base
{
    use RendersView;

    public function __construct(CsrfService $csrf)
    {
        $adminUser = Session::get('admin_user', []);
        View::assign('admin_user', $adminUser);
        View::assign('csrf_token', $csrf->token());
        View::assign('csrf_field', $csrf->fieldName());
    }

    protected function jsonFail(string $msg, int $httpCode = 200): Response
    {
        return AdminApiResponse::fail($msg, $httpCode);
    }
}