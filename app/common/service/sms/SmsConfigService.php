<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\sms;

use app\common\service\config\ConfigSecretService;
use app\common\service\config\ConfigService;
use app\common\support\ServiceResult;

/** 后台 · 短信网关配置（凭据层 · 发送走 SmsService） */
class SmsConfigService
{
    private const SECRET_KEY = 'sms_secret_key';

    public const PROVIDER_NONE = 'none';
    public const PROVIDER_ALIYUN = 'aliyun';
    public const PROVIDER_TENCENT = 'tencent';

    /** @return list<string> */
    public function keys(): array
    {
        return [
            'sms_open',
            'sms_provider',
            'sms_access_key',
            self::SECRET_KEY,
            'sms_sign_name',
            'sms_template_code',
            'sms_log_fallback',
        ];
    }

    /** @return array<string, string> */
    public function allForAdmin(): array
    {
        $this->ensureSecretMigrated();
        $out = [];
        foreach ($this->keys() as $key) {
            if ($key === self::SECRET_KEY) {
                $out[$key] = '';
                $out['sms_secret_key_set'] = $this->secretConfigured(self::SECRET_KEY) ? '1' : '0';
                continue;
            }
            $out[$key] = (string) $this->configService->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function metaForAdmin(): array
    {
        return [
            'cfg'              => $this->allForAdmin(),
            'ready'            => $this->isReady(),
            'gateway_ready'    => $this->gatewayConfigured(),
            'log_fallback'     => $this->logFallbackEnabled(),
            'log_dir_hint'     => 'data/runtime/sms/',
            'providers'        => [
                ['id' => self::PROVIDER_NONE, 'label' => '未启用（可配合写日志）'],
                ['id' => self::PROVIDER_ALIYUN, 'label' => '阿里云短信'],
                ['id' => self::PROVIDER_TENCENT, 'label' => '腾讯云短信'],
            ],
        ];
    }

    public function configGet(string $key, string $default = ''): string
    {
        return (string) $this->configService->get($key, $default);
    }

    /** 提醒设置可用：已开启 +（网关齐套 或 写日志 fallback） */
    public function isReady(): bool
    {
        if ($this->configGet('sms_open', '0') !== '1') {
            return false;
        }

        return $this->gatewayConfigured() || $this->logFallbackEnabled();
    }

    /** 运营商凭据是否齐套（对接后可真发） */
    public function gatewayConfigured(): bool
    {
        $provider = trim($this->configGet('sms_provider', self::PROVIDER_NONE));
        if ($provider === '' || $provider === self::PROVIDER_NONE) {
            return false;
        }

        return trim($this->configGet('sms_access_key', '')) !== ''
            && $this->secretConfigured('sms_secret_key')
            && trim($this->configGet('sms_sign_name', '')) !== '';
    }

    public function sendTestSms(string $mobile, string $content = ''): ServiceResult
    {
        $site = trim((string) $this->configService->get('site_name', 'PivArk'));
        if ($content === '') {
            $content = sprintf('【%s】短信接口测试 %s', $site, date('Y-m-d H:i:s'));
        }

        return $this->sms()->send($mobile, $content, ['scene' => 'admin_test']);
    }

    private function sms(): SmsService
    {
        return app(SmsService::class);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $this->ensureSecretMigrated();
        $existing = $this->allForAdmin();
        $provider = trim((string) ($data['sms_provider'] ?? $existing['sms_provider'] ?? self::PROVIDER_NONE));
        if (!in_array($provider, [self::PROVIDER_NONE, self::PROVIDER_ALIYUN, self::PROVIDER_TENCENT], true)) {
            $provider = self::PROVIDER_NONE;
        }

        $payload = [
            'sms_open'            => !empty($data['sms_open']) ? '1' : '0',
            'sms_provider'        => $provider,
            'sms_access_key'      => trim((string) ($data['sms_access_key'] ?? $existing['sms_access_key'] ?? '')),
            'sms_sign_name'       => trim((string) ($data['sms_sign_name'] ?? $existing['sms_sign_name'] ?? '')),
            'sms_template_code'   => trim((string) ($data['sms_template_code'] ?? $existing['sms_template_code'] ?? '')),
            'sms_log_fallback'    => !empty($data['sms_log_fallback']) ? '1' : '0',
        ];

        $secret = trim((string) ($data[self::SECRET_KEY] ?? ''));
        if ($secret !== '') {
            $payload[self::SECRET_KEY] = $secret;
        } elseif ($this->secretConfigured(self::SECRET_KEY)) {
            $payload[self::SECRET_KEY] = (string) $this->configService->get(self::SECRET_KEY, '');
        }

        foreach ($payload as $key => $value) {
            $this->configService->set($key, $value);
        }
        $this->configService->forgetRequestCache();

        return ServiceResult::ok(null, '短信接口配置已保存');
    }

    private function logFallbackEnabled(): bool
    {
        return $this->configGet('sms_log_fallback', '1') === '1';
    }

    private function secretConfigured(string $key): bool
    {
        return trim((string) $this->configService->get($key, '')) !== '';
    }

    /** 旧站 configs 明文 → config_secrets（幂等） */
    private function ensureSecretMigrated(): void
    {
        try {
            app(ConfigSecretService::class)->migrateKeyFromConfigs(self::SECRET_KEY);
        } catch (\Throwable) {
            // 未安装/无表时跳过
        }
    }

    private function defaultFor(string $key): string
    {
        return match ($key) {
            'sms_open'          => '0',
            'sms_provider'      => self::PROVIDER_NONE,
            'sms_log_fallback'  => '1',
            default             => '',
        };
    }

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }
}
