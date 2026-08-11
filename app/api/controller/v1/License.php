<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1;

use app\common\support\AppService;
use app\common\enum\ApiErrorCode;
use app\common\service\license\LicenseHmacService;
use app\common\service\license\LicensePublicGateway;
use app\common\support\ApiResponse;
use app\common\support\ServiceResult;
use think\facade\Request;
use think\Response;
use think\response\Json;

class License
{
    private function gateway(): LicensePublicGateway
    {
        /** @var LicensePublicGateway $gw */
        $gw = AppService::make(LicensePublicGateway::class);

        return $gw;
    }

    private function hmac(): LicenseHmacService
    {
        return app(LicenseHmacService::class);
    }

    /** 配置了 HMAC 时校验请求签名 */
    private function rejectIfHmacInvalid(): ?Json
    {
        $hmac = $this->hmac();
        if (!$hmac->isEnabled()) {
            return null;
        }
        $rawBody = (string) Request::getContent();
        $headers = [
            strtolower(LicenseHmacService::HEADER_TIMESTAMP) => (string) Request::header(LicenseHmacService::HEADER_TIMESTAMP),
            strtolower(LicenseHmacService::HEADER_SIGNATURE) => (string) Request::header(LicenseHmacService::HEADER_SIGNATURE),
        ];
        if (!$hmac->verifyFromHeaders($headers, $rawBody)) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '授权请求签名无效');
        }

        return null;
    }

    /**
     * 成功响应：启用 HMAC 时对响应体签名并附头。
     *
     * @param mixed $data
     */
    private function successSigned($data): Response
    {
        $hmac = $this->hmac();
        if (!$hmac->isEnabled()) {
            return ApiResponse::success($data);
        }
        $body = json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return ApiResponse::failCode(ApiErrorCode::UNKNOWN, '响应编码失败');
        }
        [$ts, $sig] = $hmac->signBody($body);

        return Response::create($body, 'html', 200)
            ->contentType('application/json')
            ->header([
                LicenseHmacService::HEADER_TIMESTAMP => $ts,
                LicenseHmacService::HEADER_SIGNATURE => $sig,
            ]);
    }

    private function resultOrFail(ServiceResult $result, string $fallback): Response
    {
        if (!$result->isOk()) {
            return ApiResponse::failCode(ApiErrorCode::UNKNOWN, (string) ($result->message() ?: $fallback));
        }

        return $this->successSigned($result->data());
    }

    /** POST /api/v1/license/activate */
    public function activate(): Response
    {
        $denied = $this->rejectIfHmacInvalid();
        if ($denied !== null) {
            return $denied;
        }

        $payload = Request::post();
        $siteKey = trim((string) ($payload['site_key'] ?? ''));
        $code    = trim((string) ($payload['license_code'] ?? ($payload['code'] ?? '')));
        $siteUrl = trim((string) ($payload['site_url'] ?? ''));
        $version = trim((string) ($payload['version'] ?? ($payload['core_version'] ?? '')));

        $gw = $this->gateway();
        if ($gw->isHostRuntimeActive()) {
            if (!$gw->isValidSiteKey($siteKey)) {
                return ApiResponse::failCode(ApiErrorCode::VALIDATION, '站点 ID 无效');
            }

            $result = $gw->activatePlatform($siteKey, $code, $siteUrl, $version);
        } else {
            $result = $gw->activateCommunity($siteKey, $code);
        }

        return $this->resultOrFail($result, '激活失败');
    }

    /** GET /api/v1/license/status */
    public function status(): Json
    {
        return ApiResponse::success($this->gateway()->status());
    }

    /** POST /api/v1/license/heartbeat */
    public function heartbeat(): Response
    {
        $denied = $this->rejectIfHmacInvalid();
        if ($denied !== null) {
            return $denied;
        }

        $payload = Request::post();
        $siteKey = trim((string) ($payload['site_key'] ?? ''));
        $siteUrl = trim((string) ($payload['site_url'] ?? ''));
        $version = trim((string) ($payload['version'] ?? ($payload['core_version'] ?? '')));
        $plugins = $payload['plugins'] ?? [];
        if (!is_array($plugins)) {
            $plugins = [];
        }
        $telemetry = $payload['telemetry'] ?? [];
        if (!is_array($telemetry)) {
            $telemetry = [];
        }

        $gw = $this->gateway();
        if (!$gw->isValidSiteKey($siteKey)) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '站点 ID 无效');
        }
        if (!$gw->isHostRuntimeActive()) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '当前站点未启用授权平台');
        }

        $result = $gw->heartbeat($siteKey, $siteUrl, $version, $plugins, $telemetry);

        return $this->resultOrFail($result, '心跳失败');
    }

    /** POST /api/v1/license/sync — 客户站拉取官网已购授权 */
    public function sync(): Response
    {
        $denied = $this->rejectIfHmacInvalid();
        if ($denied !== null) {
            return $denied;
        }

        $payload = Request::post();
        $siteKey = trim((string) ($payload['site_key'] ?? ''));
        $siteUrl = trim((string) ($payload['site_url'] ?? ''));
        $version = trim((string) ($payload['version'] ?? ($payload['core_version'] ?? '')));

        $gw = $this->gateway();
        if (!$gw->isValidSiteKey($siteKey)) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '站点 ID 无效');
        }
        if (!$gw->isHostRuntimeActive()) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '当前站点未启用授权平台');
        }

        $result = $gw->sync($siteKey, $siteUrl, $version);

        return $this->resultOrFail($result, '同步失败');
    }
}
