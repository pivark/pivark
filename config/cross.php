<?php
// 跨域（仅当 API 路由组挂载 AllowCrossDomain 且 env API_CORS_ORIGINS 非空时生效）
$raw = trim((string) env('API_CORS_ORIGINS', ''));
$allowOrigin = $raw === '' ? '' : $raw;

return [
    'paths'             => ['api/*'],
    'allow_origin'      => $allowOrigin,
    'allow_methods'     => 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
    'allow_headers'     => 'Authorization,Content-Type,If-Match,If-Modified-Since,If-None-Match,If-Unmodified-Since,X-Requested-With,X-Idempotency-Key,X-CSRF-Token',
    'expose_headers'    => '',
    'max_age'           => 1800,
    'allow_credentials' => 'false',
];
