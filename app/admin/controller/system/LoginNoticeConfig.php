<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\service\admin\login_notice\AdminLoginNoticeConfigService;
use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\support\SiteUrl;
use think\facade\Request;

/** 登录/事件提醒渠道设置 */
class LoginNoticeConfig extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly AdminLoginNoticeConfigService $loginNoticeConfig,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        return redirect(SiteUrl::adminSpa('/system/channels?pane=notice'));
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin(
            $this->loginNoticeConfig->saveAdmin(Request::post()),
        );
    }
}
