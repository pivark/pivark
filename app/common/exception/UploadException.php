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

// app/common/exception/UploadException.php — 上传校验异常

class UploadException extends RuntimeException
{
    public const EMPTY        = 'upload_empty';
    public const INVALID_EXT  = 'upload_invalid_ext';
    public const INVALID_MIME = 'upload_invalid_mime';
    public const TOO_LARGE    = 'upload_too_large';
    public const NOT_WRITABLE = 'upload_not_writable';
    public const SAVE_FAILED  = 'upload_save_failed';
    public const INVALID_SCENE = 'upload_invalid_scene';

    public const SECURITY_BLOCK = 'upload_security_block';

    public const SECURITY_WARN = 'upload_security_warn';

    /**
     * @param list<array{code?:string,severity?:string,path?:string,line?:?int,message?:string}> $findings
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = self::SAVE_FAILED,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly array $findings = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return list<array{code?:string,severity?:string,path?:string,line?:?int,message?:string}>
     */
    public function getFindings(): array
    {
        return $this->findings;
    }

    public function toJson(): ServiceResult
    {
        $extra = ['err_code' => $this->errorCode];
        if ($this->findings !== []) {
            $extra['security_findings'] = $this->findings;
            $extra['security_level'] = $this->errorCode === self::SECURITY_WARN ? 'warn' : 'block';
        }

        return ServiceResult::fail(
            $this->getMessage(),
            ApiErrorCode::UPLOAD_REJECTED,
            null,
            $extra,
        );
    }
}
