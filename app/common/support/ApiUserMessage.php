<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\enum\ApiErrorCode;

/**
 * API 出口用户文案 SSOT：error.message 必须中文友好；机器码/英文留给 error.code 与 debug。
 */
final class ApiUserMessage
{
    /** @var array<string, string> ApiErrorCode::key() => 默认中文提示 */
    private const CODE_ZH = [
        'AUTH_REQUIRED'      => '请先登录',
        'CAPTCHA_INVALID'    => '验证码错误或已过期',
        'CONFLICT'           => '操作冲突，请刷新后重试',
        'CSRF_EXPIRED'       => '表单已过期，请刷新页面后重试',
        'METHOD_NOT_ALLOWED' => '请求方式不正确',
        'NOT_FOUND'          => '请求的资源不存在',
        'PAYMENT_CLOSED'     => '订单已关闭',
        'PAYMENT_FAILED'     => '支付失败',
        'PERMISSION_DENIED'  => '没有操作权限',
        'RATE_LIMITED'       => '操作过于频繁，请稍后再试',
        'UNKNOWN'            => '操作未能完成，请再试一次。若仍失败，请记下刚才的步骤后联系支持',
        'UPLOAD_REJECTED'      => '上传被拒绝',
        'VALIDATION_FAILED'  => '提交的数据有误，请检查后重试',
        'OK'                 => '操作成功',
    ];

    /**
     * @return array{message:string,debug:?string}
     */
    public static function normalize(ApiErrorCode $code, string $raw): array
    {
        $trimmed = trim($raw);
        // 保留中文主句，丢掉 SQLSTATE/堆栈尾巴（安装向导等场景）
        if (
            preg_match('/\p{Han}/u', $trimmed) === 1
            && preg_match('/^(.+?)(?:\s*SQLSTATE\[.+|\s*Stack trace:.+)$/uis', $trimmed, $m) === 1
        ) {
            $lead = trim((string) ($m[1] ?? ''), " \t\n\r\0\x0B：:，,");
            if ($lead !== '' && preg_match('/\p{Han}/u', $lead) === 1) {
                return ['message' => $lead, 'debug' => $trimmed];
            }
        }
        if (self::isUserFacing($trimmed)) {
            return ['message' => $trimmed, 'debug' => null];
        }

        $fallback = self::CODE_ZH[$code->key()] ?? self::CODE_ZH['UNKNOWN'];

        return [
            'message' => $fallback,
            'debug'   => $trimmed !== '' ? $trimmed : null,
        ];
    }

    public static function defaultFor(ApiErrorCode $code): string
    {
        return self::CODE_ZH[$code->key()] ?? self::CODE_ZH['UNKNOWN'];
    }

    private static function isUserFacing(string $message): bool
    {
        if ($message === '') {
            return false;
        }
        if (isset(self::CODE_ZH[$message])) {
            return false;
        }
        if (preg_match('/^[A-Z][A-Z0-9_]+$/', $message) === 1) {
            return false;
        }
        if (preg_match('/^[a-z][a-z0-9_]+$/', $message) === 1) {
            return false;
        }
        if (preg_match('/\.(php|js|ts|mjs)\b/i', $message) === 1) {
            return false;
        }
        if (preg_match('/SQLSTATE|Stack trace|^(Exception|TypeError|Error)\b/i', $message) === 1) {
            return false;
        }
        if (preg_match('/\p{Han}/u', $message) !== 1) {
            return false;
        }

        return true;
    }
}
