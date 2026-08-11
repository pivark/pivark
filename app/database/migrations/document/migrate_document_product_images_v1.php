<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 文档产品多图（封面仍用 documents.litpic；本表为有序产品图，≠ doc_gallery 图集）
 * 用法: php app/database/migrations/document/migrate_document_product_images_v1.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

$name = 'migrate_document_product_images_v1';

migration_entry($name, static function (PDO $pdo, string $pfx, string $db): void {
    $table = "{$pfx}document_product_images";
    $exists = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $pdo->quote($db)
        . ' AND TABLE_NAME=' . $pdo->quote($table)
    )->fetchColumn();
    if ($exists > 0) {
        echo "  document_product_images exists\n";

        return;
    }
    $pdo->exec(
        "CREATE TABLE `{$table}` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `document_id` int unsigned NOT NULL DEFAULT 0 COMMENT '文档ID',
            `url` varchar(512) NOT NULL DEFAULT '' COMMENT '图片路径',
            `sort` int NOT NULL DEFAULT 0 COMMENT '排序升序',
            `is_cover` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '1=封面(与 documents.litpic 一致)',
            `created_at` datetime DEFAULT NULL,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_document_sort` (`document_id`,`sort`),
            KEY `idx_document_cover` (`document_id`,`is_cover`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文档产品多图(非图集)'"
    );
    echo "  + document_product_images\n";

    // 有 litpic 的文档回填一行封面产品图
    $docs = $pfx . 'documents';
    $hasLitpic = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=' . $pdo->quote($db)
        . ' AND TABLE_NAME=' . $pdo->quote($docs) . " AND COLUMN_NAME='litpic'"
    )->fetchColumn();
    if ($hasLitpic < 1) {
        return;
    }
    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare(
        "INSERT INTO `{$table}` (`document_id`,`url`,`sort`,`is_cover`,`created_at`,`updated_at`)
         SELECT `id`, `litpic`, 0, 1, ?, ? FROM `{$docs}`
         WHERE `litpic` IS NOT NULL AND TRIM(`litpic`) <> '' AND `deleted_at` IS NULL"
    );
    $ins->execute([$now, $now]);
    echo '  seed cover rows: ' . $ins->rowCount() . "\n";
}, static function (PDO $pdo, string $pfx, string $db): void {
    migration_drop_table_if_exists($pdo, $db, $pfx . 'document_product_images');
});
