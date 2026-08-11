<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\upload;
use app\common\support\SimpleHttpClient;

use app\common\service\config\ConfigService;
use think\facade\Log;

/** 本地上传落盘后：可选远程镜像（Webhook），失败不阻断本地保存 */
final class UploadRemoteMirrorService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public const DRIVER_LOCAL   = 'local';
    public const DRIVER_WEBHOOK = 'webhook';

    public function afterLocalStored(string $absolutePath, string $relativePath, string $publicUrl): void
    {
        if (!is_file($absolutePath) || $relativePath === '' || !$this->enabled()) {
            return;
        }
        try {
            if ($this->driver() === self::DRIVER_WEBHOOK) {
                $this->webhookMirror($absolutePath, $relativePath, $publicUrl);
            }
        } catch (\Throwable $e) {
            Log::warning('upload remote mirror: ' . $e->getMessage());
        }
    }

    public function enabled(): bool
    {
        return (string) $this->configService->get('upload_remote_enabled', '0') === '1'
            && $this->driver() !== self::DRIVER_LOCAL;
    }

    public function driver(): string
    {
        $v = strtolower(trim((string) $this->configService->get('upload_remote_driver', self::DRIVER_LOCAL)));
        $allowed = [self::DRIVER_LOCAL, self::DRIVER_WEBHOOK];

        return in_array($v, $allowed, true) ? $v : self::DRIVER_LOCAL;
    }

    private function webhookMirror(string $absolutePath, string $relativePath, string $publicUrl): void
    {
        $url    = trim((string) $this->configService->get('upload_remote_webhook_url', ''));
        $secret = (string) $this->configService->get('upload_remote_webhook_secret', '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('upload_remote_webhook_url 无效');
        }
        $payload = [
            'event'          => 'upload_mirror',
            'relative_path'  => $relativePath,
            'public_url'     => $publicUrl,
            'content_length' => (int) filesize($absolutePath),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Webhook JSON 编码失败');
        }
        $headers = ['Content-Type: application/json'];
        if ($secret !== '') {
            $headers[] = 'Authorization: Bearer ' . $secret;
        }
        $res = SimpleHttpClient::request($url, [
            'method'  => 'POST',
            'body'    => $body,
            'timeout' => 30,
            'headers' => $headers,
        ]);
        if ($res['errno'] !== 0 || $res['http_code'] < 200 || $res['http_code'] >= 300) {
            throw new \RuntimeException('Webhook HTTP ' . $res['http_code'] . ' ' . mb_substr($res['body'], 0, 200));
        }
    }
}
