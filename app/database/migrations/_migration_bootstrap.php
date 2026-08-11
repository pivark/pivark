<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 迁移 bootstrap 入口：lane 脚本须 define PIVARK_MIGRATION_SSOT 后只经本文件载入 _migration.php，避免双载 fatal。
 */
declare(strict_types=1);

if (\defined('PIVARK_MIGRATION_BOOTSTRAP')) {
    return;
}

$bootstrap = \defined('PIVARK_MIGRATION_SSOT') && is_readable((string) PIVARK_MIGRATION_SSOT)
    ? (string) PIVARK_MIGRATION_SSOT
    : __DIR__ . DIRECTORY_SEPARATOR . '_migration.php';

require_once $bootstrap;
