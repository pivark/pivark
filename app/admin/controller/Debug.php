<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

// app/admin/controller/Debug.php — 调试用验证码（仅开发/测试）

namespace app\admin\controller;

use app\common\service\auth\CaptchaService;
use app\common\service\auth\CsrfService;
use app\common\service\site\SiteModeService;
use think\Response;

class Debug extends Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly SiteModeService $siteMode,
        private readonly CaptchaService $captcha,
    ) {
        parent::__construct($csrf);
    }

    public function captcha(): Response
    {
        if (!$this->siteMode->allowsDebugCaptchaPeek()) {
            return Response::create('Not Found', 'html', 404);
        }
        $code = $this->captcha->forScene('admin')->peekCodeForDebug();
        return Response::create($code !== '' ? $code : 'none', 'html', 200);
    }
}