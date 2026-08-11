<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * AD-031 收尾：物理 DROP tags.show_in_nav（行为已废止）。
 * 用法: php app/database/migrations/tag/migrate_drop_tag_show_in_nav_v1.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

$name = 'migrate_drop_tag_show_in_nav_v1';

migration_entry($name, static function (PDO $pdo, string $pfx, string $db): void {
    $tags = $pfx . 'tags';
    $col = $pdo->query("SHOW COLUMNS FROM `{$tags}` LIKE 'show_in_nav'")->fetch();
    if (!$col) {
        echo "  tags.show_in_nav already absent\n";

        return;
    }
    $pdo->exec("ALTER TABLE `{$tags}` DROP COLUMN `show_in_nav`");
    echo "  - tags.show_in_nav\n";
}, static function (PDO $pdo, string $pfx, string $db): void {
    $tags = $pfx . 'tags';
    $col = $pdo->query("SHOW COLUMNS FROM `{$tags}` LIKE 'show_in_nav'")->fetch();
    if ($col) {
        echo "  tags.show_in_nav exists\n";

        return;
    }
    // 回滚仅还原列；语义仍废止，默认 0
    $after = $pdo->query("SHOW COLUMNS FROM `{$tags}` LIKE 'seo_description'")->fetch()
        ? ' AFTER `seo_description`'
        : '';
    $pdo->exec(
        "ALTER TABLE `{$tags}` ADD COLUMN `show_in_nav` tinyint NOT NULL DEFAULT 0 COMMENT '已废止 AD-031'{$after}"
    );
    echo "  + tags.show_in_nav (rollback shell)\n";
});
