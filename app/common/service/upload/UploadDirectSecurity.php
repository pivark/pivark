<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\upload;

use app\common\service\config\ConfigService;

/** 直传签名/回调安全门禁（禁止生产默认 stub 密钥） */
final class UploadDirectSecurity
{

    public const DEFAULT_SIGN_SECRET       = 'pivark-upload-stub';
    public const DEFAULT_OSS_ACCESS_KEY    = 'OSS_STUB_ACCESS_KEY';
    public const DEFAULT_OSS_SECRET_KEY    = 'OSS_STUB_SECRET_KEY';
    public const DEFAULT_COS_SECRET_ID     = 'COS_STUB_SECRET_ID';
    public const DEFAULT_COS_SECRET_KEY    = 'COS_STUB_SECRET_KEY';

    public function signSecret(): string
    {
        return trim((string) app(ConfigService::class)->get('upload_direct_sign_secret', self::DEFAULT_SIGN_SECRET));
    }

    public function allowsDevDefaults(): bool
    {
        $env = strtolower(trim((string) env('PIVARK_ENV', 'dev')));

        return in_array($env, ['dev', 'demo-local', 'local', 'test'], true);
    }

    public function usesDefaultSignSecret(): bool
    {
        $secret = $this->signSecret();

        return $secret === '' || hash_equals(self::DEFAULT_SIGN_SECRET, $secret);
    }

    public function assertDirectUploadReady(): void
    {
        if (!app(UploadDirectSignService::class)->enabled()) {
            return;
        }
        if ($this->allowsDevDefaults()) {
            return;
        }
        if ($this->usesDefaultSignSecret()) {
            throw new \RuntimeException('直传已启用但未配置 upload_direct_sign_secret（不可使用默认密钥）');
        }
        $provider = app(UploadDirectSignService::class)->provider();
        if (in_array($provider, ['oss_stub', 'cos_stub'], true)) {
            throw new \RuntimeException('生产环境不可使用 oss_stub/cos_stub 直传 Provider');
        }
    }

    public function callbackAllowed(): bool
    {
        if (!app(UploadDirectSignService::class)->enabled()) {
            return false;
        }
        if (in_array(app(UploadDirectSignService::class)->provider(), ['oss_stub', 'cos_stub'], true)) {
            return $this->allowsDevDefaults();
        }
        if ($this->usesDefaultSignSecret() && !$this->allowsDevDefaults()) {
            return false;
        }

        return true;
    }
}
