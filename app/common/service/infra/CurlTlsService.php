<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

/**
 * 为 PHP curl 出站 HTTPS 提供 CA 根证书（Windows 等环境 php.ini 常未配置 curl.cainfo）。
 */
class CurlTlsService
{

    /** @var string|null */
    private static ?string $resolvedCaPath = null;

    /** @var bool|null */
    private static ?bool $hasResolved = null;

    /**
     * 解析可用的 CA 证书包路径；无则返回 null。
     */
    public function resolveCaBundlePath(): ?string
    {
        if (self::$hasResolved === true) {
            return self::$resolvedCaPath;
        }
        self::$hasResolved = true;
        self::$resolvedCaPath = $this->discoverCaBundlePath();

        return self::$resolvedCaPath;
    }

    /**
     * 支付网关等出站 HTTPS：TLS + 优先 IPv4（Windows 下 IPv6 DNS 偶发 Could not resolve host）。
     *
     * @param resource|\CurlHandle $ch
     */
    public function applyPaymentOutbound($ch): void
    {
        $this->applyToCurl($ch);
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    }

    /**
     * curl 英文报错 → 前台可读中文（保留原句供日志排查）。
     */
    public static function friendlyOutboundError(string $err): string
    {
        $err = trim($err);
        if ($err === '') {
            return '连接支付网关失败，请稍后重试';
        }
        if (preg_match('/could not resolve host/i', $err)) {
            return '服务器无法解析微信支付域名 api.mch.weixin.qq.com，请检查本机 DNS 或改用支付宝；运维可将 DNS 设为 223.5.5.5 / 114.114.114.114 后重试';
        }
        if (preg_match('/timed out|timeout|0 bytes received/i', $err)) {
            return '连接微信支付超时，请稍后重试或检查服务器出网防火墙';
        }
        if (preg_match('/ssl|certificate/i', $err)) {
            return '支付 HTTPS 证书校验失败，请联系管理员配置 curl CA 证书';
        }

        return $err;
    }

    /**
     * 为 curl 句柄启用 TLS 校验（优先使用项目内 cacert.pem）。
     *
     * @param resource|\CurlHandle $ch
     */
    public function applyToCurl($ch): void
    {
        $ca = $this->resolveCaBundlePath();
        if ($ca !== null && is_readable($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            return;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    private function discoverCaBundlePath(): ?string
    {
        $candidates = [];

        $env = trim((string) (getenv('PIVARK_CURL_CAFILE') ?: ''));
        if ($env !== '' && is_file($env)) {
            $candidates[] = $env;
        }

        foreach (['curl.cainfo', 'openssl.cafile'] as $iniKey) {
            $ini = trim((string) ini_get($iniKey));
            if ($ini !== '' && is_file($ini)) {
                $candidates[] = $ini;
            }
        }

        $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__, 4) . DIRECTORY_SEPARATOR);
        $this->ensureProjectCaBundle($root);
        $candidates[] = $root . 'config' . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem';

        $vendorCa = $root . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR
            . 'ca-bundle' . DIRECTORY_SEPARATOR . 'res' . DIRECTORY_SEPARATOR . 'cacert.pem';
        $candidates[] = $vendorCa;

        foreach ($candidates as $path) {
            if ($path !== '' && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * 确保项目内 config/certs/cacert.pem 存在（优先从 composer ca-bundle 复制，否则从 curl.se 下载）。
     */
    public function ensureProjectCaBundle(?string $root = null): void
    {
        $root = $root ?? (defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__, 4) . DIRECTORY_SEPARATOR));
        $path = $root . 'config' . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (is_readable($path)) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $vendor = $root . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR
            . 'ca-bundle' . DIRECTORY_SEPARATOR . 'res' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (is_readable($vendor)) {
            copy($vendor, $path);

            return;
        }
        if (!function_exists('curl_init')) {
            return;
        }
        $ch = curl_init('https://curl.se/ca/cacert.pem');
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw) || strlen($raw) < 1000 || !str_contains($raw, 'BEGIN CERTIFICATE')) {
            return;
        }
        file_put_contents($path, $raw);
    }
}
