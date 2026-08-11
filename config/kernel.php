<?php
/**
 * 内核域配置入口（真源在 config/kernel/*.php）
 */
declare(strict_types=1);

$cfg = [];
foreach (glob(__DIR__ . '/kernel/*.php') ?: [] as $file) {
    $cfg[basename($file, '.php')] = include $file;
}

return $cfg;
