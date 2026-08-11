<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * Community 演示：主品项配件关联（可重复执行，按 parent code 覆盖）
 * 用法: php install/assets/seed/seed_community_demo_item_relations.php [--force]
 */
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    require dirname(__DIR__, 3) . '/app/bootstrap/cli.php';
    pivark_app();
}

use app\common\model\ProductItemRelation;
use app\common\support\AppTime;
use app\common\support\DbTable;
use think\facade\Db;

/** 向导 include 时禁止 exit，避免掐断 /install/runStep JSON */
$seedExit = static function (int $code): void {
    if (\defined('PIVARK_INSTALL_SEED_INCLUDE') && \constant('PIVARK_INSTALL_SEED_INCLUDE')) {
        if ($code !== 0) {
            throw new \RuntimeException('seed_community_demo_item_relations exit ' . $code);
        }

        return;
    }
    exit($code);
};

$force = in_array('--force', $argv ?? [], true);
$dataDir = __DIR__ . '/data';
$dataFile = $dataDir . '/demo_product_item_relations.php';

echo "=== seed_community_demo_item_relations ===\n";

if (!DbTable::modelExists(ProductItemRelation::class)) {
    echo "FAIL product_item_relations table missing — run migrate_product_item_relations first\n";
    $seedExit(1);
    return;
}

if (!is_file($dataFile)) {
    echo "FAIL missing {$dataFile}\n";
    $seedExit(1);
    return;
}

$existing = (int) ProductItemRelation::count();
if ($existing > 0 && !$force) {
    echo "SKIP relations exist ({$existing}); use --force to rebuild\n";
    $seedExit(0);
    return;
}

/** @var list<array<string, mixed>> $rows */
$rows = require $dataFile;
if (!is_array($rows) || $rows === []) {
    echo "FAIL demo_product_item_relations empty\n";
    $seedExit(1);
    return;
}

$codeToId = static function (string $code): int {
    $code = trim($code);
    if ($code === '') {
        return 0;
    }

    return (int) Db::name('items')->where('code', $code)->value('id');
};

$byParent = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $parentCode = trim((string) ($row['parent'] ?? ''));
    $childCode  = trim((string) ($row['child'] ?? ''));
    if ($parentCode === '' || $childCode === '') {
        continue;
    }
    $byParent[$parentCode][] = $row;
}

$now = AppTime::now();
$parents = 0;
$links   = 0;

Db::startTrans();
try {
    foreach ($byParent as $parentCode => $childRows) {
        $parentId = $codeToId($parentCode);
        if ($parentId < 1) {
            echo "  WARN parent missing: {$parentCode}\n";
            continue;
        }
        ProductItemRelation::where('parent_item_id', $parentId)->delete();
        $sort = 0;
        foreach ($childRows as $row) {
            $childId = $codeToId(trim((string) ($row['child'] ?? '')));
            if ($childId < 1 || $childId === $parentId) {
                echo "  WARN child missing: {$parentCode} -> " . ($row['child'] ?? '') . "\n";
                continue;
            }
            $type = strtolower(trim((string) ($row['relation_type'] ?? 'accessory')));
            if (!in_array($type, ['accessory', 'spare', 'component'], true)) {
                $type = 'accessory';
            }
            ProductItemRelation::insert([
                'parent_item_id' => $parentId,
                'child_item_id'  => $childId,
                'relation_type'  => $type,
                'note'           => mb_substr(trim((string) ($row['note'] ?? '')), 0, 200),
                'qty'            => max(0.001, (float) ($row['qty'] ?? 1)),
                'sort'           => (int) ($row['sort'] ?? $sort),
                'created_at'     => $now,
            ]);
            $sort++;
            $links++;
        }
        $parents++;
    }
    Db::commit();
} catch (\Throwable $e) {
    Db::rollback();
    echo 'FAIL ' . $e->getMessage() . "\n";
    $seedExit(1);
    return;
}

echo "  parents={$parents} links={$links}\n";
echo "=== seed_community_demo_item_relations done ===\n";
