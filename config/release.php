<?php
/**
 * 发行域配置入口（真源在 config/release/*.php）
 */
declare(strict_types=1);

$cfg = [];
foreach (glob(__DIR__ . '/release/*.php') ?: [] as $file) {
    $cfg[basename($file, '.php')] = include $file;
}

return $cfg;
