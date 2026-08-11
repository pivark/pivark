<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 栏目退役独立 SEO 标题列（公开 title = 名称）
 * 用法: php app/database/migrations/site/migrate_site_nav_drop_seo_title_v1.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

$name = 'migrate_site_nav_drop_seo_title_v1';

migration_entry($name, static function (PDO $pdo, string $pfx, string $db): void {
    $col = migration_column_exists($db);
    $table = 'site_nav';
    if (!$col($pdo, $pfx, $table, 'seo_title')) {
        echo "  site_nav.seo_title already absent\n";

        return;
    }
    $pdo->exec("ALTER TABLE `{$pfx}{$table}` DROP COLUMN `seo_title`");
    echo "  - site_nav.seo_title\n";
}, static function (PDO $pdo, string $pfx, string $db): void {
    $col = migration_column_exists($db);
    $table = 'site_nav';
    if ($col($pdo, $pfx, $table, 'seo_title')) {
        echo "  site_nav.seo_title already exists\n";

        return;
    }
    $pdo->exec(
        "ALTER TABLE `{$pfx}{$table}` ADD COLUMN `seo_title` varchar(200) NOT NULL DEFAULT '' COMMENT 'SEO标题' AFTER `view_tpl_name`"
    );
    echo "  + site_nav.seo_title (rollback)\n";
});
