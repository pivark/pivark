<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 *
 * 后台物理入口 PATH_INFO 解析（无副作用，可供门禁单测）。
 * 与 install/bootstrap/install_path_info.php 同构：Nginx 无伪静态时
 * /admin/index.php/auth/login 仍可进后台。
 */
declare(strict_types=1);

if (!function_exists('pivark_admin_resolve_path_info')) {
    /**
     * @param array<string, mixed> $server 通常传 $_SERVER
     * @param string               $entrySegment 入口段，默认 admin；别名时为 manage 等
     */
    function pivark_admin_resolve_path_info(array $server, string $entrySegment = 'admin'): string
    {
        $entrySegment = strtolower(trim($entrySegment, '/'));
        if ($entrySegment === '' || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $entrySegment)) {
            $entrySegment = 'admin';
        }

        $uriPath = parse_url((string) ($server['REQUEST_URI'] ?? '/' . $entrySegment . '/'), PHP_URL_PATH);
        $uriPath = is_string($uriPath) ? $uriPath : '/' . $entrySegment . '/';

        $existing = (string) ($server['PATH_INFO'] ?? '');
        if ($existing !== '' && $existing !== '/') {
            if (str_starts_with($existing, '/' . $entrySegment)) {
                return $existing;
            }

            return '/' . $entrySegment . (str_starts_with($existing, '/') ? $existing : '/' . $existing);
        }

        $scriptNeedle = '#' . preg_quote('/' . $entrySegment . '/index.php', '#') . '(/.*)$#';
        if (preg_match($scriptNeedle, $uriPath, $m) === 1) {
            return '/' . $entrySegment . $m[1];
        }

        if ($uriPath === '' || str_ends_with($uriPath, '/index.php')
            || $uriPath === '/' . $entrySegment
            || $uriPath === '/' . $entrySegment . '/'
        ) {
            return '/' . $entrySegment . '/';
        }

        return $uriPath;
    }
}
