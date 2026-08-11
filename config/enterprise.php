<?php
/**
 * 企业域配置入口（真源在 config/enterprise/*.php）
 */
declare(strict_types=1);

$cfg = [];
foreach (glob(__DIR__ . '/enterprise/*.php') ?: [] as $file) {
    $cfg[basename($file, '.php')] = include $file;
}

return $cfg;
