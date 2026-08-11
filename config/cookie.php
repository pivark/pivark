<?php
// Cookie 配置（覆盖框架默认 httponly=false / secure=false）
$https = filter_var(env('APP_HTTPS', '0'), FILTER_VALIDATE_BOOLEAN);

return [
    'expire'    => 0,
    'prefix'    => '',
    'path'      => '/',
    'domain'    => '',
    'secure'    => $https,
    'httponly'  => true,
    'setcookie' => true,
    'samesite'  => 'lax',
];
