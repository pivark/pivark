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
use app\common\service\mail\MailConfigService;
use app\common\support\AdminApiResponse;
use app\common\support\SiteUrl;
use think\facade\Request;

/** 邮件 SMTP 接口配置 */
class MailConfig extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly MailConfigService $mailConfig,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        return redirect(SiteUrl::adminSpa('/system/channels?pane=mail'));
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->mailConfig->saveAdmin(Request::post()));
    }

    public function applyEthereal()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->mailConfig->applyEtherealSandbox(true));
    }

    public function testSend()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin(
            $this->mailConfig->sendTestMail(trim((string) Request::post('to', ''))),
        );
    }
}
