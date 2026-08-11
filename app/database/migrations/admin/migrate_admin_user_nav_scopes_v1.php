<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * AD-031：管理员栏目数据范围（发文/品项可管范围认 site_nav）
 * 用法: php app/database/migrations/admin/migrate_admin_user_nav_scopes_v1.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

$name = 'migrate_admin_user_nav_scopes_v1';

migration_entry($name, static function (PDO $pdo, string $pfx, string $db): void {
    $scopeTable = $pfx . 'admin_user_nav_scopes';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([$db, $scopeTable]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec("CREATE TABLE `{$scopeTable}` (
            `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
            `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '后台用户ID',
            `nav_id` int unsigned NOT NULL DEFAULT 0 COMMENT '可管理的栏目ID（含子孙）',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_user_nav` (`user_id`,`nav_id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_nav_id` (`nav_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员栏目数据范围'");
        echo "  + table admin_user_nav_scopes\n";
    } else {
        echo "  admin_user_nav_scopes already exists\n";
    }
}, static function (PDO $pdo, string $pfx, string $db): void {
    // no-op structural rollback
});
