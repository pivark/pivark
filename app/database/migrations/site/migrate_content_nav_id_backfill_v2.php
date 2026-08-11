<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * AD-031 补丁：v1 回填漏掉 status=0 栏目；对仍为 nav_id=0 的行再跑一遍（幂等）。
 * 匹配规则与 v1 修复后一致：title 对齐，含停用栏目。
 * 用法: php app/database/migrations/site/migrate_content_nav_id_backfill_v2.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

$name = 'migrate_content_nav_id_backfill_v2';

migration_entry($name, static function (PDO $pdo, string $pfx, string $db): void {
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
        echo "  skip documents.nav_id missing (run migrate_content_nav_id_v1 first)\n";

        return;
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

    /** @return array<int, list<array{nav_id:int, depth:int}>> tag_id => candidates */
    $buildTagNavMap = static function (PDO $pdo) use ($nav, $tags, $depthOf): array {
        $sql = "SELECT t.id AS tag_id, n.id AS nav_id
                FROM `{$tags}` t
                INNER JOIN `{$nav}` n ON n.title = t.name";
        $map = [];
        foreach ($pdo->query($sql) as $row) {
            $tid = (int) $row['tag_id'];
            $nid = (int) $row['nav_id'];
            $map[$tid][] = ['nav_id' => $nid, 'depth' => $depthOf($nid)];
        }
        foreach ($map as $tid => $cands) {
            usort($cands, static fn (array $a, array $b): int => $b['depth'] <=> $a['depth']);
            $map[$tid] = $cands;
        }

        return $map;
    };

    $tagNav = $buildTagNavMap($pdo);
    echo '  tag↔nav title matches: ' . count($tagNav) . "\n";

    $pickNav = static function (array $tagIds) use ($tagNav): int {
        $bestId = 0;
        $bestDepth = -1;
        foreach ($tagIds as $tid) {
            $tid = (int) $tid;
            $cands = $tagNav[$tid] ?? [];
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
    } else {
        echo "  skip items backfill\n";
    }

    $docUpdated = 0;
    if ($tableExists($pdo, $docTags)) {
        $docTagsMap = [];
        foreach ($pdo->query("SELECT document_id, tag_id FROM `{$docTags}`") as $row) {
            $docTagsMap[(int) $row['document_id']][] = (int) $row['tag_id'];
        }
        $contentNavIds = [];
        try {
            $q = $pdo->query(
                "SELECT id FROM `{$nav}` WHERE content_kind IN ('document','page','article','news','download','')"
            );
            while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                $contentNavIds[(int) $r['id']] = true;
            }
        } catch (Throwable) {
            $contentNavIds = [];
        }
        $pickDocNav = static function (array $tagIds) use ($tagNav, $contentNavIds): int {
            $bestId = 0;
            $bestDepth = -1;
            foreach ($tagIds as $tid) {
                foreach ($tagNav[(int) $tid] ?? [] as $cand) {
                    $nid = (int) $cand['nav_id'];
                    if ($contentNavIds !== [] && !isset($contentNavIds[$nid])) {
                        continue;
                    }
                    if ((int) $cand['depth'] > $bestDepth) {
                        $bestDepth = (int) $cand['depth'];
                        $bestId = $nid;
                    }
                }
            }
            if ($bestId > 0) {
                return $bestId;
            }
            foreach ($tagIds as $tid) {
                $cands = $tagNav[(int) $tid] ?? [];
                if ($cands !== []) {
                    return (int) $cands[0]['nav_id'];
                }
            }

            return 0;
        };

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
                $navId = $pickDocNav($docTagsMap[$id] ?? []);
                if ($navId <= 0) {
                    continue;
                }
                $upd->execute([$navId, $id]);
                $docUpdated += $upd->rowCount();
            }
        }
        echo "  documents.nav_id backfilled: {$docUpdated}\n";
    } else {
        echo "  skip documents backfill (no document_tags)\n";
    }
});
