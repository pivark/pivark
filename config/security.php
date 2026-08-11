<?php
/**
 * 安全域配置入口（真源在 config/security/*.php）
 */
declare(strict_types=1);

$cfg = [];
foreach (glob(__DIR__ . '/security/*.php') ?: [] as $file) {
    $cfg[basename($file, '.php')] = include $file;
}

return $cfg;
