<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * AD-031：栏目列表页设置一等列（原主题频道 Tag 能力）
 * 用法: php app/database/migrations/site/migrate_site_nav_list_fields_v1.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

$name = 'migrate_site_nav_list_fields_v1';

migration_entry($name, static function (PDO $pdo, string $pfx, string $db): void {
    $col = migration_column_exists($db);
    $table = 'site_nav';
    $adds = [
        'url_path' => "ALTER TABLE `{$pfx}{$table}` ADD COLUMN `url_path` varchar(100) NOT NULL DEFAULT '' COMMENT '前台列表路径（无首尾斜杠）' AFTER `target`",
        'tpl_name' => "ALTER TABLE `{$pfx}{$table}` ADD COLUMN `tpl_name` varchar(100) NOT NULL DEFAULT '' COMMENT '列表页模板文件名' AFTER `url_path`",
        'view_tpl_name' => "ALTER TABLE `{$pfx}{$table}` ADD COLUMN `view_tpl_name` varchar(100) NOT NULL DEFAULT '' COMMENT '内容页默认模板' AFTER `tpl_name`",
        'seo_title' => "ALTER TABLE `{$pfx}{$table}` ADD COLUMN `seo_title` varchar(200) NOT NULL DEFAULT '' COMMENT 'SEO标题' AFTER `view_tpl_name`",
        'seo_keywords' => "ALTER TABLE `{$pfx}{$table}` ADD COLUMN `seo_keywords` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO关键词' AFTER `seo_title`",
        'seo_description' => "ALTER TABLE `{$pfx}{$table}` ADD COLUMN `seo_description` varchar(500) NOT NULL DEFAULT '' COMMENT 'SEO描述' AFTER `seo_keywords`",
        'litpic' => "ALTER TABLE `{$pfx}{$table}` ADD COLUMN `litpic` varchar(255) NOT NULL DEFAULT '' COMMENT '栏目封面图' AFTER `seo_description`",
        'read_perm' => "ALTER TABLE `{$pfx}{$table}` ADD COLUMN `read_perm` tinyint NOT NULL DEFAULT 0 COMMENT '阅读：0开放 1会员' AFTER `litpic`",
        'read_level_id' => "ALTER TABLE `{$pfx}{$table}` ADD COLUMN `read_level_id` int unsigned NOT NULL DEFAULT 0 COMMENT '会员等级ID，0=登录即可' AFTER `read_perm`",
    ];
    foreach ($adds as $field => $sql) {
        if (!$col($pdo, $pfx, $table, $field)) {
            $pdo->exec($sql);
            echo "  + site_nav.{$field}\n";
        } else {
            echo "  site_nav.{$field} exists\n";
        }
    }
    try {
        $pdo->exec("ALTER TABLE `{$pfx}{$table}` ADD KEY `idx_url_path` (`url_path`)");
        echo "  + idx_url_path\n";
    } catch (Throwable) {
        echo "  idx_url_path skip\n";
    }
}, static function (PDO $pdo, string $pfx, string $db): void {
    $table = $pfx . 'site_nav';
    foreach (['idx_url_path'] as $idx) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$idx}`");
        } catch (Throwable) {
        }
    }
    foreach ([
        'read_level_id',
        'read_perm',
        'litpic',
        'seo_description',
        'seo_keywords',
        'seo_title',
        'view_tpl_name',
        'tpl_name',
        'url_path',
    ] as $field) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$field}`");
            echo "  - site_nav.{$field}\n";
        } catch (Throwable $e) {
            echo "  site_nav.{$field} drop skip: " . $e->getMessage() . "\n";
        }
    }
});
