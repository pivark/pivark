<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\plugin\security\PluginSqlGuard;
use think\facade\Db;

/**
 * 插件 database/*.sql 批量执行（跳过 SELECT）。
 *
 * 故意保留裸 Db::execute：install/uninstall/升级 DDL 无稳定 Model 映射；
 * 插件 database/*Migration.php 内 CREATE/ALTER 同理，勿强行 ORM 化。
 */
final class PluginSqlRunner
{
    public static function executeBatch(string $sql, string $pluginIdentifier = ''): void
    {
        $guard      = $pluginIdentifier !== '' ? app(PluginSqlGuard::class) : null;
        $statements = self::statements($sql);
        if ($statements === []) {
            return;
        }

        Db::startTrans();
        try {
            foreach ($statements as $stmt) {
                self::assertSafeStatement($stmt);
                if ($guard !== null) {
                    $guard->assertSafe($stmt, $pluginIdentifier);
                }
                Db::execute($stmt);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt === '' || preg_match('/^SELECT\s/i', $stmt)) {
                continue;
            }
            $out[] = $stmt;
        }

        return $out;
    }

    private static function assertSafeStatement(string $stmt): void
    {
        if (preg_match('/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE\s*\()\b/i', $stmt)) {
            throw new \RuntimeException('Blocked unsafe SQL in plugin batch');
        }
    }
}
