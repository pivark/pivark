<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\service\auth\CaptchaConfigService;
use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use think\facade\Request;

/** 验证码配置 */
class CaptchaConfig extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly CaptchaConfigService $captchaConfig,
    ) {
        parent::__construct($csrf);
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->captchaConfig->saveAdmin(Request::post()));
    }

    public function index()
    {
        return redirect(\app\common\support\SiteUrl::adminSpa('/system/captcha'));
    }
}
