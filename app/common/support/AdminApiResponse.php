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
use think\response\Json;

/**
 * 后台 / 会员 AJAX JSON — 与 /api/v1 同一 REST 契约
 */
final class AdminApiResponse
{
    public static function fromResult(ServiceResult $result, int $httpStatus = 0): Json
    {
        return $result->toJson($httpStatus);
    }

    /**
     * 列表页：Service 返回 list/total/meta，无 legacy code
     *
     * @param array<string, mixed> $page
     */
    public static function list(array $page, int $httpStatus = 200): Json
    {
        $list = $page['list'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }
        $meta = $page;
        unset($meta['list'], $meta['data'], $meta['code'], $meta['msg']);

        return ApiResponse::success($list, $meta === [] ? null : $meta)->code($httpStatus);
    }

    public static function admin(ServiceResult $payload, int $httpStatus = 200): Json
    {
        return self::fromResult($payload, $httpStatus);
    }

    public static function fail(string $msg, int $httpStatus = 422): Json
    {
        return ApiResponse::httpFailCode($httpStatus, ApiErrorCode::VALIDATION, $msg);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function authExpired(string $msg, string $redirect, int $httpStatus = 401, array $extra = []): Json
    {
        $extra['redirect'] = $redirect;

        return ApiResponse::httpFailCode(
            $httpStatus > 0 ? $httpStatus : 401,
            ApiErrorCode::AUTH_REQUIRED,
            $msg,
            null,
            $extra,
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function forbidden(string $msg, int $httpStatus = 403, array $extra = []): Json
    {
        return ApiResponse::httpFailCode(
            $httpStatus > 0 ? $httpStatus : 403,
            ApiErrorCode::PERMISSION_DENIED,
            $msg,
            null,
            $extra,
        );
    }

    /** @deprecated 请用 admin(ServiceResult) */
    public static function legacyPlugin(ServiceResult $payload, int $httpStatus = 200): Json
    {
        return self::fromResult($payload, $httpStatus);
    }

    /**
     * 幂等/缓存回放：直接输出已序列化的 REST JSON 体
     *
     * @param array<string, mixed> $payload
     */
    public static function replayJson(array $payload, int $httpStatus = 200): Json
    {
        return json($payload, $httpStatus > 0 ? $httpStatus : 200);
    }
}
