<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\upload;
use app\common\support\ServiceResult;

/**
 * 全端上传 v1 API 可注入门面（Phase 2 DI）。
 */
final class UploadPublicGateway
{

    public function handle(string $scene, mixed $uploadFile, array $options = []): ServiceResult
    {
        return UploadService::scene($scene)->handle($uploadFile, $options);
    }

    public function issueDirectPolicy(string $scene, string $filename = '', int $fileSize = 0): array
    {
        return app(UploadDirectSignService::class)->issue($scene, $filename, $fileSize);
    }

    public function handleOssCallback(array $params, string $rawBody = '', string $authorization = ''): ServiceResult
    {
        return app(UploadDirectCallbackService::class)->handle($params, $rawBody, $authorization);
    }

    /**
     * @param array<string, mixed>|ServiceResult $result handleOssCallback 返回值
     * @return array{Status:string,Message?:string}
     */
    public function ossCallbackResponse(array|ServiceResult $result): array
    {
        if ($result instanceof ServiceResult) {
            return [
                'oss_status' => (string) ($result->dataArray()['oss_status'] ?? ''),
                'msg'        => $result->message(),
            ];
        }
        if (($result['oss_status'] ?? '') === 'Ok') {
            return ['Status' => 'Ok'];
        }

        return [
            'Status'  => 'Error',
            'Message' => (string) ($result['msg'] ?? '回调失败'),
        ];
    }
}
