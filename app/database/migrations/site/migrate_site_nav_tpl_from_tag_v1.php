<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * AD-031：真分类列表模板真源 = site_nav.tpl_name。
 * TYPE_TAG 栏目若 tpl_name/url_path 仍空，从绑定 Tag 一次性回填（幂等；不改已有非空值）。
 * 用法: php app/database/migrations/site/migrate_site_nav_tpl_from_tag_v1.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

$name = 'migrate_site_nav_tpl_from_tag_v1';

migration_irreversible($name, 'tpl_name/url_path one-way backfill; restore from backup if rollback needed', static function (PDO $pdo, string $pfx, string $db): void {
    $hasCol = static function (PDO $pdo, string $table, string $col) use ($db): bool {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$db, $table, $col]);

        return (int) $st->fetchColumn() > 0;
    };

    if (!$hasCol($pdo, $pfx . 'site_nav', 'tpl_name') || !$hasCol($pdo, $pfx . 'tags', 'tpl_name')) {
        echo "  skip: site_nav.tpl_name or tags.tpl_name missing\n";

        return;
    }

    $navHasUrlPath = $hasCol($pdo, $pfx . 'site_nav', 'url_path');
    $tagHasUrlPath = $hasCol($pdo, $pfx . 'tags', 'url_path');
    $navHasViewTpl = $hasCol($pdo, $pfx . 'site_nav', 'view_tpl_name');
    $tagHasViewTpl = $hasCol($pdo, $pfx . 'tags', 'view_tpl_name');

    $sql = "SELECT n.id AS nav_id, n.tpl_name AS nav_tpl, n.target AS nav_target,
                   t.tpl_name AS tag_tpl";
    if ($navHasUrlPath && $tagHasUrlPath) {
        $sql .= ', n.url_path AS nav_path, t.url_path AS tag_path';
    }
    if ($navHasViewTpl && $tagHasViewTpl) {
        $sql .= ', n.view_tpl_name AS nav_view_tpl, t.view_tpl_name AS tag_view_tpl';
    }
    $sql .= " FROM `{$pfx}site_nav` n
              INNER JOIN `{$pfx}tags` t
                ON t.slug = TRIM(n.target)
                OR (t.url_path <> '' AND t.url_path = TRIM(n.target))
              WHERE n.nav_type = 'tag'
                AND TRIM(n.target) <> ''";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0;
    foreach ($rows as $row) {
        $navId = (int) ($row['nav_id'] ?? 0);
        if ($navId < 1) {
            continue;
        }
        $sets = [];
        $params = [];

        $navTpl = trim((string) ($row['nav_tpl'] ?? ''));
        $tagTpl = trim((string) ($row['tag_tpl'] ?? ''));
        if ($navTpl === '' && $tagTpl !== '') {
            $sets[] = '`tpl_name` = ?';
            $params[] = $tagTpl;
        }

        if ($navHasUrlPath && $tagHasUrlPath) {
            $navPath = trim((string) ($row['nav_path'] ?? ''), '/');
            $tagPath = trim((string) ($row['tag_path'] ?? ''), '/');
            if ($navPath === '' && $tagPath !== '') {
                $sets[] = '`url_path` = ?';
                $params[] = $tagPath;
            }
        }

        if ($navHasViewTpl && $tagHasViewTpl) {
            $navView = trim((string) ($row['nav_view_tpl'] ?? ''));
            $tagView = trim((string) ($row['tag_view_tpl'] ?? ''));
            if ($navView === '' && $tagView !== '') {
                $sets[] = '`view_tpl_name` = ?';
                $params[] = $tagView;
            }
        }

        if ($sets === []) {
            continue;
        }

        $params[] = $navId;
        $pdo->prepare(
            'UPDATE `' . $pfx . 'site_nav` SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($params);
        $updated++;
        echo "  nav#{$navId} target=" . trim((string) ($row['nav_target'] ?? ''))
            . ' tpl=' . ($tagTpl !== '' && $navTpl === '' ? $tagTpl : '(keep)')
            . "\n";
    }

    echo "  updated={$updated}\n";
});
