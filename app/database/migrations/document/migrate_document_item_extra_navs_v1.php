<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 文档/品项附加栏目关联表（主归属仍为 documents.nav_id / items.nav_id）
 * 用法: php app/database/migrations/document/migrate_document_item_extra_navs_v1.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

$name = 'migrate_document_item_extra_navs_v1';

migration_entry($name, static function (PDO $pdo, string $pfx, string $db): void {
    $doc = "{$pfx}document_navs";
    $item = "{$pfx}item_navs";
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `{$doc}` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `document_id` int unsigned NOT NULL DEFAULT 0 COMMENT '文档ID',
            `nav_id` int unsigned NOT NULL DEFAULT 0 COMMENT '附加栏目 site_nav.id',
            `created_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_document_nav` (`document_id`,`nav_id`),
            KEY `idx_nav_id` (`nav_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文档附加栏目'"
    );
    echo "  + document_navs\n";
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `{$item}` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `item_id` int unsigned NOT NULL DEFAULT 0 COMMENT '品项ID',
            `nav_id` int unsigned NOT NULL DEFAULT 0 COMMENT '附加栏目 site_nav.id',
            `created_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_item_nav` (`item_id`,`nav_id`),
            KEY `idx_nav_id` (`nav_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='品项附加栏目'"
    );
    echo "  + item_navs\n";
}, static function (PDO $pdo, string $pfx, string $db): void {
    foreach (['document_navs', 'item_navs'] as $t) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `{$pfx}{$t}`");
            echo "  - {$t}\n";
        } catch (Throwable $e) {
            echo "  {$t} drop skip: " . $e->getMessage() . "\n";
        }
    }
});
