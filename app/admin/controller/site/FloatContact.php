<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\site;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\service\site\FloatContactConfigService;
use app\common\service\site\FloatContactService;
use think\facade\Request;

class FloatContact extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly FloatContactConfigService $floatContactConfig,
        private readonly FloatContactService $floatContact,
    ) {
        parent::__construct($csrf);
    }

    public function configSave()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->floatContactConfig->saveAdmin(Request::post()));
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->floatContact->saveAdmin(Request::post()));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->floatContact->deleteAdmin((int) Request::post('id', 0)));
    }

    public function status()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->floatContact->updateStatusAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('status', 0)
        ));
    }

    public function sort()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->floatContact->updateSortAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('sort', 0)
        ));
    }
}
