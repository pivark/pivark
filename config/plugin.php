<?php
/**
 * 插件域配置入口（真源在 config/plugin/*.php）
 */
declare(strict_types=1);

$cfg = [];
foreach (glob(__DIR__ . '/plugin/*.php') ?: [] as $file) {
    $cfg[basename($file, '.php')] = include $file;
}

return $cfg;
