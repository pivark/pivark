<?php
// 日志配置（生产默认 error+warning；LOG_LEVEL=info 可开审计上下文）
$debug = filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
$logLevel = strtolower(trim((string) env('LOG_LEVEL', '')));
$defaultLevels = $debug
    ? []
    : ['error', 'critical', 'alert', 'emergency', 'warning'];
if ($logLevel !== '') {
    $allowed = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
    $picked  = array_values(array_filter(
        array_map('trim', explode(',', $logLevel)),
        static fn (string $level): bool => in_array($level, $allowed, true)
    ));
    $defaultLevels = $picked !== [] ? $picked : $defaultLevels;
}

$businessLevels = ['info', 'notice', 'warning'];
$slowLevels     = ['warning', 'error'];

return [
    // 默认日志驱动
    'default' => 'file',
    // 日志通道配置
    'channels' => [
        'file' => [
            'type'      => 'File',
            'path'      => '',
            'level'     => $defaultLevels,
            'file_size' => 10 * 1024 * 1024,
            'max_files' => 30,
        ],
        // 业务 info/warning（审计 2026-06-09：与 error 主通道分离）
        'business' => [
            'type'      => 'File',
            'path'      => '',
            'level'     => $businessLevels,
            'file_size' => 10 * 1024 * 1024,
            'max_files' => 14,
            'single'    => 'business',
        ],
        // 慢 SQL / 超阈值查询
        'slow_query' => [
            'type'      => 'File',
            'path'      => '',
            'level'     => $slowLevels,
            'file_size' => 10 * 1024 * 1024,
            'max_files' => 14,
            'single'    => 'slow_query',
        ],
    ],
];