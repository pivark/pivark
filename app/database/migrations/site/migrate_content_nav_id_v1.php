<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * AD-031：文档/品项归属真分类 — documents.nav_id / items.nav_id
 * 用法: php app/database/migrations/site/migrate_content_nav_id_v1.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

$name = 'migrate_content_nav_id_v1';

migration_entry($name, static function (PDO $pdo, string $pfx, string $db): void {
    $docs = $pfx . 'documents';
    $items = $pfx . 'items';

    $docCol = $pdo->query("SHOW COLUMNS FROM `{$docs}` LIKE 'nav_id'")->fetch();
    if (!$docCol) {
        $pdo->exec(
            "ALTER TABLE `{$docs}` ADD COLUMN `nav_id` int unsigned NOT NULL DEFAULT 0 COMMENT '真分类 site_nav.id，0=未归类' AFTER `author_id`"
        );
        $pdo->exec("ALTER TABLE `{$docs}` ADD KEY `idx_nav_id` (`nav_id`)");
        echo "  + documents.nav_id\n";
    } else {
        echo "  documents.nav_id exists\n";
    }

    $itemExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=" . $pdo->quote($db) . " AND table_name=" . $pdo->quote($items)
    )->fetchColumn();
    if ((int) $itemExists > 0) {
        $itemCol = $pdo->query("SHOW COLUMNS FROM `{$items}` LIKE 'nav_id'")->fetch();
        if (!$itemCol) {
            $pdo->exec(
                "ALTER TABLE `{$items}` ADD COLUMN `nav_id` int unsigned NOT NULL DEFAULT 0 COMMENT '真分类 site_nav.id，0=未归类' AFTER `primary_document_id`"
            );
            $pdo->exec("ALTER TABLE `{$items}` ADD KEY `idx_nav_id` (`nav_id`)");
            echo "  + items.nav_id\n";
        } else {
            echo "  items.nav_id exists\n";
        }
    } else {
        echo "  skip items (table missing)\n";
    }
}, static function (PDO $pdo, string $pfx, string $db): void {
    $docs = $pfx . 'documents';
    $items = $pfx . 'items';
    try {
        $pdo->exec("ALTER TABLE `{$docs}` DROP INDEX `idx_nav_id`");
    } catch (Throwable) {
    }
    try {
        $pdo->exec("ALTER TABLE `{$docs}` DROP COLUMN `nav_id`");
        echo "  - documents.nav_id\n";
    } catch (Throwable $e) {
        echo '  documents.nav_id drop skip: ' . $e->getMessage() . "\n";
    }
    $itemExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=" . $pdo->quote($db) . " AND table_name=" . $pdo->quote($items)
    )->fetchColumn();
    if ($itemExists > 0) {
        try {
            $pdo->exec("ALTER TABLE `{$items}` DROP INDEX `idx_nav_id`");
        } catch (Throwable) {
        }
        try {
            $pdo->exec("ALTER TABLE `{$items}` DROP COLUMN `nav_id`");
            echo "  - items.nav_id\n";
        } catch (Throwable $e) {
            echo '  items.nav_id drop skip: ' . $e->getMessage() . "\n";
        }
    }
});
