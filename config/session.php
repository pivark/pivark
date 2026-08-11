<?php
// Session 配置（多机生产：.env SESSION_TYPE=cache SESSION_STORE=redis）
$https = filter_var(env('APP_HTTPS', '0'), FILTER_VALIDATE_BOOLEAN)
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$appEnv = strtolower(trim((string) env('APP_ENV', '')));
$sessionType  = strtolower(trim((string) env('SESSION_TYPE', 'file')));
$sessionStore = env('SESSION_STORE', null);
if (!in_array($sessionType, ['file', 'cache'], true)) {
    $sessionType = 'file';
}
if ($appEnv === 'production' && $sessionType === 'file') {
    error_log('[PivArk session] production 使用 file session，多机部署请设置 SESSION_TYPE=cache SESSION_STORE=redis');
}

$sessionRoot = defined('ROOT_PATH')
    ? ROOT_PATH
    : dirname(__DIR__) . DIRECTORY_SEPARATOR;
$sessionPath = $sessionRoot . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'session';

$sessionExpire = (int) env('SESSION_EXPIRE', 7200);
if ($sessionExpire < 300) {
    $sessionExpire = 7200;
}

return [
    'type'           => $sessionType,
    'path'           => $sessionPath,
    'store'          => ($sessionStore !== null && $sessionStore !== '') ? (string) $sessionStore : null,
    'expire'         => $sessionExpire,
    'prefix'         => '',
    'var_session_id' => '',
    'name'           => 'PHPSESSID',
    'serialize'      => [],
    'cookie'         => [
        'expire'   => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https || $appEnv === 'production',
        'httponly' => true,
        'samesite' => 'lax',
    ],
];
