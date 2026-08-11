<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\static;

use app\common\support\ServiceResult;

use app\common\service\config\ConfigService;
use app\common\service\seo\SeoStaticConfigService;
use app\common\support\ProjectPaths;
use app\common\service\static\StaticHtmlService;
use app\common\service\static\StaticRemotePublishConfigService;
use app\common\support\QueryLimit;
use think\facade\Log;

/** 静态 HTML 写入后：可选 OSS 同步 + CDN 刷新（失败不阻断本地落盘） */
final class StaticRemotePublishService
{

    public function __construct(
        private readonly StaticRemotePublishConfigService $staticRemotePublishConfigService,
    ) {
    }

    public function afterHtmlWritten(string $absolutePath, string $relativePath, string $publicUrl): void
    {
        if (!is_file($absolutePath) || $relativePath === '') {
            return;
        }
        try {
            if ($this->staticRemotePublishConfigService->ossEnabled()) {
                $this->pushOss($absolutePath, $relativePath, $publicUrl);
            }
            if ($this->staticRemotePublishConfigService->cdnEnabled()) {
                $this->purgeCdn($relativePath, $publicUrl);
                if ($this->staticRemotePublishConfigService->cdnWarmupEnabled()) {
                    $this->warmupCdn($relativePath, $publicUrl);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('static remote publish: ' . $e->getMessage());
        }
    }

    private function pushOss(string $absolutePath, string $relativePath, string $publicUrl): void
    {
        $driver = $this->staticRemotePublishConfigService->ossDriver();
        match ($driver) {
            StaticRemotePublishConfigService::OSS_MIRROR  => $this->ossMirror($absolutePath, $relativePath),
            StaticRemotePublishConfigService::OSS_WEBHOOK => $this->httpWebhook(
                (string) app(ConfigService::class)->get('static_oss_webhook_url', ''),
                (string) app(ConfigService::class)->get('static_oss_webhook_secret', ''),
                [
                    'event'          => 'static_html_upload',
                    'relative_path'  => $relativePath,
                    'public_url'     => $publicUrl,
                    'content_type'   => 'text/html; charset=utf-8',
                    'content_length' => (int) filesize($absolutePath),
                ],
            ),
            StaticRemotePublishConfigService::OSS_S3      => $this->ossS3Put($absolutePath, $relativePath),
            default                                       => null,
        };
    }

    private function ossMirror(string $absolutePath, string $relativePath): void
    {
        $base = trim((string) app(ConfigService::class)->get('static_oss_mirror_dir', 'data/static_mirror'), '/\\');
        if ($base === '') {
            return;
        }
        $root = dirname(__DIR__, 3);
        $dest = $root . '/' . $base . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
        $dir  = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建镜像目录');
        }
        if (!copy($absolutePath, $dest)) {
            throw new \RuntimeException('镜像复制失败');
        }
    }

    private function ossS3Put(string $absolutePath, string $relativePath): void
    {
        $endpoint = trim((string) app(ConfigService::class)->get('static_oss_endpoint', ''));
        $bucket   = trim((string) app(ConfigService::class)->get('static_oss_bucket', ''));
        $keyId    = trim((string) app(ConfigService::class)->get('static_oss_access_key', ''));
        $secret   = trim((string) app(ConfigService::class)->get('static_oss_secret_key', ''));
        $prefix   = trim((string) app(ConfigService::class)->get('static_oss_prefix', ''), '/');
        if ($endpoint === '' || $bucket === '' || $keyId === '' || $secret === '') {
            throw new \RuntimeException('OSS S3 兼容：endpoint/bucket/密钥未配置');
        }
        $objectKey = ($prefix !== '' ? $prefix . '/' : '') . ltrim($relativePath, '/');
        $body      = (string) file_get_contents($absolutePath);
        $date      = gmdate('D, d M Y H:i:s \G\M\T');
        $ctype     = 'text/html; charset=utf-8';
        $resource  = '/' . $bucket . '/' . $objectKey;
        $stringToSign = "PUT\n\n{$ctype}\n{$date}\n{$resource}";
        $signature    = base64_encode(hash_hmac('sha1', $stringToSign, $secret, true));
        $host         = $bucket . '.' . preg_replace('#^https?://#i', '', $endpoint);
        $url = 'https://' . $host . '/' . implode('/', array_map('rawurlencode', explode('/', $objectKey)));

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl 初始化失败');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Date: ' . $date,
                'Content-Type: ' . $ctype,
                'Authorization: OSS ' . $keyId . ':' . $signature,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('OSS PUT HTTP ' . $code . ' ' . (string) $resp);
        }
    }

