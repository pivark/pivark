<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * AD-031 正名：侧栏「内容标签」→「标签」；演示组「网站栏目」→「默认标签」
 * 用法: php app/database/migrations/menu/migrate_menu_tag_title_rename_v39.php [--force]
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

use app\common\service\admin\AdminSpaMenuRouteCacheService;

$name = 'migrate_menu_tag_title_rename_v39';

migration_irreversible($name, 'menu/tag_group rename; restore from backup if rollback needed', static function (PDO $pdo, string $pfx, string $db) use ($name): void {
    $menu  = "{$pfx}menus";
    $groups = "{$pfx}tag_groups";

    migration_transaction($pdo, static function (PDO $pdo) use ($menu, $groups, $pfx): void {
        $nMenu = $pdo->exec(
            "UPDATE `{$menu}` SET `title` = '标签'
             WHERE `title` = '内容标签'"
        );
        echo '  renamed tag menu titles: ' . (int) $nMenu . "\n";

        $nGroup = $pdo->exec(
            "UPDATE `{$groups}` SET `name` = '默认标签', `updated_at` = NOW()
             WHERE `name` = '网站栏目'"
        );
        echo '  renamed demo tag_groups: ' . (int) $nGroup . "\n";

        $applied = migration_apply_admin_menu_ssot($pdo, $pfx);
        echo "  admin_menu_ssot applied: {$applied}\n";
    });

    // CLI 下 Cache facade 常不可用：revision bust + 扫文件清旧菜单树
    try {
        if (class_exists(AdminSpaMenuRouteCacheService::class)) {
            app(AdminSpaMenuRouteCacheService::class)->bustAll();
            echo "  menu route cache busted\n";
        }
    } catch (Throwable $e) {
        echo '  menu route cache bust skipped: ' . $e->getMessage() . "\n";
    }
    $cacheDir = dirname(__DIR__, 3) . '/data/runtime/cache';
    $cleared = 0;
    if (is_dir($cacheDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = (string) $file->getPathname();
            $raw = (string) @file_get_contents($path);
            if ($raw !== '' && (str_contains($raw, '内容标签') || str_contains($raw, 'admin_spa_vben_routes'))) {
                @unlink($path);
                ++$cleared;
            }
        }
    }
    echo "  cleared runtime menu cache files: {$cleared}\n";
    echo "OK {$name} on {$db}\n";
});
