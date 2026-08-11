<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1;

use app\common\enum\ApiErrorCode;
use app\common\service\upload\UploadPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use app\common\support\UploadOssCallbackResponse;
use app\common\support\UploadGate;
use app\common\support\UploadGateException;
use think\facade\Request;
use think\response\Json;

class Upload
{
    private function gateway(): UploadPublicGateway
    {
        /** @var UploadPublicGateway $gw */
        $gw = AppService::make(UploadPublicGateway::class);

        return $gw;
    }

    /**
     * GET /api/v1/upload/policy?scene=
     * 直传策略占位（默认 local；upload_direct_enabled=1 时返回 direct_stub 签名草案）
     */
    public function policy(): Json
    {
        $scene = (string) Request::param('scene', '');
        if ($scene === '') {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '缺少参数 scene');
        }

        try {
            UploadGate::assertUpload($scene);
        } catch (UploadGateException $e) {
            return UploadGate::failJson($e);
        }

        $filename = (string) Request::param('filename', '');
        $fileSize = max(0, (int) Request::param('file_size', 0));

        return ApiResponse::success(
            $this->gateway()->issueDirectPolicy($scene, $filename, $fileSize),
        );
    }

    /**
     * POST /api/v1/upload
     * multipart: file, scene（必填，见 config/upload.php）
     */
    public function store(): Json
    {
        if (!Request::isPost()) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '请使用 POST');
        }

        $scene = (string) Request::param('scene', '');
        if ($scene === '') {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '缺少参数 scene');
        }

        try {
            UploadGate::assertUpload($scene);
        } catch (UploadGateException $e) {
            return UploadGate::failJson($e);
        }

        $uploadFile = Request::file('file');
        if ($uploadFile === null) {
            return ApiResponse::failCode(ApiErrorCode::VALIDATION, '请选择要上传的文件');
        }

        try {
            $result = $this->gateway()->handle($scene, $uploadFile, [
                'content_hash' => (string) Request::post('content_hash', ''),
                'file_size'    => (int) Request::post('file_size', 0),
                'force_upload' => in_array((string) Request::post('force_upload', ''), ['1', 'true'], true),
            ]);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::failCode(ApiErrorCode::UPLOAD_REJECTED, $e->getMessage());
        }

        if (!$result->isOk()) {
            return ApiResponse::failCode(ApiErrorCode::UPLOAD_REJECTED, (string) ($result->message() ?: '上传失败'));
        }

        return ApiResponse::success(
            $result->dataArray() ?: null,
            [
                'message' => (string) ($result->message() ?: ($result->isDuplicate() ? '文件已存在' : '上传成功')),
                'duplicate' => $result->isDuplicate(),
            ],
        );
    }

    /**
     * POST /api/v1/upload/oss-callback
     * OSS 直传回调（验签写 media_assets；Aliyun 期望 JSON {"Status":"Ok"}）
     */
    public function ossCallback(): Json
    {
        $contentStr = Request::getContent();

        $params = Request::post();
        if (!is_array($params)) {
            $params = [];
        }
        if ($params === [] && trim($contentStr) !== '') {
            $decoded = json_decode($contentStr, true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }

        $authHeader = Request::header('Authorization', '');
        $auth = is_array($authHeader) ? '' : (string) $authHeader;

        $result = $this->gateway()->handleOssCallback(
            $params,
            $contentStr,
            $auth,
        );

        return UploadOssCallbackResponse::aliyun($result);
    }
}
