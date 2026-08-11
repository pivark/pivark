<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\middleware;

use app\common\service\config\ConfigService;
use think\Request;
use think\Response;

/** 全站 IP 黑白名单（配置项 ip_access_mode / ip_allowlist / ip_blocklist） */
class IpAccessMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        $mode = strtolower(trim((string) app(ConfigService::class)->get('ip_access_mode', 'off')));
        if ($mode === 'off' || $mode === '') {
            return $next($request);
        }

        $ip = (string) $request->ip();
        if ($ip === '') {
            return $next($request);
        }

        $blocklist = self::parseList((string) app(ConfigService::class)->get('ip_blocklist', ''));
        $allowlist = self::parseList((string) app(ConfigService::class)->get('ip_allowlist', ''));

        if ($blocklist !== [] && self::ipMatchesList($ip, $blocklist)) {
            return response('Forbidden', 403);
        }

        if ($mode === 'allow') {
            if ($allowlist === [] || !self::ipMatchesList($ip, $allowlist)) {
                return response('Forbidden', 403);
            }
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    private static function parseList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n|,/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $rules
     */
    private static function ipMatchesList(string $ip, array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule === $ip) {
                return true;
            }
            if (str_contains($rule, '/') && self::ipInCidr($ip, $rule)) {
                return true;
            }
            if (str_ends_with($rule, '*') && str_starts_with($ip, rtrim($rule, '*'))) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int) $mask;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || $mask < 0 || $mask > 32) {
            return false;
        }
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $maskLong = -1 << (32 - $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
