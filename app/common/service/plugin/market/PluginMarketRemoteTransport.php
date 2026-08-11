<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;

use app\common\service\config\ConfigService;
use app\common\support\LocalFile;

/**
 * 市场远程 HTTP 运输（从 PluginMarketShelfDirectory 抽出）
 * — URL 协议展开 + GET/POST；目录语义/缓存/签验仍在 RemoteCatalog
 */
final class PluginMarketRemoteTransport
{
    /**
     * 服务端 HTTP 传输用：将 // 展开为带协议的绝对 URL 候选（不写死 https 为唯一标准）。
     *
     * @return list<string>
     */
    public function transportUrlCandidates(string $url): array
    {
        return $this->expandProtocolRelativeFetchUrls(trim($url));
    }

    /**
     * 服务端拉取：把 //host 补成可 curl 的绝对 URL（跟 site_url / 请求 / platform 协议，不写死 https）。
     *
     * @return list<string> 候选（通常 1 个；无上下文时 http+https 各试）
     */
    public function expandProtocolRelativeFetchUrls(string $url): array
    {
        $url = trim($url);
        if ($url === '' || !str_starts_with($url, '//')) {
            return $url !== '' ? [$url] : [];
        }
        $scheme = $this->inferTransportScheme();
        if ($scheme !== '') {
            return [$scheme . ':' . $url];
        }

        return ['https:' . $url, 'http:' . $url];
    }

    /** 推断传输层协议：请求 → site_url → LICENSE_PLATFORM_URL；皆无则空（由调用方双试） */
    public function inferTransportScheme(): string
    {
        try {
            if (function_exists('request')) {
                $req = request();
                if (is_object($req) && method_exists($req, 'scheme')) {
                    $s = strtolower(trim((string) $req->scheme()));
                    if (in_array($s, ['http', 'https'], true)) {
                        return $s;
                    }
                }
            }
        } catch (\Throwable) {
            // CLI / 无请求上下文
        }
        foreach ([
            trim((string) app(ConfigService::class)->get('site_url', '')),
            rtrim(trim((string) env('PIVARK_LICENSE_PLATFORM_URL', '')), '/'),
            trim((string) config('plugin.market.remote_catalog_url', '')),
        ] as $candidate) {
            if (preg_match('#^(https?):#i', $candidate, $m)) {
                return strtolower($m[1]);
            }
        }

        return '';
    }

    public function httpPostJson(string $url, string $jsonBody): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $jsonBody,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Content-Length: ' . (string) strlen($jsonBody),
                    'User-Agent: PivArk-PluginMarket/1.0',
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code < 200 || $code >= 300) {
                return null;
            }

            return (string) $body;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'timeout' => 15,
                'header'  => "Content-Type: application/json\r\nUser-Agent: PivArk-PluginMarket/1.0\r\n",
                'content' => $jsonBody,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);

        return is_string($body) ? $body : null;
    }

    public function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_USERAGENT      => 'PivArk-PluginMarket/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code < 200 || $code >= 300) {
                return null;
            }

            return (string) $body;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'header'  => "User-Agent: PivArk-PluginMarket/1.0\r\n",
            ],
        ]);
        $body = LocalFile::getContents($url, false, $ctx);

        return is_string($body) ? $body : null;
    }
}
