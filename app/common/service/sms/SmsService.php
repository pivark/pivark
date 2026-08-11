<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\sms;

use app\common\support\AppTime;
use app\common\support\ServiceResult;
use think\facade\Log;

/** 统一短信发送（网关 · 开发写盘 fallback） */
class SmsService
{

    public function __construct(
        private readonly SmsConfigService $configService,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->configService->isReady();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(string $mobile, string $content, array $context = []): ServiceResult
    {
        if ((string) $this->configService->configGet('sms_open', '0') !== '1') {
            return ServiceResult::fail('短信通道未启用');
        }

        $mobile = $this->normalizeMobile($mobile);
        if ($mobile === '') {
            return ServiceResult::fail('手机号无效');
        }

        $content = trim($content);
        if ($content === '') {
            return ServiceResult::fail('短信内容不能为空');
        }

        if ($this->configService->gatewayConfigured()) {
            $gateway = $this->sendViaGateway($mobile, $content, $context);
            if ($gateway->isOk()) {
                return $gateway;
            }
            if ($this->logFallbackEnabled()) {
                return $this->logFallback($mobile, $content, $context, $gateway->message());
            }

            return $gateway;
        }

        if ($this->logFallbackEnabled()) {
            return $this->logFallback($mobile, $content, $context);
        }

        return ServiceResult::fail('短信网关未配置，请填写凭据或开启「未配置时写日志」');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function sendViaGateway(string $mobile, string $content, array $context): ServiceResult
    {
        $provider = trim((string) $this->configService->configGet('sms_provider', SmsConfigService::PROVIDER_NONE));
        Log::info('sms_gateway_pending', [
            'provider' => $provider,
            'mobile'   => $mobile,
            'context'  => $context,
        ]);

        return ServiceResult::fail('运营商网关尚未对接（' . $provider . '），请开启写日志模式或稍后再试');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logFallback(string $mobile, string $content, array $context, string $note = ''): ServiceResult
    {
        $dir = runtime_path() . 'sms';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $safeMobile = preg_replace('/[^0-9+]/', '', $mobile) ?? 'unknown';
        $file       = $dir . DIRECTORY_SEPARATOR . AppTime::format('Ymd_His') . '_' . $safeMobile . '.json';
        $payload    = [
            'mobile'  => $mobile,
            'content' => $content,
            'context' => $context,
            'note'    => $note,
            'sent_at' => AppTime::format('c'),
        ];
        file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

        return ServiceResult::ok(
            ['log_file' => $file],
            '短信已写入开发日志（' . basename($file) . '）',
        );
    }

    private function logFallbackEnabled(): bool
    {
        return (string) $this->configService->configGet('sms_log_fallback', '1') === '1';
    }

    private function normalizeMobile(string $mobile): string
    {
        $mobile = trim($mobile);
        if ($mobile === '') {
            return '';
        }
        $digits = preg_replace('/\s+/', '', $mobile) ?? '';
        if (preg_match('/^1[3-9]\d{9}$/', $digits)) {
            return $digits;
        }
        if (preg_match('/^\+?\d{8,15}$/', $digits)) {
            return $digits;
        }

        return '';
    }
}
