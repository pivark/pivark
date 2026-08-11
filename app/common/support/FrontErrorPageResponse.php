<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\template\TemplateEngine;
use think\Response;

/** 前台主题 error.php 错误页（控制器与全局异常共用） */
final class FrontErrorPageResponse
{
    public static function create(int $status, string $msg, string $backUrl = ''): Response
    {
        $back       = $backUrl !== '' ? $backUrl : SiteUrl::home();
        $httpStatus = $status >= 400 && $status < 600 ? $status : 404;
        $html       = app(TemplateEngine::class)->render('error', [
            'error_msg'         => $msg,
            'error_desc'        => $httpStatus === 404
                ? '您访问的页面不存在或已被移除'
                : '',
            'error_back'        => $back,
            'error_code'        => (string) $httpStatus,
            'seo_title'         => $httpStatus === 404 ? '页面未找到' : '系统提示',
            'seo_description'   => $msg,
            'seo_title_context' => 'error',
        ]);
        if (str_contains($html, 'template not found')) {
            $html = self::fallbackHtml($msg, $back);
        }

        return self::withSecurityHeaders(Response::create($html, 'html', $httpStatus));
    }

    private static function withSecurityHeaders(Response $response): Response
    {
        return FrontSecurityHeaders::apply($response);
    }

    private static function fallbackHtml(string $msg, string $back): string
    {
        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>提示</title></head><body>'
            . '<p>' . htmlspecialchars($msg) . '</p>'
            . '<p><a href="' . htmlspecialchars($back) . '">返回</a></p></body></html>';
    }
}
