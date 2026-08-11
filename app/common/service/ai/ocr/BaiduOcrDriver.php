<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai\ocr;

use app\common\support\ServiceResult;

use app\common\service\config\AiConfigService;
use app\common\support\LocalFile;

/** 百度通用文字识别（高精度版需按产品线扩展） */
class BaiduOcrDriver implements OcrDriverInterface
{
    public function recognize(string $absolutePath, string $mime): ServiceResult
    {
        $aiConfig = app(AiConfigService::class);
        $key    = $aiConfig->baiduOcrApiKey();
        $secret = $aiConfig->baiduOcrSecretKey();
        if ($key === '' || $secret === '') {
            return ServiceResult::fail('请配置百度 OCR API Key / Secret');
        }
        $token = self::accessToken($key, $secret);
        if ($token === '') {
            return ServiceResult::fail('百度 OCR 获取 access_token 失败');
        }
        $image = base64_encode((string) file_get_contents($absolutePath));
        $url   = 'https://aip.baidubce.com/rest/2.0/ocr/v1/general_basic?access_token=' . urlencode($token);
        $body  = http_build_query(['image' => $image]);
        $ctx   = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 60,
            ],
        ]);
        $raw = LocalFile::getContents($url, false, $ctx);
        if ($raw === null) {
            return ServiceResult::fail('百度 OCR 请求失败');
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || !empty($json['error_code'])) {
            return ServiceResult::fail((string) ($json['error_msg'] ?? '百度 OCR 错误'));
        }
        $lines = [];
        foreach ($json['words_result'] ?? [] as $row) {
            if (is_array($row) && isset($row['words'])) {
                $lines[] = (string) $row['words'];
            }
        }
        $text = trim(implode("\n", $lines));
        if ($text === '') {
            return ServiceResult::fail('未识别到文字');
        }

        return ServiceResult::ok(['text' => $text], 'ok');
    }

    private static function accessToken(string $key, string $secret): string
    {
        $url = 'https://aip.baidubce.com/oauth/2.0/token?' . http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $key,
            'client_secret' => $secret,
        ]);
        $raw = LocalFile::getContents($url);
        if ($raw === null) {
            return '';
        }
        $json = json_decode($raw, true);

        return is_array($json) ? (string) ($json['access_token'] ?? '') : '';
    }
}