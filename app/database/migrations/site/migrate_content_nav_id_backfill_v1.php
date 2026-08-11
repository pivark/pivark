<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * AD-031：将旧「标签归属」回填到 documents.nav_id / items.nav_id（幂等，仅 nav_id=0）
 * 函数体内联（打包器只打注册表 migrate 文件）；种子副本见 install/assets/seed/lib_content_nav_id_backfill.php
 * 用法: php app/database/migrations/site/migrate_content_nav_id_backfill_v1.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

/**
 * AD-031：按 tags.name / target=slug 将 document/item.nav_id 从 0 回填（幂等）。
 *
 * @return array{tag_nav:int, items:int, documents:int}
 */
function pivark_content_nav_id_backfill(PDO $pdo, string $pfx, string $db): array
{
    $docs = $pfx . 'documents';
    $items = $pfx . 'items';
    $nav = $pfx . 'site_nav';
    $tags = $pfx . 'tags';
    $docTags = $pfx . 'document_tags';
    $itemTags = $pfx . 'item_tags';

    $hasCol = static function (PDO $pdo, string $table, string $col) use ($db): bool {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$db, $table, $col]);

        return (int) $st->fetchColumn() > 0;
    };

    $tableExists = static function (PDO $pdo, string $table) use ($db): bool {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $st->execute([$db, $table]);

        return (int) $st->fetchColumn() > 0;
    };

    if (!$hasCol($pdo, $docs, 'nav_id')) {
        echo "  skip documents.nav_id missing\n";

        return ['tag_nav' => 0, 'items' => 0, 'documents' => 0];
    }

    $parents = [];
    $st = $pdo->query("SELECT id, parent_id FROM `{$nav}`");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $parents[(int) $row['id']] = (int) $row['parent_id'];
    }
    $depthOf = static function (int $id) use (&$parents): int {
        $d = 0;
        $guard = 0;
        while ($id > 0 && $guard < 64) {
            $d++;
            $id = (int) ($parents[$id] ?? 0);
            $guard++;
        }

        return $d;
    };

    $tagNav = [];
    // title 匹配 + target=slug 匹配（安装种子更稳）
    $sql = "SELECT t.id AS tag_id, n.id AS nav_id
            FROM `{$tags}` t
            INNER JOIN `{$nav}` n ON (n.title = t.name OR n.target = t.slug)";
    foreach ($pdo->query($sql) as $row) {
        $tid = (int) $row['tag_id'];
        $nid = (int) $row['nav_id'];
        $tagNav[$tid][] = ['nav_id' => $nid, 'depth' => $depthOf($nid)];
    }
    foreach ($tagNav as $tid => $cands) {
        usort($cands, static fn (array $a, array $b): int => $b['depth'] <=> $a['depth']);
        $tagNav[$tid] = $cands;
    }
    echo '  tag↔nav matches: ' . count($tagNav) . "\n";

    $pickNav = static function (array $tagIds) use ($tagNav): int {
        $bestId = 0;
        $bestDepth = -1;
        foreach ($tagIds as $tid) {
            $cands = $tagNav[(int) $tid] ?? [];
            if ($cands === []) {
                continue;
            }
            $top = $cands[0];
            if ((int) $top['depth'] > $bestDepth) {
                $bestDepth = (int) $top['depth'];
                $bestId = (int) $top['nav_id'];
            }
        }

        return $bestId;
    };

    $itemUpdated = 0;
    if ($tableExists($pdo, $items) && $hasCol($pdo, $items, 'nav_id') && $tableExists($pdo, $itemTags)) {
        $itemTagsMap = [];
        foreach ($pdo->query("SELECT item_id, tag_id FROM `{$itemTags}`") as $row) {
            $itemTagsMap[(int) $row['item_id']][] = (int) $row['tag_id'];
        }
        $upd = $pdo->prepare("UPDATE `{$items}` SET `nav_id` = ? WHERE `id` = ? AND (`nav_id` = 0 OR `nav_id` IS NULL)");
        foreach ($pdo->query("SELECT id FROM `{$items}` WHERE nav_id = 0 OR nav_id IS NULL") as $row) {
            $id = (int) $row['id'];
            $navId = $pickNav($itemTagsMap[$id] ?? []);
            if ($navId <= 0) {
                continue;
            }
            $upd->execute([$navId, $id]);
            $itemUpdated += $upd->rowCount();
        }
        echo "  items.nav_id backfilled: {$itemUpdated}\n";
    }

    $docUpdated = 0;
    if ($tableExists($pdo, $docTags)) {
        $docTagsMap = [];
        foreach ($pdo->query("SELECT document_id, tag_id FROM `{$docTags}`") as $row) {
            $docTagsMap[(int) $row['document_id']][] = (int) $row['tag_id'];
        }
        $upd = $pdo->prepare("UPDATE `{$docs}` SET `nav_id` = ? WHERE `id` = ? AND (`nav_id` = 0 OR `nav_id` IS NULL)");
        $lastId = 0;
        while (true) {
            $st = $pdo->prepare(
                "SELECT id FROM `{$docs}` WHERE (nav_id = 0 OR nav_id IS NULL) AND id > ? ORDER BY id ASC LIMIT 500"
            );
            $st->execute([$lastId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $lastId = $id;
                $navId = $pickNav($docTagsMap[$id] ?? []);
                if ($navId <= 0) {
                    continue;
                }
                $upd->execute([$navId, $id]);
                $docUpdated += $upd->rowCount();
            }
        }
        echo "  documents.nav_id backfilled: {$docUpdated}\n";
    }

    return ['tag_nav' => count($tagNav), 'items' => $itemUpdated, 'documents' => $docUpdated];
}


$name = 'migrate_content_nav_id_backfill_v1';

migration_irreversible($name, 'nav_id backfill cannot restore prior zeros; restore from backup if rollback needed', static function (PDO $pdo, string $pfx, string $db): void {
    pivark_content_nav_id_backfill($pdo, $pfx, $db);
});
