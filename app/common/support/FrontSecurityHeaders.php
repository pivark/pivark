<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use think\Response;

/** 前台 HTML 安全响应头 SSOT */
final class FrontSecurityHeaders
{
    public const CSP_POLICY = "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net data:; connect-src 'self' https:; media-src 'self' https: blob:; worker-src 'self' blob:; frame-src 'self' https:; frame-ancestors 'self'";

    public static function apply(Response $response): Response
    {
        $headers = [
            'Content-Security-Policy' => self::CSP_POLICY,
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Referrer-Policy'           => 'strict-origin-when-cross-origin',
        ];

        if (self::reportOnlyEnabled()) {
            $reportUri = trim((string) config('security.headers.csp_report_uri', ''));
            $reportPolicy = self::CSP_POLICY;
            if ($reportUri !== '') {
                $reportPolicy .= '; report-uri ' . $reportUri;
            }
            $headers['Content-Security-Policy-Report-Only'] = $reportPolicy;
        }

        return $response->header($headers);
    }

    public static function reportOnlyEnabled(): bool
    {
        return (bool) config('security.headers.csp_report_only', true);
    }
}
