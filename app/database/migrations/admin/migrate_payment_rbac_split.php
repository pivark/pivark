<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 支付 RBAC 拆码：plugin.payment.use / .config / .refund
 * - 已有 .use 的角色自动补授 .config + .refund（行为不回退）
 * - ops_finance 由 refresh_ops_audit_roles 单独授 .use
 *
 * 用法: php app/database/migrations/admin/migrate_payment_rbac_split.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_migration_bootstrap.php';

$name = 'migrate_payment_rbac_split';

migration_entry($name, static function (PDO $pdo, string $pfx, string $db): void {
    $permTable = "{$pfx}permissions";
    $rpTable   = "{$pfx}role_permissions";

    $useId = (int) $pdo->query(
        "SELECT id FROM `{$permTable}` WHERE code=" . $pdo->quote('plugin.payment.use') . ' AND status=1 LIMIT 1'
    )->fetchColumn();
    if ($useId < 1) {
        $ins = $pdo->prepare(
            "INSERT INTO `{$permTable}` (`name`,`code`,`parent_id`,`module`,`icon`,`sort`,`status`,`created_at`)
             VALUES ('支付 · 使用','plugin.payment.use',NULL,'plugin',NULL,200,1,NOW())"
        );
        $ins->execute();
        $useId = (int) $pdo->lastInsertId();
        echo "  insert plugin.payment.use (#{$useId})\n";
    } else {
        echo "  exists plugin.payment.use (#{$useId})\n";
    }

    $defs = [
        ['支付 · 配置', 'plugin.payment.config', 201],
        ['支付 · 退款关单', 'plugin.payment.refund', 202],
    ];
    $newIds = [];
    foreach ($defs as [$label, $code, $sort]) {
        $id = (int) $pdo->query(
            "SELECT id FROM `{$permTable}` WHERE code=" . $pdo->quote($code) . ' LIMIT 1'
        )->fetchColumn();
        if ($id < 1) {
            $ins = $pdo->prepare(
                "INSERT INTO `{$permTable}` (`name`,`code`,`parent_id`,`module`,`icon`,`sort`,`status`,`created_at`)
                 VALUES (?,?,NULL,'plugin',NULL,?,1,NOW())"
            );
            $ins->execute([$label, $code, $sort]);
            $id = (int) $pdo->lastInsertId();
            echo "  insert {$code} (#{$id})\n";
        } else {
            $pdo->exec(
                "UPDATE `{$permTable}` SET `name`=" . $pdo->quote($label)
                . ', `status`=1, `module`=\'plugin\' WHERE id=' . $id
            );
            echo "  exists {$code} (#{$id})\n";
        }
        $newIds[$code] = $id;
    }

    // 凡持有 .use 的角色补授 config+refund（超管/原支付岗不回退）
    $roleIds = $pdo->query(
        "SELECT DISTINCT role_id FROM `{$rpTable}` WHERE permission_id={$useId}"
    )->fetchAll(PDO::FETCH_COLUMN);
    $roleIds = array_map('intval', $roleIds);
    // 角色 1/2 保底
    foreach ([1, 2] as $rid) {
        if (!in_array($rid, $roleIds, true)) {
            $roleIds[] = $rid;
        }
    }

    foreach ($roleIds as $roleId) {
        if ($roleId < 1) {
            continue;
        }
        foreach ($newIds as $code => $pid) {
            $pdo->exec(
                "INSERT IGNORE INTO `{$rpTable}` (role_id, permission_id, created_at)
                 VALUES ({$roleId}, {$pid}, NOW())"
            );
        }
        // 确保仍有 .use
        $pdo->exec(
            "INSERT IGNORE INTO `{$rpTable}` (role_id, permission_id, created_at)
             VALUES ({$roleId}, {$useId}, NOW())"
        );
        echo "  role #{$roleId} granted use+config+refund\n";
    }
}, static function (PDO $pdo, string $pfx, string $db): void {
    migration_remove_permission($pdo, $pfx, 'plugin.payment.config');
    migration_remove_permission($pdo, $pfx, 'plugin.payment.refund');
});
