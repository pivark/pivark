<?php
/**
 * DI 域配置入口（真源在 config/di/*.php）
 */
declare(strict_types=1);

$cfg = [];
foreach (glob(__DIR__ . '/di/*.php') ?: [] as $file) {
    $cfg[basename($file, '.php')] = include $file;
}

return $cfg;