    private function purgeCdn(string $relativePath, string $publicUrl): void
    {
        $cdnUrl = $this->cdnPublicUrl($relativePath, $publicUrl);
        $this->httpWebhook(
            (string) app(ConfigService::class)->get('static_cdn_webhook_url', ''),
            (string) app(ConfigService::class)->get('static_cdn_webhook_secret', ''),
            [
                'event'         => 'cdn_purge',
                'urls'          => [$cdnUrl],
                'relative_path' => $relativePath,
                'public_url'    => $publicUrl,
            ],
        );
    }

    private function warmupCdn(string $relativePath, string $publicUrl): void
    {
        $this->warmupUrls([$this->cdnPublicUrl($relativePath, $publicUrl)]);
    }

    /**
     * @param list<string> $urls 完整 CDN URL
     * @return ServiceResult
     */
    public function warmupUrls(array $urls): ServiceResult
    {
        if (!$this->staticRemotePublishConfigService->cdnWarmupEnabled()) {
            return ServiceResult::fail('CDN 预热未启用');
        }
        $urls = array_values(array_unique(array_filter(array_map(
            static fn ($u): string => trim((string) $u),
            $urls,
        ), static fn (string $u): bool => $u !== '' && preg_match('#^https?://#i', $u) === 1)));
        if ($urls === []) {
            return ServiceResult::fail('无有效 URL');
        }
        $hook = trim((string) app(ConfigService::class)->get('static_cdn_warmup_webhook_url', ''));
        if ($hook === '') {
            $hook = trim((string) app(ConfigService::class)->get('static_cdn_webhook_url', ''));
        }
        try {
            $this->httpWebhook(
                $hook,
                (string) app(ConfigService::class)->get('static_cdn_warmup_webhook_secret', ''),
                [
                    'event' => 'cdn_warmup',
                    'urls'  => $urls,
                ],
                max(5, (int) app(ConfigService::class)->get('static_cdn_warmup_timeout', 15)),
            );

            return ServiceResult::ok(['warmed' => count($urls), 'failed' => 0], 'ok');
        } catch (\Throwable $e) {
            return ServiceResult::fail($e->getMessage());
        }
    }

    public function cdnUrlForRelative(string $relativePath, string $publicUrl): string
    {
        return $this->cdnPublicUrl($relativePath, $publicUrl);
    }

    /**
     * 扫描静态 HTML 并生成 CDN 预热 URL 列表（供 CLI / 运维）。
     *
     * @return list<string>
     */
    public function collectHtmlWarmupUrls(int $limit = QueryLimit::SITEMAP_BATCH): array
    {
        $limit = max(1, min(5000, $limit));
        $publicRoot = ProjectPaths::publicDir();
        $sub        = app(SeoStaticConfigService::class)->subdir();
        $scanRoot   = $sub !== '' ? $publicRoot . '/' . $sub : $publicRoot;
        if (!is_dir($scanRoot)) {
            return [];
        }
        $it    = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scanRoot, \FilesystemIterator::SKIP_DOTS),
        );
        $urls  = [];
        $count = 0;
        foreach ($it as $fileInfo) {
            if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'html') {
                continue;
            }
            $abs = $fileInfo->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($publicRoot))), '/');
            $publicUrl = app(StaticHtmlService::class)->publicUrlForRelative($rel);
            $urls[] = $this->cdnUrlForRelative($rel, $publicUrl);
            $count++;
            if ($count >= $limit) {
                break;
            }
        }

        return $urls;
    }

    private function cdnPublicUrl(string $relativePath, string $publicUrl): string
    {
        $base = rtrim((string) app(ConfigService::class)->get('static_cdn_public_base', ''), '/');
        if ($base === '') {
            return $publicUrl;
        }
        $path = '/' . ltrim(str_replace('\\', '/', $relativePath), '/');

        return $base . $path;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function httpWebhook(string $url, string $secret, array $payload, int $timeout = 30): void
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Webhook URL 无效');
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Webhook JSON 编码失败');
        }
        $headers = ['Content-Type: application/json'];
        if ($secret !== '') {
            $headers[] = 'Authorization: Bearer ' . $secret;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl 初始化失败');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => max(5, min(120, $timeout)),
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Webhook HTTP ' . $code . ' ' . mb_substr((string) $resp, 0, 200));
        }
    }
}
