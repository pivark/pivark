<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\infra\CurlTlsService;

/** 轻量 curl 出站 SSOT（GET/HEAD/POST JSON 等通用场景） */
final class SimpleHttpClient
{
    /**
     * @param  array<string, mixed>  $options  method, headers, body, timeout, connect_timeout, no_body, follow_location, max_redirects, user_agent
     * @return array{http_code: int, body: string, errno: int, error: string}
     */
    public static function request(string $url, array $options = []): array
    {
        if (!function_exists('curl_init')) {
            return ['http_code' => 0, 'body' => '', 'errno' => -1, 'error' => 'curl_init unavailable'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['http_code' => 0, 'body' => '', 'errno' => -1, 'error' => 'curl_init failed'];
        }

        $method = strtoupper((string) ($options['method'] ?? 'GET'));
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $timeout = max(1, (int) ($options['timeout'] ?? 30));
        $connectTimeout = max(1, (int) ($options['connect_timeout'] ?? min(10, $timeout)));

        $curlOpts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if (!empty($options['no_body'])) {
            $curlOpts[CURLOPT_NOBODY] = true;
        }
        if (array_key_exists('follow_location', $options)) {
            $curlOpts[CURLOPT_FOLLOWLOCATION] = (bool) $options['follow_location'];
        }
        if (isset($options['max_redirects'])) {
            $curlOpts[CURLOPT_MAXREDIRS] = max(0, (int) $options['max_redirects']);
        }
        if (!empty($options['user_agent'])) {
            $curlOpts[CURLOPT_USERAGENT] = (string) $options['user_agent'];
        }
        if (array_key_exists('body', $options) && $options['body'] !== null) {
            $curlOpts[CURLOPT_POSTFIELDS] = (string) $options['body'];
        }

        curl_setopt_array($ch, $curlOpts);
        if (array_key_exists('verify_ssl', $options) && $options['verify_ssl'] === false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        } else {
            app(CurlTlsService::class)->applyToCurl($ch);
        }

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 0) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }
        curl_close($ch);

        return [
            'http_code' => $code,
            'body'      => is_string($body) ? $body : '',
            'errno'     => $errno,
            'error'     => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{http_code: int, body: string}
     */
    public static function requestOrFail(string $url, array $options = []): array
    {
        $res = self::request($url, $options);
        if ($res['errno'] !== 0) {
            throw new \RuntimeException('HTTP 请求失败: curl ' . $res['errno'] . ' ' . $res['error']);
        }

        return ['http_code' => $res['http_code'], 'body' => $res['body']];
    }
}
