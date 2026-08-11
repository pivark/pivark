<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);


namespace app\common\support;

use app\common\enum\ApiErrorCode;
use think\response\Json;

/**
 * 前台 /api/v1 JSON 响应（REST 惯例：HTTP 表语义，body 用 data / error）
 */
class ApiResponse
{
    /**
     * @param mixed $data
     * @param array<string, mixed>|null $meta
     */
    public static function success($data = null, ?array $meta = null): Json
    {
        $payload = ['data' => $data];
        if ($meta !== null && $meta !== []) {
            $payload['meta'] = $meta;
        }

        return json($payload, 200);
    }

    /**
     * @param array<string, mixed> $rootExtra 合并到 JSON 根（如 redirect）
     * @param array<string, mixed>|null $meta
     * @param mixed $data
     */
    public static function successWithRootExtra(
        $data = null,
        ?array $meta = null,
        array $rootExtra = [],
    ): Json {
        $payload = ['data' => $data];
        if ($meta !== null && $meta !== []) {
            $payload['meta'] = $meta;
        }
        if ($rootExtra !== []) {
            $payload = array_merge($payload, $rootExtra);
        }

        return json($payload, 200);
    }

    /** @param mixed $data */
    public static function fail(string $msg, $data = null): Json
    {
        return self::error(ApiErrorCode::UNKNOWN, $msg, $data);
    }

    /** @param mixed $data */
    public static function failCode(ApiErrorCode|int $errCode, string $msg, $data = null): Json
    {
        $err = $errCode instanceof ApiErrorCode
            ? $errCode
            : (ApiErrorCode::tryFromInt((int) $errCode) ?? ApiErrorCode::UNKNOWN);

        return self::error($err, $msg, $data);
    }

    /**
     * @param array<string, mixed> $extra 合并到 JSON 根（如 meta.auth_required）
     * @param mixed $data
     */
    public static function httpFail(int $httpStatus, string $msg, $data = null, array $extra = []): Json
    {
        return self::error(ApiErrorCode::UNKNOWN, $msg, $data, $extra, $httpStatus);
    }

    /**
     * @param array<string, mixed> $extra
     * @param mixed $data
     */
    public static function httpFailCode(
        int $httpStatus,
        ApiErrorCode $errCode,
        string $msg,
        $data = null,
        array $extra = [],
    ): Json {
        return self::error($errCode, $msg, $data, $extra, $httpStatus);
    }

    /**
     * @param array<string, mixed> $extra
     * @param mixed $data
     */
    public static function error(
        ApiErrorCode $errCode,
        string $message,
        $data = null,
        array $extra = [],
        ?int $httpStatus = null,
    ): Json {
        $status   = $httpStatus ?? $errCode->httpStatus();
        $resolved = ApiUserMessage::normalize($errCode, $message);
        $error    = [
            'code'    => $errCode->key(),
            'message' => $resolved['message'],
        ];
        if ($resolved['debug'] !== null && self::shouldExposeDebugDetail()) {
            $error['debug'] = ['detail' => $resolved['debug']];
        }
        $payload = ['error' => $error];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        if ($extra !== []) {
            $payload = array_merge($payload, $extra);
        }

        return json($payload, $status);
    }

    private static function shouldExposeDebugDetail(): bool
    {
        try {
            return (bool) app()->isDebug();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<mixed> $list
     * @param array<string, mixed> $extraMeta
     */
    public static function paginate(array $list, int $total, int $page, int $limit, array $extraMeta = []): Json
    {
        $meta = array_merge([
            'page'  => $page,
            'limit' => $limit,
        ], $extraMeta);
        if ($total >= 0) {
            $meta['total'] = $total;
        }

        return self::success($list, $meta);
    }

    public static function fromServiceResult(ServiceResult $result, int $httpStatus = 200): Json
    {
        return $result->toJson($httpStatus);
    }

    /** @deprecated 请用 fromServiceResult(ServiceResult) */
    public static function plugin(ServiceResult $payload, int $httpStatus = 200): Json
    {
        return self::fromServiceResult($payload, $httpStatus);
    }

    public static function methodNotAllowed(string $msg = '请使用 POST'): Json
    {
        return self::error(ApiErrorCode::METHOD_NOT_ALLOWED, $msg);
    }

    public static function csrfExpired(string $msg = '表单已过期，请刷新页面后重试'): Json
    {
        return self::error(ApiErrorCode::CSRF_EXPIRED, $msg);
    }

    public static function authRequired(string $msg = '请先登录'): Json
    {
        return self::error(ApiErrorCode::AUTH_REQUIRED, $msg);
    }
}
