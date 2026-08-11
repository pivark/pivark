<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\controller;

use app\common\enum\ApiErrorCode;
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use app\common\service\auth\CaptchaService;
use app\common\service\site\SiteModeService;
use think\Response;

/** 全站统一验证码 HTTP 入口 */
class Captcha
{
    /**
     * GET /captcha/{scene} — 输出 PNG（GD 不可用时运营环境 503）
     */
    public function image(string $scene = 'admin'): Response
    {
        try {
            $svc = app(CaptchaService::class)->forScene($scene);
        } catch (\InvalidArgumentException $e) {
            return Response::create($e->getMessage(), 'html', 404);
        }

        if (!$svc->isEnabled()) {
            return Response::create('验证码已关闭', 'html', 404);
        }

        return $svc->create();
    }

    /**
     * GET /captcha/{scene}/status — 前端判断是否展示验证码框
     */
    public function status(string $scene = 'admin'): Response
    {
        try {
            $svc = app(CaptchaService::class)->forScene($scene);
        } catch (\InvalidArgumentException $e) {
            return AdminApiResponse::admin(ServiceResult::fail($e->getMessage(), ApiErrorCode::NOT_FOUND), 404);
        }

        return AdminApiResponse::admin(ServiceResult::ok([
            'on'    => $svc->isEnabled(),
            'scene' => $svc->getScene(),
            'url'   => $svc->imageUrl(),
        ]));
    }

    /**
     * GET /debug/captcha/{scene} — 开发模式自动化读取当前 Session 验证码（须先 GET /captcha/{scene}）
     */
    public function debugPeek(string $scene = 'home'): Response
    {
        if (!app(SiteModeService::class)->allowsDebugCaptchaPeek()) {
            return Response::create('Not Found', 'html', 404);
        }
        try {
            $svc = app(CaptchaService::class)->forScene($scene);
        } catch (\InvalidArgumentException $e) {
            return Response::create($e->getMessage(), 'html', 404);
        }

        $code = $svc->peekCodeForDebug();

        return Response::create($code !== '' ? $code : 'none', 'html', 200);
    }
}
