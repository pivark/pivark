<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\upload\UploadPublicGateway;
use think\response\Json;

final class UploadOssCallbackResponse
{
    /**
     * @param array<string, mixed>|ServiceResult $result UploadDirectCallbackService / gateway 返回值
     */
    public static function aliyun(array|ServiceResult $result): Json
    {
        $payload = app(UploadPublicGateway::class)->ossCallbackResponse($result);

        return json($payload);
    }
}
