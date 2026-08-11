<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — API 业务错误码（多语言 / 前端分支 SSOT）
 *
 * 约定：/api/v1 与 admin/member AJAX 均用 HTTP 状态 + body.error.code（字符串）；Service 层用 ServiceResult。
 */
declare(strict_types=1);

namespace app\common\enum;

enum ApiErrorCode: int
{
    case OK                = 0;
    case UNKNOWN           = 1;
    case VALIDATION        = 1001;
    case NOT_FOUND         = 1002;
    case PERMISSION_DENIED = 1003;
    case RATE_LIMITED      = 1004;
    case AUTH_REQUIRED     = 1005;
    case CAPTCHA_INVALID   = 1006;
    case UPLOAD_REJECTED   = 1007;
    case PAYMENT_FAILED    = 2001;
    case PAYMENT_CLOSED    = 2002;
    case METHOD_NOT_ALLOWED = 1008;
    case CSRF_EXPIRED       = 1009;
    case CONFLICT           = 1010;
    /** 站点核心档位不足（专业版+） */
    case CORE_LICENSE_PRO_REQUIRED = 1011;

    /** 机器可读标识（前端 i18n key） */
    public function key(): string
    {
        return match ($this) {
            self::OK                => 'OK',
            self::UNKNOWN           => 'UNKNOWN',
            self::VALIDATION        => 'VALIDATION_FAILED',
            self::NOT_FOUND         => 'NOT_FOUND',
            self::PERMISSION_DENIED => 'PERMISSION_DENIED',
            self::RATE_LIMITED      => 'RATE_LIMITED',
            self::AUTH_REQUIRED     => 'AUTH_REQUIRED',
            self::CAPTCHA_INVALID   => 'CAPTCHA_INVALID',
            self::UPLOAD_REJECTED   => 'UPLOAD_REJECTED',
            self::PAYMENT_FAILED    => 'PAYMENT_FAILED',
            self::PAYMENT_CLOSED    => 'PAYMENT_CLOSED',
            self::METHOD_NOT_ALLOWED => 'METHOD_NOT_ALLOWED',
            self::CSRF_EXPIRED       => 'CSRF_EXPIRED',
            self::CONFLICT           => 'CONFLICT',
            self::CORE_LICENSE_PRO_REQUIRED => 'CORE_LICENSE_PRO_REQUIRED',
        };
    }

    public static function tryFromInt(int $value): ?self
    {
        return self::tryFrom($value);
    }

    /** REST /api/v1 默认 HTTP 状态（行业惯例） */
    public function httpStatus(): int
    {
        return match ($this) {
            self::OK                 => 200,
            self::UNKNOWN            => 500,
            self::VALIDATION         => 422,
            self::NOT_FOUND          => 404,
            self::PERMISSION_DENIED  => 403,
            self::RATE_LIMITED       => 429,
            self::AUTH_REQUIRED      => 401,
            self::CAPTCHA_INVALID    => 400,
            self::UPLOAD_REJECTED    => 422,
            self::PAYMENT_FAILED     => 402,
            self::PAYMENT_CLOSED     => 409,
            self::METHOD_NOT_ALLOWED => 405,
            self::CSRF_EXPIRED       => 403,
            self::CONFLICT           => 409,
            self::CORE_LICENSE_PRO_REQUIRED => 403,
        };
    }
}
