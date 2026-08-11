<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 *
 * 安装入口 PATH_INFO 解析（无副作用，可供门禁单测）。
 */
declare(strict_types=1);

if (!function_exists('pivark_install_resolve_path_info')) {
    /**
     * @param array<string, mixed> $server 通常传 $_SERVER
     */
    function pivark_install_resolve_path_info(array $server): string
    {
        $uriPath = parse_url((string) ($server['REQUEST_URI'] ?? '/install/'), PHP_URL_PATH);
        $uriPath = is_string($uriPath) ? $uriPath : '/install/';

        $existing = (string) ($server['PATH_INFO'] ?? '');
        if ($existing !== '' && $existing !== '/') {
            if (str_starts_with($existing, '/install')) {
                return $existing;
            }

            return '/install' . (str_starts_with($existing, '/') ? $existing : '/' . $existing);
        }

        if (preg_match('#/install/index\.php(/.*)$#', $uriPath, $m) === 1) {
            return '/install' . $m[1];
        }

        if ($uriPath === '' || str_ends_with($uriPath, '/index.php')) {
            return '/install/';
        }

        return $uriPath;
    }
}
