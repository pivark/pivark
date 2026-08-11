<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\exception;

use app\common\enum\ApiErrorCode;
use app\common\support\ServiceResult;
use RuntimeException;

/** 验证码校验异常 */
class CaptchaException extends RuntimeException
{
    public const EMPTY            = 'captcha_empty';
    public const INVALID          = 'captcha_invalid';
    public const EXPIRED          = 'captcha_expired';
    public const TOO_MANY         = 'captcha_too_many';
    public const RATE_LIMITED     = 'captcha_rate_limited';
    public const GD_UNAVAILABLE   = 'captcha_gd_unavailable';
    public const DISABLED_SCENE   = 'captcha_scene_invalid';

    public function __construct(
        string $message,
        private readonly string $errorCode = self::INVALID,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function toJson(): ServiceResult
    {
        $extra = [];
        if ($this->errorCode !== self::INVALID) {
            $extra['error'] = $this->errorCode;
        }

        return ServiceResult::fail($this->getMessage(), ApiErrorCode::CAPTCHA_INVALID, null, $extra === [] ? null : $extra);
    }
}
