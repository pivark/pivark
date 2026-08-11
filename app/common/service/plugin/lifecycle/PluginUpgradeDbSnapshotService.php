<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\lifecycle;

use app\common\service\plugin\PluginService;
use app\common\service\infra\BackupService;
use app\common\support\AppTime;
use app\common\support\DbTable;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;
use think\facade\Db;

/** 插件升级前业务表快照：mysqldump SQL 优先 · JSON 兜底 */
final class PluginUpgradeDbSnapshotService
{
    public function __construct(
        private readonly PluginService $pluginService,
        private readonly BackupService $backup,
    ) {
    }

    /**
     * @return string|null 快照文件名（不含目录）
     */
    public function snapshotBeforeUpgrade(string $identifier): ?string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }

        $tables = $this->pluginService->discoverPluginDataTables($identifier);
        if ($tables === []) {
            return null;
        }

        $pfx  = (string) config('database.connections.mysql.prefix');
        $max  = max(1000, (int) config('plugin.market.upgrade_table_snapshot_max_rows', 10000));
        $dump = ['identifier' => $identifier, 'tables' => [], 'truncated_tables' => [], 'created_at' => AppTime::format('c')];

        foreach ($tables as $table) {
            $logical = $table;
            $full    = str_starts_with($table, $pfx) ? $table : $pfx . $table;
            try {
                $countRow = Db::query('SELECT COUNT(*) AS c FROM `' . str_replace('`', '``', $full) . '`');
                $total    = is_array($countRow[0] ?? null) ? (int) ($countRow[0]['c'] ?? 0) : 0;
                $rows     = Db::query(
                    'SELECT * FROM `' . str_replace('`', '``', $full) . '` LIMIT ' . $max
                );
                $dump['tables'][$logical] = is_array($rows) ? $rows : [];
                if ($total > $max) {
                    $dump['truncated_tables'][] = $logical;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($dump['tables'] === []) {
            return null;
        }

        $filename = $identifier . '-tables-' . AppTime::format('YmdHis') . '.json';
        $path     = $this->snapshotRoot() . DIRECTORY_SEPARATOR . $filename;
        if (!LocalFile::putContents($path, json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
            return null;
        }

        return $filename;
    }

    /**
     * @return string|null mysqldump SQL 文件名（不含目录）
     */
    public function snapshotSqlBeforeUpgrade(string $identifier): ?string
    {
        if (!(bool) config('plugin.market.upgrade_sql_snapshot_enabled', true)) {
            return null;
        }

        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }

        $tables = $this->pluginService->discoverPluginDataTables($identifier);
        if ($tables === []) {
            return null;
        }

        $pfx      = (string) config('database.connections.mysql.prefix');
        $physical = [];
        foreach ($tables as $table) {
            $full = str_starts_with($table, $pfx) ? $table : DbTable::name($table);
            $physical[] = DbTable::assertPhysicalTableName($full, $pfx);
        }

        $filename = $identifier . '-tables-' . AppTime::format('YmdHis') . '.sql';
        $path     = $this->sqlSnapshotRoot() . DIRECTORY_SEPARATOR . $filename;
        if (!$this->backup->dumpPhysicalTablesToFile($path, $physical)) {
            return null;
        }

        return $filename;
    }

    public function restoreSqlSnapshot(string $identifier, string $filename): bool
    {
        $identifier = strtolower(trim($identifier));
        $filename   = basename(trim($filename));
        if ($identifier === '' || $filename === '' || !str_starts_with($filename, $identifier . '-tables-')) {
            return false;
        }

        $path = $this->sqlSnapshotRoot() . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            return false;
        }

        return $this->backup->restoreRuntimeSqlSnapshot($path)->isOk();
    }

    /** SQL 优先，JSON 兜底 */
    public function restoreBestEffort(string $identifier, ?string $sqlFilename, ?string $jsonFilename): bool
    {
        if ($sqlFilename !== null && $this->restoreSqlSnapshot($identifier, $sqlFilename)) {
            return true;
        }
        if ($jsonFilename !== null) {
            return $this->restoreSnapshot($identifier, $jsonFilename);
        }

        return false;
    }

    public function restoreSnapshot(string $identifier, string $filename): bool
    {
        $identifier = strtolower(trim($identifier));
        $filename   = basename(trim($filename));
        if ($identifier === '' || $filename === '' || !str_starts_with($filename, $identifier . '-tables-')) {
            return false;
        }

        $path = $this->snapshotRoot() . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            return false;
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || ($json['identifier'] ?? '') !== $identifier || !is_array($json['tables'] ?? null)) {
            return false;
        }

        $pfx = (string) config('database.connections.mysql.prefix');
        try {
            Db::transaction(function () use ($json, $identifier, $pfx): void {
                foreach ($json['tables'] as $logical => $rows) {
                    if (!is_string($logical) || !is_array($rows)) {
                        continue;
                    }
                    $full = str_starts_with($logical, $pfx) ? $logical : $pfx . $logical;
                    Db::execute('DELETE FROM `' . str_replace('`', '``', $full) . '`');
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        Db::name($logical)->insert($row);
                    }
                }
            });
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function snapshotRoot(): string
    {
        $dir = rtrim(ProjectPaths::runtimeDir(), '/\\') . DIRECTORY_SEPARATOR . 'plugin_table_snapshots';
        if (!is_dir($dir)) {
            LocalFile::mkdirIfMissing($dir);
        }

        return $dir;
    }

    private function sqlSnapshotRoot(): string
    {
        $dir = rtrim(ProjectPaths::runtimeDir(), '/\\') . DIRECTORY_SEPARATOR . 'plugin_sql_snapshots';
        if (!is_dir($dir)) {
            LocalFile::mkdirIfMissing($dir);
        }

        return $dir;
    }
}
