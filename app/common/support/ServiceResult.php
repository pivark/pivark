<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\contract\PluginHostRuntimeResult;
use app\common\enum\ApiErrorCode;
use think\response\Json;

/**
 * Service 层统一结果（禁止再返回 legacy code/msg 数组）
 */
final class ServiceResult implements \ArrayAccess, PluginHostRuntimeResult
{
    public const KIND_OK = 'ok';

    public const KIND_FAIL = 'fail';

    public const KIND_AUTH = 'auth';

    public const KIND_FORBIDDEN = 'forbidden';

    public const KIND_NOT_FOUND = 'not_found';

    public const KIND_DUPLICATE = 'duplicate';

    /**
     * @param array<string, mixed> $extra redirect / sensitive flags 等
     * @param array<string, mixed>|null $meta
     */
    private function __construct(
        private readonly string $kind,
        private readonly mixed $data = null,
        private readonly string $message = '',
        private readonly ?ApiErrorCode $errorCode = null,
        private readonly ?array $meta = null,
        private readonly array $extra = [],
        private readonly int $httpStatus = 0,
    ) {
    }

    public static function ok(mixed $data = null, string $message = '', ?array $meta = null): self
    {
        return new self(self::KIND_OK, $data, $message, null, $meta);
    }

    public static function fail(
        string $message,
        ApiErrorCode $code = ApiErrorCode::VALIDATION,
        mixed $data = null,
        ?array $meta = null,
    ): self {
        return new self(self::KIND_FAIL, $data, $message, $code, $meta);
    }

    public static function duplicate(mixed $data, string $message = '文件已存在'): self
    {
        $meta = ['duplicate' => true];
        if ($message !== '') {
            $meta['message'] = $message;
        }

        return new self(self::KIND_DUPLICATE, $data, $message, null, $meta);
    }

    public static function rateLimited(string $message = '请求过于频繁，请稍后再试'): self
    {
        return self::fail($message, ApiErrorCode::RATE_LIMITED);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function authRequired(string $message, string $redirect, array $extra = []): self
    {
        $extra['redirect'] = $redirect;

        return new self(self::KIND_AUTH, null, $message, ApiErrorCode::AUTH_REQUIRED, null, $extra, 401);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function forbidden(string $message, array $extra = []): self
    {
        return new self(self::KIND_FORBIDDEN, null, $message, ApiErrorCode::PERMISSION_DENIED, null, $extra, 403);
    }

    public static function notFound(string $message, mixed $data = null): self
    {
        return new self(self::KIND_NOT_FOUND, $data, $message, ApiErrorCode::NOT_FOUND, null, [], 404);
    }

    /**
     * 后台列表页（无 legacy code）
     *
     * @param list<mixed> $list
     * @param array<string, mixed> $meta
     */
    public static function list(array $list, int $total, array $meta = []): self
    {
        $meta['total'] = $total;

        return new self(self::KIND_OK, $list, '', null, $meta);
    }

    public function isOk(): bool
    {
        return $this->kind === self::KIND_OK || $this->kind === self::KIND_DUPLICATE;
    }

    public function failed(): bool
    {
        return !$this->isOk();
    }

    public function message(): string
    {
        return $this->message;
    }

    public function data(): mixed
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function dataArray(): array
    {
        return is_array($this->data) ? $this->data : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function meta(): ?array
    {
        return $this->meta;
    }

    public function errorCode(): ApiErrorCode
    {
        return $this->errorCode ?? ApiErrorCode::UNKNOWN;
    }

    /**
     * @return array<string, mixed>
     */
    public function extra(): array
    {
        return $this->extra;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function withExtra(array $extra): self
    {
        if ($extra === []) {
            return $this;
        }

        return new self(
            $this->kind,
            $this->data,
            $this->message,
            $this->errorCode,
            $this->meta,
            array_merge($this->extra, $extra),
            $this->httpStatus,
        );
    }

    public function httpStatus(): int
    {
        if ($this->httpStatus > 0) {
            return $this->httpStatus;
        }

        if (!$this->isOk()) {
            return $this->errorCode()->httpStatus();
        }

        return 200;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isDuplicate(): bool
    {
        return $this->kind === self::KIND_DUPLICATE;
    }

    public function toJson(int $httpStatus = 0): Json
    {
        $status = $httpStatus > 0 ? $httpStatus : $this->httpStatus();

        return match ($this->kind) {
            self::KIND_AUTH => AdminApiResponse::authExpired(
                $this->message !== '' ? $this->message : '请先登录',
                (string) ($this->extra['redirect'] ?? ''),
                $status > 0 ? $status : 401,
                array_diff_key($this->extra, ['redirect' => true]),
            ),
            self::KIND_FORBIDDEN => AdminApiResponse::forbidden(
                $this->message !== '' ? $this->message : '无权限',
                $status > 0 ? $status : 403,
                $this->extra,
            ),
            self::KIND_NOT_FOUND => ApiResponse::httpFailCode(
                $status > 0 ? $status : 404,
                ApiErrorCode::NOT_FOUND,
                $this->message !== '' ? $this->message : '不存在',
                $this->data,
            ),
            self::KIND_FAIL => ApiResponse::httpFailCode(
                $status > 0 ? $status : $this->errorCode()->httpStatus(),
                $this->errorCode(),
                $this->message !== '' ? $this->message : '操作失败',
                $this->data,
                $this->extra,
            ),
            self::KIND_DUPLICATE => ApiResponse::success($this->data, $this->meta)->code($status > 0 ? $status : 200),
            default => $this->successToJson($status),
        };
    }

    private function successToJson(int $status): Json
    {
        $redirect = trim((string) ($this->extra['redirect'] ?? ''));
        $meta     = $this->buildSuccessMeta();
        $code     = $status > 0 ? $status : 200;
        if ($redirect === '') {
            return ApiResponse::success($this->data, $meta)->code($code);
        }

        return ApiResponse::successWithRootExtra($this->data, $meta, ['redirect' => $redirect])->code($code);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildSuccessMeta(): ?array
    {
        $meta = $this->meta ?? [];
        if ($this->message !== '' && $this->message !== 'ok') {
            $meta['message'] = $this->message;
        }
        foreach ($this->extra as $key => $value) {
            if ($key !== 'redirect') {
                $meta[$key] = $value;
            }
        }

        return $meta === [] ? null : $meta;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->dataArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->dataArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('ServiceResult is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('ServiceResult is immutable');
    }
}
