<?php
/**
 * 已退役的后台 HTML 组 JSON 路径（须走 /api/v1/admin/* REST）
 *
 * @return array<string, string> admin 相对前缀 => 404 提示
 */
declare(strict_types=1);

return [
    'login/captcha'  => '请使用 GET /api/v1/admin/auth/captcha',
    'login/status'   => '请使用 GET /api/v1/admin/auth/status',
    'login/do_login' => '请使用 POST /api/v1/admin/auth/login',
    'login/logout'   => '请使用 POST /api/v1/admin/auth/logout',
];
