<?php
/**
 * 后台域配置入口（真源在 config/admin/*.php）
 */
declare(strict_types=1);

$cfg = [];
foreach (glob(__DIR__ . '/admin/*.php') ?: [] as $file) {
    $cfg[basename($file, '.php')] = include $file;
}

return $cfg;
