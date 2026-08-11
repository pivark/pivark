<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\support\AppTime;

use app\common\support\ServiceResult;


use app\common\service\audit\AuditLogService;
use app\common\service\site\SiteModeService;
use app\common\support\LocalFile;
use app\common\support\DbTable;
use app\common\support\ProjectPaths;
use app\common\support\TrustedShellRunner;
/** 数据库与 uploads 备份 */
class BackupService
{
    /** job 每拍最多导出行数（大表分拍，避免整表卡死撞幂等） */
    private const BACKUP_ROW_CHUNK = 500;

    /**
     * 恢复每拍最多推进的「原 SQL 语句」数（安全上限）。
     * 真节流靠 RESTORE_TICK_BUDGET_MS：行级 INSERT 若每拍只跑个位数，
     * 十几万语句会变成上万次 HTTP，弱网必断。
     */
    private const RESTORE_STMT_CHUNK = 800;

    /** 多值 INSERT 切块后每小批行数（弱机/慢盘友好） */
    private const RESTORE_INSERT_ROW_CHUNK = 80;

    /** 恢复每拍时间预算（毫秒）：到点落盘返回，避免网关/浏览器掐断 */
    private const RESTORE_TICK_BUDGET_MS = 10_000;

    /** job 进度落盘间隔（语句数）；每拍结束必写（避免每句刷盘） */
    private const RESTORE_JOB_CHECKPOINT_EVERY = 50;

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly SiteModeService $siteModeService,
    ) {
    }

    private const BACKUP_NAME_PATTERN = '/^(db|uploads)_\d{8}_\d{6}\.(sql|zip)$/';

    /**
     * @return mixed
     */
    public function storageDir(): string
    {
        $configured = trim((string) env('BACKUP_STORAGE_DIR', ''));
        $dir        = $configured !== ''
            ? rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured), DIRECTORY_SEPARATOR)
            : ROOT_PATH . 'data' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建备份目录: ' . $dir);
        }

        return $dir;
    }

    /**
     * @return mixed
     * @param mixed $name
     */
    public function isAllowedBackupName(string $name): bool
    {
        $name = basename($name);

        return $name !== '' && (bool) preg_match(self::BACKUP_NAME_PATTERN, $name);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listBackups(): array
    {
        $dir = $this->storageDir();
        $out = [];
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            if (!$this->isAllowedBackupName($name)) {
                continue;
            }
            $mtime = AppTime::format('Y-m-d H:i:s', (int) filemtime($path));
            $out[] = [
                'name'       => $name,
                'size'       => (int) filesize($path),
                'size_text'  => $this->formatBytes((int) filesize($path)),
                'mtime'      => $mtime,
                'created_at' => $mtime,
                'kind'       => str_starts_with($name, 'db_') ? 'database' : 'uploads',
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($b['mtime'], $a['mtime']));

        return $out;
    }

    /**
     * 列出当前连接库全部物理表（不限站点前缀；备份默认范围=全库）。
     *
     * @return array{database:string,prefix:string,scope:string,list:list<array<string,mixed>>,total:int}
     */
    public function listDatabaseTables(): array
    {
        $cfg  = config('database.connections.mysql');
        $pfx  = (string) ($cfg['prefix'] ?? 'pv_');
        $list = DbTable::listPhysicalTableMeta(null);

        return [
            'database' => (string) ($cfg['database'] ?? ''),
            'prefix'   => $pfx,
            'scope'    => 'database',
            'list'     => $list,
            'total'    => count($list),
        ];
    }

    /**
     * @param list<string>       $types  database, uploads
     * @param list<string>|null $tables 物理表名；空则导出全部允许表
     * @return ServiceResult
     */
    public function create(array $types = ['database', 'uploads'], ?array $tables = null): ServiceResult
    {
        $types = array_values(array_intersect($types, ['database', 'uploads']));
        if ($types === []) {
            return ServiceResult::fail('请选择备份类型');
        }
        $stamp       = AppTime::format('Ymd_His');
        $dir         = $this->storageDir();
        $files       = [];
        $partials    = [];
        $tableCount  = 0;

        try {
            if (in_array('database', $types, true)) {
                $resolved   = $this->resolveBackupTables($tables);
                $tableCount = count($resolved);
                $sql        = $dir . DIRECTORY_SEPARATOR . "db_{$stamp}.sql";
                $sqlPartial = $sql . '.partial';
                $partials[] = $sqlPartial;
                $this->dumpDatabase($sqlPartial, $resolved);
                if (!rename($sqlPartial, $sql)) {
                    throw new \RuntimeException('无法落盘数据库备份');
                }
                if (!is_file($sql) || filesize($sql) < 32) {
                    LocalFile::unlinkIfExists($sql);
                    throw new \RuntimeException('数据库备份文件无效或为空');
                }
                $partials = array_values(array_filter($partials, static fn (string $p): bool => $p !== $sqlPartial));
                $this->writeBackupSignature($sql);
                $files[] = $sql;
            }
            if (in_array('uploads', $types, true)) {
                $zip        = $dir . DIRECTORY_SEPARATOR . "uploads_{$stamp}.zip";
                $zipPartial = $zip . '.partial';
                $partials[] = $zipPartial;
                $this->zipUploads($zipPartial);
                if (!rename($zipPartial, $zip)) {
                    throw new \RuntimeException('无法落盘 uploads 备份');
                }
                if (!is_file($zip) || filesize($zip) < 32) {
                    LocalFile::unlinkIfExists($zip);
                    throw new \RuntimeException('uploads 备份文件无效或为空');
                }
                $partials = array_values(array_filter($partials, static fn (string $p): bool => $p !== $zipPartial));
                $files[] = $zip;
            }
        } catch (\Throwable $e) {
            foreach ($partials as $partial) {
                LocalFile::unlinkIfExists($partial);
            }
            foreach ($files as $done) {
                LocalFile::unlinkIfExists($done);
                LocalFile::unlinkIfExists($done . '.sig');
            }

            return ServiceResult::fail($e->getMessage());
        }

        if ($files === []) {
            return ServiceResult::fail('备份未生成任何文件');
        }

        $this->auditLogService->operate('创建备份', 'admin.backup', [
            'types'       => $types,
            'table_count' => $tableCount,
            'files'       => array_map('basename', $files),
        ]);

        return ServiceResult::ok(['files' => array_map('basename', $files)], '备份完成');
    }

    /**
     * 启动可进度备份任务（database / uploads）。
     *
     * @param list<string>|null $tables
     */
    public function startJob(string $type, ?array $tables = null): ServiceResult
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['database', 'uploads'], true)) {
            return ServiceResult::fail('请选择备份类型');
        }

        try {
            if ($type === 'uploads') {
                return $this->startUploadsJob();
            }

            return $this->startDatabaseBackupJob($tables);
        } catch (\Throwable $e) {
            return ServiceResult::fail($e->getMessage());
        }
    }

    /**
     * 推进备份/恢复任务一步。
     * $expectedCursor 须与上次返回的 cursor 一致（CAS）：既防重复推进，又让每拍 POST body 唯一，不撞短时防重复指纹。
     */
    public function tickJob(string $jobId, int $batchSize = 3, ?int $expectedCursor = null): ServiceResult
    {
        $jobId = $this->normalizeJobId($jobId);
        if ($jobId === '') {
            return ServiceResult::fail('任务 ID 无效');
        }
        if ($expectedCursor === null) {
            return ServiceResult::fail('缺少进度游标 cursor');
        }

        $lockPath = $this->jobPath($jobId) . '.lock';
        $lockFh   = fopen($lockPath, 'c+');
        if ($lockFh === false) {
            return ServiceResult::fail('无法锁定备份任务');
        }
        if (!flock($lockFh, LOCK_EX)) {
            fclose($lockFh);

            return ServiceResult::fail('备份任务忙碌，请稍后重试');
        }

        try {
            $job = $this->readJob($jobId);
            if ($job === null) {
                return ServiceResult::fail('任务不存在或已过期');
            }
            if (($job['status'] ?? '') === 'finished') {
                return ServiceResult::ok($this->publicJobView($job), '已完成');
            }
            if (($job['status'] ?? '') === 'failed') {
                return ServiceResult::fail((string) ($job['error'] ?? '任务已失败'), data: $this->publicJobView($job));
            }
            if (($job['status'] ?? '') === 'cancelled') {
                return ServiceResult::fail('任务已取消', data: $this->publicJobView($job));
            }

            $jobCursor = $this->jobProgressCursor($job);
            if ($expectedCursor < $jobCursor) {
                // 同 cursor 重试：上一拍已落盘，直接回当前视图（幂等）
                return ServiceResult::ok($this->publicJobView($job), '进行中');
            }
            if ($expectedCursor > $jobCursor) {
                return ServiceResult::fail('进度不同步，请刷新后重试', data: $this->publicJobView($job));
            }

            $batchSize = max(1, min(20, $batchSize));
            $kind      = (string) ($job['kind'] ?? '');

            try {
                if ($kind === 'database_backup') {
                    $job = $this->tickDatabaseBackupJob($job, $batchSize);
                } elseif ($kind === 'uploads_backup') {
                    $job = $this->tickUploadsBackupJob($job);
                } elseif ($kind === 'database_restore') {
                    $job = $this->tickDatabaseRestoreJob($job, self::RESTORE_STMT_CHUNK);
                } elseif ($kind === 'uploads_restore') {
                    $job = $this->tickUploadsRestoreJob($job);
                } else {
                    return ServiceResult::fail('未知任务类型');
                }
            } catch (\Throwable $e) {
                $job['status']     = 'failed';
                $job['error']      = $e->getMessage();
                $job['updated_at'] = AppTime::format('c');
                $this->writeJob($job);
                $this->cleanupJobPartials($job);

                return ServiceResult::fail($e->getMessage(), data: $this->publicJobView($job));
            }

            $this->writeJob($job);
            $view = $this->publicJobView($job);
            if (($job['status'] ?? '') === 'failed') {
                return ServiceResult::fail((string) ($job['error'] ?? '任务失败'), data: $view);
            }

            return ServiceResult::ok($view, ($job['status'] ?? '') === 'finished' ? '已完成' : '进行中');
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }

    public function getJob(string $jobId): ServiceResult
    {
        $jobId = $this->normalizeJobId($jobId);
        $job   = $jobId !== '' ? $this->readJob($jobId) : null;
        if ($job === null) {
            return ServiceResult::fail('任务不存在或已过期');
        }

        return ServiceResult::ok($this->publicJobView($job));
    }

    public function cancelJob(string $jobId): ServiceResult
    {
        $jobId = $this->normalizeJobId($jobId);
        $job   = $jobId !== '' ? $this->readJob($jobId) : null;
        if ($job === null) {
            return ServiceResult::fail('任务不存在或已过期');
        }
        if (in_array((string) ($job['status'] ?? ''), ['finished', 'failed', 'cancelled'], true)) {
            return ServiceResult::ok($this->publicJobView($job), '任务已结束');
        }
        $job['status']     = 'cancelled';
        $job['updated_at'] = AppTime::format('c');
        $this->writeJob($job);
        $this->cleanupJobPartials($job);

        return ServiceResult::ok($this->publicJobView($job), '已取消');
    }

    /** 启动数据库恢复任务（按 SQL 语句分批） */
    public function startRestoreJob(string $name): ServiceResult
    {
        $name = basename(trim($name));
        if (str_starts_with($name, 'uploads_')) {
            return $this->startUploadsRestoreJob($name);
        }
        if (!str_starts_with($name, 'db_') || !str_ends_with(strtolower($name), '.sql')) {
            return ServiceResult::fail('仅支持恢复 db_*.sql 或 uploads_*.zip');
        }
        $path = $this->resolveFile($name);
        if ($path === null) {
            return ServiceResult::fail('备份文件不存在');
        }
        if (!$this->looksLikeDatabaseBackup($path)) {
            return ServiceResult::fail('备份文件内容无效或已损坏');
        }
        if (!$this->verifyBackupSignature($path)) {
            return ServiceResult::fail('备份签名校验失败，文件可能被篡改');
        }

        try {
            $total = $this->countSqlStatements($path);
            if ($total < 1) {
                return ServiceResult::fail('备份文件没有可执行语句');
            }
            $jobId = $this->newJobId();
            $job   = [
                'id'           => $jobId,
                'kind'         => 'database_restore',
                'status'       => 'running',
                'total'        => $total,
                'done'         => 0,
                'remaining'    => $total,
                'percent'      => 0,
                'current'      => '',
                'file'         => $name,
                'source_path'          => $path,
                'byte_offset'          => 0,
                'insert_tuple_offset'  => 0,
                'error'                => '',
                'started_at'           => AppTime::format('c'),
                'updated_at'           => AppTime::format('c'),
            ];
            $this->writeJob($job);

            return ServiceResult::ok($this->publicJobView($job), '恢复任务已创建');
        } catch (\Throwable $e) {
            return ServiceResult::fail($e->getMessage());
        }
    }

    /**
     * @param list<string>|null $tables
     */
    private function startDatabaseBackupJob(?array $tables): ServiceResult
    {
        $resolved = $this->resolveBackupTables($tables);
        $stamp    = AppTime::format('Ymd_His');
        $final    = 'db_' . $stamp . '.sql';
        $partial  = $this->storageDir() . DIRECTORY_SEPARATOR . $final . '.partial';
        $header   = "-- PivArk backup job " . AppTime::format('c') . "\nSET NAMES utf8mb4;\n\n";
        if (file_put_contents($partial, $header) === false) {
            throw new \RuntimeException('无法创建备份临时文件（请检查 data/backups 写权限）');
        }

        $jobId = $this->newJobId();
        $total = count($resolved);
        $job   = [
            'id'           => $jobId,
            'kind'         => 'database_backup',
            'status'       => 'running',
            'total'        => $total,
            'done'         => 0,
            'remaining'    => $total,
            'percent'      => 0,
            'current'      => $resolved[0] ?? '',
            'tables'       => $resolved,
            'table_index'  => 0,
            'phase'        => '',
            'row_total'    => 0,
            'row_done'     => 0,
            'cursor'       => 0,
            'partial_path' => $partial,
            'final_name'   => $final,
            'file'         => null,
            'size'         => 0,
            'size_text'    => '',
            'table_count'  => $total,
            'error'        => '',
            'started_at'   => AppTime::format('c'),
            'updated_at'   => AppTime::format('c'),
        ];
        $this->writeJob($job);

        return ServiceResult::ok($this->publicJobView($job), '备份任务已创建');
    }

    private function startUploadsJob(): ServiceResult
    {
        $stamp   = AppTime::format('Ymd_His');
        $final   = 'uploads_' . $stamp . '.zip';
        $partial = $this->storageDir() . DIRECTORY_SEPARATOR . $final . '.partial';
        $jobId   = $this->newJobId();
        $job     = [
            'id'           => $jobId,
            'kind'         => 'uploads_backup',
            'status'       => 'running',
            'total'        => 1,
            'done'         => 0,
            'remaining'    => 1,
            'percent'      => 0,
            'current'      => 'uploads',
            'partial_path' => $partial,
            'final_name'   => $final,
            'file'         => null,
            'size'         => 0,
            'size_text'    => '',
            'error'        => '',
            'started_at'   => AppTime::format('c'),
            'updated_at'   => AppTime::format('c'),
        ];
        $this->writeJob($job);

        return ServiceResult::ok($this->publicJobView($job), 'uploads 备份任务已创建');
    }

    private function startUploadsRestoreJob(string $name): ServiceResult
    {
        $path = $this->resolveFile($name);
        if ($path === null) {
            return ServiceResult::fail('备份文件不存在');
        }
        $jobId = $this->newJobId();
        $job   = [
            'id'         => $jobId,
            'kind'       => 'uploads_restore',
            'status'     => 'running',
            'total'      => 1,
            'done'       => 0,
            'remaining'  => 1,
            'percent'    => 0,
            'current'    => $name,
            'file'       => $name,
            'source_path'=> $path,
            'error'      => '',
            'started_at' => AppTime::format('c'),
            'updated_at' => AppTime::format('c'),
        ];
        $this->writeJob($job);

        return ServiceResult::ok($this->publicJobView($job), 'uploads 恢复任务已创建');
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function tickDatabaseBackupJob(array $job, int $batchSize): array
    {
        /** @var list<string> $tables */
        $tables = array_values(array_filter(
            array_map(static fn ($t) => is_scalar($t) ? trim((string) $t) : '', (array) ($job['tables'] ?? [])),
            static fn (string $t): bool => $t !== '',
        ));
        $partial = (string) ($job['partial_path'] ?? '');
        if ($partial === '' || !is_file($partial)) {
            throw new \RuntimeException('备份临时文件丢失');
        }
        if ($tables === []) {
            throw new \RuntimeException('没有可备份的表');
        }

        $chunk = self::BACKUP_ROW_CHUNK;
        $tableIndex = max(0, (int) ($job['table_index'] ?? 0));

        if ($tableIndex >= count($tables)) {
            return $this->finalizeDatabaseBackupJob($job, $tables);
        }

        $table = $tables[$tableIndex];
        $job['current'] = $table;

        if ((string) ($job['phase'] ?? '') !== 'rows') {
            $job['row_total'] = $this->countTableRows($table);
            $job['row_done']  = 0;
            $this->appendTableStructure($partial, $table);
            $job['phase'] = 'rows';
        }

        if ((int) ($job['row_total'] ?? 0) > 0 && (int) ($job['row_done'] ?? 0) < (int) $job['row_total']) {
            $job = $this->appendTableRowsChunk($job, $partial, $table, $chunk);
        }

        if ((int) ($job['row_done'] ?? 0) >= (int) ($job['row_total'] ?? 0)) {
            $job['table_index'] = $tableIndex + 1;
            $job['phase']       = '';
            $job['row_total']   = 0;
            $job['row_done']    = 0;
            $job['done']        = $tableIndex + 1;
            if ($job['table_index'] < count($tables)) {
                $job['current'] = $tables[$job['table_index']];
            } else {
                $job['current'] = '';
            }
        }

        $job['cursor']     = (int) ($job['cursor'] ?? 0) + 1;
        $job['updated_at'] = AppTime::format('c');
        $job               = $this->refreshDatabaseBackupProgress($job, $tables);

        if ((int) ($job['table_index'] ?? 0) >= count($tables)) {
            return $this->finalizeDatabaseBackupJob($job, $tables);
        }

        return $job;
    }

    /**
     * @param array<string, mixed> $job
     * @param list<string> $tables
     * @return array<string, mixed>
     */
    private function finalizeDatabaseBackupJob(array $job, array $tables): array
    {
        $partial   = (string) ($job['partial_path'] ?? '');
        $finalName = (string) ($job['final_name'] ?? '');
        $finalPath = $this->storageDir() . DIRECTORY_SEPARATOR . $finalName;
        if ($finalName === '' || $partial === '' || !is_file($partial) || !rename($partial, $finalPath)) {
            throw new \RuntimeException('无法落盘数据库备份');
        }
        if (!is_file($finalPath) || filesize($finalPath) < 32) {
            LocalFile::unlinkIfExists($finalPath);
            throw new \RuntimeException('数据库备份文件无效或为空');
        }
        $this->writeBackupSignature($finalPath);
        $size = (int) filesize($finalPath);
        $job['status']       = 'finished';
        $job['file']         = $finalName;
        $job['size']         = $size;
        $job['size_text']    = $this->formatBytes($size);
        $job['percent']      = 100;
        $job['done']         = count($tables);
        $job['remaining']    = 0;
        $job['current']      = '';
        $job['row_total']    = 0;
        $job['row_done']     = 0;
        $job['partial_path'] = '';
        $job['updated_at']   = AppTime::format('c');
        $this->auditLogService->operate('创建备份', 'admin.backup', [
            'types'       => ['database'],
            'table_count' => count($tables),
            'files'       => [$finalName],
            'job_id'      => (string) ($job['id'] ?? ''),
        ]);

        return $job;
    }

    /**
     * @param array<string, mixed> $job
     * @param list<string> $tables
     * @return array<string, mixed>
     */
    private function refreshDatabaseBackupProgress(array $job, array $tables): array
    {
        $tableTotal = count($tables);
        $tableIndex = max(0, min($tableTotal, (int) ($job['table_index'] ?? 0)));
        $rowTotal   = max(0, (int) ($job['row_total'] ?? 0));
        $rowDone    = max(0, min($rowTotal, (int) ($job['row_done'] ?? 0)));
        $job['done']      = $tableIndex;
        $job['remaining'] = max(0, $tableTotal - $tableIndex);
        $job['total']     = $tableTotal;
        if ($tableTotal <= 0) {
            $job['percent'] = 100;
        } else {
            $frac = $rowTotal > 0 ? ($rowDone / $rowTotal) : 0.0;
            $job['percent'] = (int) min(99, floor((($tableIndex + $frac) * 100) / $tableTotal));
        }

        return $job;
    }

    private function countTableRows(string $table): int
    {
        $table   = DbTable::assertPhysicalTableName($table, null);
        $quoted  = '`' . str_replace('`', '``', $table) . '`';
        $rows    = \think\facade\Db::query('SELECT COUNT(*) AS c FROM ' . $quoted);
        $count   = (int) ($rows[0]['c'] ?? 0);

        return max(0, $count);
    }

    private function appendTableStructure(string $targetFile, string $table): void
    {
        $table  = DbTable::assertPhysicalTableName($table, null);
        $create = \think\facade\Db::query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
        $sql    = 'DROP TABLE IF EXISTS `' . $table . "`;\n"
            . (string) ($create[0]['Create Table'] ?? '') . ";\n\n";
        if (file_put_contents($targetFile, $sql, FILE_APPEND) === false) {
            throw new \RuntimeException('无法写入表结构 ' . $table);
        }
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function appendTableRowsChunk(array $job, string $targetFile, string $table, int $chunk): array
    {
        $pdo      = \think\facade\Db::connect()->getPdo();
        $table    = DbTable::assertPhysicalTableName($table, null);
        $quoted   = '`' . str_replace('`', '``', $table) . '`';
        $offset   = max(0, (int) ($job['row_done'] ?? 0));
        $chunk    = max(1, $chunk);
        $rows     = \think\facade\Db::query(
            'SELECT * FROM ' . $quoted . ' LIMIT ' . $chunk . ' OFFSET ' . $offset
        );
        if ($rows === []) {
            $job['row_done'] = (int) ($job['row_total'] ?? $offset);

            return $job;
        }
        $lines = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $cols = array_map(
                fn ($v) => $this->sqlValueLiteral($pdo, $v),
                array_values($r)
            );
            $lines[] = 'INSERT INTO ' . $quoted . ' VALUES (' . implode(',', $cols) . ');';
        }
        if ($lines !== [] && file_put_contents($targetFile, implode("\n", $lines) . "\n", FILE_APPEND) === false) {
            throw new \RuntimeException('无法写入表数据 ' . $table);
        }
        $job['row_done'] = $offset + count($rows);

        return $job;
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function tickUploadsBackupJob(array $job): array
    {
        $partial = (string) ($job['partial_path'] ?? '');
        $final   = (string) ($job['final_name'] ?? '');
        if ($partial === '' || $final === '') {
            throw new \RuntimeException('uploads 任务参数无效');
        }
        $job['current'] = 'uploads';
        $this->zipUploads($partial);
        $finalPath = $this->storageDir() . DIRECTORY_SEPARATOR . $final;
        if (!rename($partial, $finalPath)) {
            throw new \RuntimeException('无法落盘 uploads 备份');
        }
        if (!is_file($finalPath) || filesize($finalPath) < 32) {
            LocalFile::unlinkIfExists($finalPath);
            throw new \RuntimeException('uploads 备份文件无效或为空');
        }
        $size = (int) filesize($finalPath);
        $job['status']       = 'finished';
        $job['done']         = 1;
        $job['remaining']    = 0;
        $job['percent']      = 100;
        $job['file']         = $final;
        $job['size']         = $size;
        $job['size_text']    = $this->formatBytes($size);
        $job['current']      = '';
        $job['partial_path'] = '';
        $job['cursor']       = (int) ($job['cursor'] ?? 0) + 1;
        $job['updated_at']   = AppTime::format('c');
        $this->auditLogService->operate('创建备份', 'admin.backup', [
            'types'  => ['uploads'],
            'files'  => [$final],
            'job_id' => (string) ($job['id'] ?? ''),
        ]);

        return $job;
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function tickDatabaseRestoreJob(array $job, int $_batchSize = 0): array
    {
        ignore_user_abort(true);
        @set_time_limit(180);

        $path   = (string) ($job['source_path'] ?? '');
        $offset = max(0, (int) ($job['byte_offset'] ?? 0));
        $tupleOffset = max(0, (int) ($job['insert_tuple_offset'] ?? 0));
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('备份文件不存在');
        }

        // 忽略前端 batch_size；以时间预算+高上限推进，避免「一拍几句 → 上万次 HTTP」
        $stmtCap = self::RESTORE_STMT_CHUNK;
        $startedMs = (int) floor(hrtime(true) / 1_000_000);
        $stmtsDoneThisTick = 0;
        $sinceCheckpoint = 0;
        $eof = false;

        $checkpoint = function () use (&$job, &$offset, &$tupleOffset, &$sinceCheckpoint): void {
            $job['byte_offset'] = $offset;
            $job['insert_tuple_offset'] = $tupleOffset;
            $this->refreshDatabaseRestoreProgress($job);
            $this->writeJob($job);
            $sinceCheckpoint = 0;
        };

        while (true) {
            $elapsed = (int) floor(hrtime(true) / 1_000_000) - $startedMs;
            if ($stmtsDoneThisTick > 0 && $elapsed >= self::RESTORE_TICK_BUDGET_MS) {
                break;
            }
            if ($stmtsDoneThisTick >= $stmtCap) {
                break;
            }

            $batch = $this->readSqlStatementsFromOffset($path, $offset, 1);
            if ($batch['statements'] === []) {
                $eof = true;
                break;
            }

            $statement = $batch['statements'][0];
            $nextOffset = (int) $batch['next_offset'];
            $preview = mb_substr(preg_replace('/\s+/', ' ', $statement) ?? $statement, 0, 48);
            $chunks = $this->chunkMultiValueInsert($statement, self::RESTORE_INSERT_ROW_CHUNK);

            if ($chunks === null) {
                $job['current'] = $preview;
                \think\facade\Db::execute($statement);
                $offset = $nextOffset;
                $tupleOffset = 0;
                $job['done'] = (int) ($job['done'] ?? 0) + 1;
                $stmtsDoneThisTick++;
                $sinceCheckpoint++;
                $eof = (bool) $batch['eof'];
                if ($sinceCheckpoint >= self::RESTORE_JOB_CHECKPOINT_EVERY) {
                    $checkpoint();
                }
                if ($eof) {
                    break;
                }
                continue;
            }

            $totalChunks = count($chunks);
            $from = min($tupleOffset, $totalChunks);
            while ($from < $totalChunks) {
                $job['current'] = $preview . ' (' . ($from + 1) . '/' . $totalChunks . ')';
                \think\facade\Db::execute($chunks[$from]);
                $from++;
                $elapsed = (int) floor(hrtime(true) / 1_000_000) - $startedMs;
                if ($from < $totalChunks && $elapsed >= self::RESTORE_TICK_BUDGET_MS) {
                    break;
                }
            }

            if ($from >= $totalChunks) {
                $offset = $nextOffset;
                $tupleOffset = 0;
                $job['done'] = (int) ($job['done'] ?? 0) + 1;
                $stmtsDoneThisTick++;
                $sinceCheckpoint++;
                $eof = (bool) $batch['eof'];
                if ($sinceCheckpoint >= self::RESTORE_JOB_CHECKPOINT_EVERY) {
                    $checkpoint();
                }
                if ($eof) {
                    break;
                }
            } else {
                // 多值 INSERT 切块中途：字节游标停在本句，tuple 续跑
                $tupleOffset = $from;
                break;
            }
        }

        $job['byte_offset'] = $offset;
        $job['insert_tuple_offset'] = $tupleOffset;
        $job['cursor'] = (int) ($job['cursor'] ?? 0) + 1;
        $this->refreshDatabaseRestoreProgress($job);

        if ($eof && $tupleOffset === 0) {
            $job['status']    = 'finished';
            $job['percent']   = 100;
            $job['remaining'] = 0;
            $job['current']   = '';
            $this->auditLogService->operate('恢复数据库', 'admin.backup', [
                'file'   => (string) ($job['file'] ?? ''),
                'job_id' => (string) ($job['id'] ?? ''),
            ]);
        }

        $this->writeJob($job);

        return $job;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function refreshDatabaseRestoreProgress(array &$job): void
    {
        $total = max(1, (int) ($job['total'] ?? 1));
        $done  = min($total, max(0, (int) ($job['done'] ?? 0)));
        $job['done']       = $done;
        $job['remaining']  = max(0, $total - $done);
        $job['percent']    = (int) floor($done * 100 / $total);
        $job['updated_at'] = AppTime::format('c');
    }

    /**
     * 将 mysqldump 多值 INSERT 切成小批，避免单条语句卡死弱机/撞超时。
     *
     * @return list<string>|null null 表示按原语句执行
     */
    private function chunkMultiValueInsert(string $statement, int $rowsPerChunk): ?array
    {
        if (!preg_match('/^\s*INSERT\s+INTO\b/i', $statement)) {
            return null;
        }
        if (!preg_match('/^(.+?\bVALUES)\s*(.+)$/is', $statement, $m)) {
            return null;
        }
        $prefix = rtrim((string) $m[1]);
        $rest   = rtrim((string) $m[2], " \t\n\r;");
        $tuples = $this->parseSqlValueTuples($rest);
        if ($tuples === null || count($tuples) <= 1) {
            return null;
        }
        $rowsPerChunk = max(1, $rowsPerChunk);
        $out = [];
        foreach (array_chunk($tuples, $rowsPerChunk) as $group) {
            $out[] = $prefix . ' ' . implode(',', $group);
        }

        return $out;
    }

    /**
     * @return list<string>|null
     */
    private function parseSqlValueTuples(string $valuesPart): ?array
    {
        $tuples = [];
        $len    = strlen($valuesPart);
        $i      = 0;
        while ($i < $len) {
            while ($i < $len && ctype_space($valuesPart[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            if ($valuesPart[$i] !== '(') {
                return null;
            }
            $start   = $i;
            $depth   = 0;
            $inStr   = false;
            $quote   = '';
            $escape  = false;
            for (; $i < $len; $i++) {
                $ch = $valuesPart[$i];
                if ($inStr) {
                    if ($escape) {
                        $escape = false;
                        continue;
                    }
                    if ($ch === '\\') {
                        $escape = true;
                        continue;
                    }
                    if ($ch === $quote) {
                        if ($i + 1 < $len && $valuesPart[$i + 1] === $quote) {
                            $i++;
                            continue;
                        }
                        $inStr = false;
                        $quote = '';
                    }
                    continue;
                }
                if ($ch === "'" || $ch === '"') {
                    $inStr = true;
                    $quote = $ch;
                    continue;
                }
                if ($ch === '(') {
                    $depth++;
                    continue;
                }
                if ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $tuples[] = substr($valuesPart, $start, $i - $start + 1);
                        $i++;
                        break;
                    }
                }
            }
            if ($depth !== 0) {
                return null;
            }
            while ($i < $len && ctype_space($valuesPart[$i])) {
                $i++;
            }
            if ($i < $len && $valuesPart[$i] === ',') {
                $i++;
                continue;
            }
            if ($i < $len) {
                return null;
            }
        }

        return $tuples === [] ? null : $tuples;
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function tickUploadsRestoreJob(array $job): array
    {
        $name = (string) ($job['file'] ?? '');
        $res  = $this->restoreUploads($name);
        if (!$res->isOk()) {
            throw new \RuntimeException((string) ($res->message() ?? 'uploads 恢复失败'));
        }
        $job['status']     = 'finished';
        $job['done']       = 1;
        $job['remaining']  = 0;
        $job['percent']    = 100;
        $job['current']    = '';
        $job['cursor']     = (int) ($job['cursor'] ?? 0) + 1;
        $job['updated_at'] = AppTime::format('c');

        return $job;
    }

    private function appendTableDump(string $targetFile, string $table): void
    {
        $table = DbTable::assertPhysicalTableName($table, null);
        $mysqldump = $this->findMysqldump();
        if ($mysqldump !== null) {
            $tmp = $targetFile . '.' . preg_replace('/[^A-Za-z0-9_]/', '_', $table) . '.tbltmp';
            $cfg = config('database.connections.mysql');
            $db  = $this->configString($cfg['database'] ?? '');
            $dumped = TrustedShellRunner::withMysqlDefaultsExtraFile(
                $this->mysqlClientSectionFromConfig(),
                static function (string $cnfPath) use ($mysqldump, $db, $table, $tmp): bool {
                    $cmd = sprintf(
                        '%s --defaults-extra-file=%s --skip-comments --single-transaction --quick --no-tablespaces %s %s > %s',
                        escapeshellarg($mysqldump),
                        escapeshellarg($cnfPath),
                        escapeshellarg($db),
                        escapeshellarg($table),
                        escapeshellarg($tmp)
                    );

                    return TrustedShellRunner::passthru($cmd) === 0
                        && is_file($tmp)
                        && filesize($tmp) > 0;
                },
            );
            if ($dumped) {
                $chunk = (string) file_get_contents($tmp);
                LocalFile::unlinkIfExists($tmp);
                if ($chunk !== '' && file_put_contents($targetFile, $chunk . "\n", FILE_APPEND) !== false) {
                    return;
                }
            }
            LocalFile::unlinkIfExists($tmp);
        }

        $this->appendTableDumpViaPdo($targetFile, $table);
    }

    private function appendTableDumpViaPdo(string $targetFile, string $table): void
    {
        $pdo    = \think\facade\Db::connect()->getPdo();
        $table  = DbTable::assertPhysicalTableName($table, null);
        $create = \think\facade\Db::query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
        $lines  = [
            'DROP TABLE IF EXISTS `' . $table . '`;',
            (string) ($create[0]['Create Table'] ?? ''),
            '',
        ];
        $batchSize = 5000;
        $offset    = 0;
        $quoted    = '`' . str_replace('`', '``', $table) . '`';
        while (true) {
            $rows = \think\facade\Db::query(
                'SELECT * FROM ' . $quoted . ' LIMIT ' . $batchSize . ' OFFSET ' . $offset
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $cols = array_map(
                    fn ($v) => $this->sqlValueLiteral($pdo, $v),
                    array_values($r)
                );
                $lines[] = 'INSERT INTO ' . $quoted . ' VALUES (' . implode(',', $cols) . ');';
            }
            if (\count($rows) < $batchSize) {
                break;
            }
            $offset += $batchSize;
        }
        $lines[] = '';
        if (file_put_contents($targetFile, implode("\n", $lines) . "\n", FILE_APPEND) === false) {
            throw new \RuntimeException('无法写入表 ' . $table);
        }
    }

    private function countSqlStatements(string $path): int
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('无法读取备份文件');
        }
        $count = 0;
        $buf   = '';
        try {
            while (!feof($fh)) {
                $line = fgets($fh);
                if ($line === false) {
                    break;
                }
                $trim = ltrim($line);
                if ($trim === '' || str_starts_with($trim, '--')) {
                    continue;
                }
                $buf .= $line;
                if (str_contains($line, ';')) {
                    foreach ($this->splitSqlStatements($buf) as $stmt) {
                        if ($stmt !== '') {
                            $count++;
                        }
                    }
                    $buf = '';
                }
            }
            $tail = trim($buf);
            if ($tail !== '') {
                $count++;
            }
        } finally {
            fclose($fh);
        }

        return max(1, $count);
    }

    /**
     * @return array{statements:list<string>,next_offset:int,eof:bool}
     */
    private function readSqlStatementsFromOffset(string $path, int $offset, int $limit): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('无法读取备份文件');
        }
        $out = [];
        $buf = '';
        try {
            if ($offset > 0) {
                fseek($fh, $offset);
            }
            while (!feof($fh) && count($out) < $limit) {
                $line = fgets($fh);
                if ($line === false) {
                    break;
                }
                $trim = ltrim($line);
                if ($trim === '' || str_starts_with($trim, '--')) {
                    $offset = ftell($fh) ?: $offset;
                    continue;
                }
                $buf .= $line;
                if (str_contains($line, ';')) {
                    foreach ($this->splitSqlStatements($buf) as $stmt) {
                        if ($stmt === '') {
                            continue;
                        }
                        $out[] = $stmt;
                        if (count($out) >= $limit) {
                            break;
                        }
                    }
                    $buf = '';
                    $offset = ftell($fh) ?: $offset;
                }
            }
            $eof = feof($fh) && trim($buf) === '';
            if ($eof === false && trim($buf) !== '' && count($out) < $limit && feof($fh)) {
                $out[] = trim($buf);
                $eof   = true;
                $offset = filesize($path) ?: $offset;
            }
        } finally {
            fclose($fh);
        }

        return [
            'statements'  => $out,
            'next_offset' => $offset,
            'eof'         => $eof || ($out === [] && $offset >= (int) filesize($path)),
        ];
    }

    private function jobsDir(): string
    {
        $dir = rtrim(ProjectPaths::runtimeDir(), '/\\') . DIRECTORY_SEPARATOR . 'backup_jobs';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建备份任务目录');
        }

        return $dir;
    }

    private function newJobId(): string
    {
        return 'bj_' . AppTime::format('YmdHis') . '_' . bin2hex(random_bytes(4));
    }

    private function normalizeJobId(string $jobId): string
    {
        $jobId = trim($jobId);

        return preg_match('/^bj_[A-Za-z0-9_]+$/', $jobId) === 1 ? $jobId : '';
    }

    private function jobPath(string $jobId): string
    {
        return $this->jobsDir() . DIRECTORY_SEPARATOR . $jobId . '.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJob(string $jobId): ?array
    {
        $path = $this->jobPath($jobId);
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function writeJob(array $job): void
    {
        $id = $this->normalizeJobId((string) ($job['id'] ?? ''));
        if ($id === '') {
            throw new \RuntimeException('任务 ID 无效');
        }
        $job['id'] = $id;
        file_put_contents(
            $this->jobPath($id),
            json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function publicJobView(array $job): array
    {
        $kind      = (string) ($job['kind'] ?? '');
        $total     = max(0, (int) ($job['total'] ?? 0));
        $done      = max(0, (int) ($job['done'] ?? 0));
        $remaining = max(0, (int) ($job['remaining'] ?? max(0, $total - $done)));
        $percent   = max(0, min(100, (int) ($job['percent'] ?? ($total > 0 ? (int) floor($done * 100 / $total) : 0))));
        $rowTotal  = max(0, (int) ($job['row_total'] ?? 0));
        $rowDone   = max(0, min($rowTotal, (int) ($job['row_done'] ?? 0)));
        $rowRemain = max(0, $rowTotal - $rowDone);
        $current   = (string) ($job['current'] ?? '');
        $status    = (string) ($job['status'] ?? '');

        return [
            'job_id'        => (string) ($job['id'] ?? ''),
            'kind'          => $kind,
            'status'        => $status,
            'cursor'        => $this->jobProgressCursor($job),
            'total'         => $total,
            'done'          => $done,
            'remaining'     => $remaining,
            'percent'       => $percent,
            'current'       => $current,
            'row_total'     => $rowTotal,
            'row_done'      => $rowDone,
            'row_remaining' => $rowRemain,
            'status_text'   => $this->buildJobStatusText($kind, $status, $total, $done, $remaining, $percent, $current, $rowTotal, $rowDone, $rowRemain),
            'file'          => $job['file'] ?? null,
            'size'          => (int) ($job['size'] ?? 0),
            'size_text'     => (string) ($job['size_text'] ?? ''),
            'table_count'   => (int) ($job['table_count'] ?? $total),
            'error'         => (string) ($job['error'] ?? ''),
            'started_at'    => (string) ($job['started_at'] ?? ''),
            'updated_at'    => (string) ($job['updated_at'] ?? ''),
        ];
    }

    /** 客户端下一拍必须回传的进度游标（备份=步号；库恢复=字节偏移；其它=done） */
    private function jobProgressCursor(array $job): int
    {
        $kind = (string) ($job['kind'] ?? '');
        if ($kind === 'database_backup') {
            return max(0, (int) ($job['cursor'] ?? 0));
        }
        if ($kind === 'database_restore') {
            return max(0, (int) ($job['byte_offset'] ?? 0));
        }

        return max(0, (int) ($job['done'] ?? 0));
    }

    private function buildJobStatusText(
        string $kind,
        string $status,
        int $total,
        int $done,
        int $remaining,
        int $percent,
        string $current,
        int $rowTotal,
        int $rowDone,
        int $rowRemain,
    ): string {
        if ($status === 'finished') {
            return $total > 0 ? "已完成 {$total} / {$total}" : '已完成';
        }
        if ($status === 'failed') {
            return '失败';
        }
        if ($status === 'cancelled') {
            return '已取消';
        }
        if ($kind === 'database_backup') {
            $parts = ["表 {$done}/{$total}"];
            if ($current !== '') {
                $parts[] = $current;
            }
            if ($rowTotal > 0) {
                $parts[] = "行 {$rowDone}/{$rowTotal}";
                $parts[] = "剩余行 {$rowRemain}";
            }
            $parts[] = "{$percent}%";

            return implode(' · ', $parts);
        }
        if ($kind === 'database_restore') {
            $parts = ["语句 {$done}/{$total}", "剩余 {$remaining}", "{$percent}%"];
            if ($current !== '') {
                $parts[] = $current;
            }

            return implode(' · ', $parts);
        }

        return "进行中：已完成 {$done} / 共 {$total}，剩余 {$remaining}";
    }

    /**
     * @param array<string, mixed> $job
     */
    private function cleanupJobPartials(array $job): void
    {
        $partial = (string) ($job['partial_path'] ?? '');
        if ($partial !== '') {
            LocalFile::unlinkIfExists($partial);
        }
    }

    /**
     * 未指定表 → 当前库全部表；指定表 → 须属于当前库。
     *
     * @param list<string>|null $tables
     * @return list<string>
     */
    private function resolveBackupTables(?array $tables): array
    {
        $allowed = DbTable::listPhysicalTables(null);
        if ($tables === null || $tables === []) {
            if ($allowed === []) {
                throw new \InvalidArgumentException('当前库没有可备份的数据表');
            }

            return $allowed;
        }

        $allowedSet = array_flip($allowed);
        $out        = [];
        foreach ($tables as $table) {
            if (!is_scalar($table)) {
                continue;
            }
            $table = trim((string) $table);
            if ($table === '' || !isset($allowedSet[$table])) {
                continue;
            }
            $out[] = DbTable::assertPhysicalTableName($table, null);
        }
        if ($out === []) {
            throw new \InvalidArgumentException('请选择至少一张有效数据表');
        }

        return $out;
    }

    /**
     * @return mixed
     * @param mixed $name
     */
    public function resolveFile(string $name): ?string
    {
        if (!$this->isAllowedBackupName($name)) {
            return null;
        }
        $path = $this->storageDir() . DIRECTORY_SEPARATOR . basename($name);

        return is_file($path) ? $path : null;
    }

    /**
     * @return ServiceResult
     * @param mixed $name
     */
    public function delete(string $name): ServiceResult
    {
        $path = $this->resolveFile($name);
        if ($path === null) {
            return ServiceResult::fail('文件不存在');
        }
        if (!LocalFile::unlinkIfExists($path)) {
            return ServiceResult::fail('删除失败');
        }

        $this->auditLogService->operate('删除备份', 'admin.backup', ['file' => basename($path)]);

        return ServiceResult::ok(null, '已删除');
    }

    /**
     * 从本系统生成的 db_*.sql 恢复数据库（覆盖现有表数据，慎用）
     *
     * @return ServiceResult
     * @param mixed $name
     */
    public function restoreDatabase(string $name): ServiceResult
    {
        $name = basename($name);
        if (!str_starts_with($name, 'db_') || !str_ends_with(strtolower($name), '.sql')) {
            return ServiceResult::fail('仅支持恢复 db_*.sql 数据库备份');
        }
        $path = $this->resolveFile($name);
        if ($path === null) {
            return ServiceResult::fail('备份文件不存在');
        }
        if (!$this->looksLikeDatabaseBackup($path)) {
            return ServiceResult::fail('备份文件内容无效或已损坏');
        }
        if (!$this->verifyBackupSignature($path)) {
            return ServiceResult::fail('备份签名校验失败，文件可能被篡改');
        }

        try {
            if ($this->restoreViaMysqlCli($path)) {
                $this->auditLogService->operate('恢复数据库', 'admin.backup', ['file' => $name]);
                return ServiceResult::ok(null, '数据库已恢复');
            }
            $this->restoreViaPdo($path);
            $this->auditLogService->operate('恢复数据库', 'admin.backup', ['file' => $name]);
            return ServiceResult::ok(null, '数据库已恢复（PDO 模式）');
        } catch (\Throwable $e) {
            return ServiceResult::fail('恢复失败：' . $e->getMessage());
        }
    }

    /**
     * 导出指定物理表到绝对路径（插件升级快照等内部用途）
     *
     * @param list<string> $physicalTables
     */
    public function dumpPhysicalTablesToFile(string $absolutePath, array $physicalTables): bool
    {
        if ($physicalTables === []) {
            return false;
        }
        try {
            $this->dumpDatabase($absolutePath, $physicalTables);
        } catch (\Throwable) {
            return false;
        }

        return is_file($absolutePath) && filesize($absolutePath) > 0;
    }

    /** 从 runtime 目录内 SQL 快照恢复（跳过备份签名校验） */
    public function restoreRuntimeSqlSnapshot(string $absolutePath): ServiceResult
    {
        $absolutePath = realpath($absolutePath) ?: $absolutePath;
        $runtimeRoot  = realpath(rtrim(ProjectPaths::runtimeDir(), '/\\')) ?: '';
        $normPath     = str_replace('\\', '/', $absolutePath);
        $normRuntime  = str_replace('\\', '/', $runtimeRoot);
        if ($runtimeRoot === '' || !str_starts_with($normPath, $normRuntime)) {
            return ServiceResult::fail('仅允许恢复 runtime 目录内快照');
        }
        if (!is_file($absolutePath) || !$this->looksLikeDatabaseBackup($absolutePath)) {
            return ServiceResult::fail('快照无效');
        }

        try {
            if ($this->restoreViaMysqlCli($absolutePath)) {
                return ServiceResult::ok(null, 'SQL 快照已恢复');
            }
            $this->restoreViaPdo($absolutePath);

            return ServiceResult::ok(null, 'SQL 快照已恢复（PDO）');
        } catch (\Throwable $e) {
            return ServiceResult::fail('恢复失败：' . $e->getMessage());
        }
    }

    /**
     * 从 uploads_*.zip 恢复 public/uploads（覆盖同名文件）
     *
     * @return ServiceResult
     * @param mixed $name
     */
    public function restoreUploads(string $name): ServiceResult
    {
        $name = basename($name);
        if (!str_starts_with($name, 'uploads_') || !str_ends_with(strtolower($name), '.zip')) {
            return ServiceResult::fail('仅支持恢复 uploads_*.zip 备份');
        }
        $path = $this->resolveFile($name);
        if ($path === null) {
            return ServiceResult::fail('备份文件不存在');
        }
        if (!class_exists(\ZipArchive::class)) {
            return ServiceResult::fail('需要 PHP ZipArchive 扩展');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return ServiceResult::fail('无法打开 zip 文件');
        }

        $hasUploads = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (str_starts_with(str_replace('\\', '/', $entry), 'uploads/')) {
                $hasUploads = true;
                break;
            }
        }
        if (!$hasUploads) {
            $zip->close();
            return ServiceResult::fail('压缩包内缺少 uploads/ 目录结构');
        }

        $targetRoot = ROOT_PATH . 'public';
        if (!is_dir($targetRoot) && !mkdir($targetRoot, 0755, true) && !is_dir($targetRoot)) {
            $zip->close();
            return ServiceResult::fail('无法创建 public 目录');
        }

        if (!$zip->extractTo($targetRoot)) {
            $zip->close();
            return ServiceResult::fail('解压失败');
        }
        $zip->close();

        $this->auditLogService->operate('恢复 uploads', 'admin.backup', ['file' => $name]);

        return ServiceResult::ok(null, 'uploads 已恢复');
    }

    private function looksLikeDatabaseBackup(string $path): bool
    {
        if (filesize($path) < 32) {
            return false;
        }
        $head = (string) file_get_contents($path, false, null, 0, 4096);
        if ($head === '') {
            return false;
        }

        return str_contains($head, 'CREATE TABLE')
            || str_contains($head, 'INSERT INTO')
            || str_contains($head, 'PivArk');
    }

    private function restoreViaMysqlCli(string $path): bool
    {
        $mysql = $this->findMysql();
        if ($mysql === null) {
            return false;
        }

        $cfg = config('database.connections.mysql');
        $db  = $this->configString($cfg['database'] ?? '');

        return (bool) TrustedShellRunner::withMysqlDefaultsExtraFile(
            $this->mysqlClientSectionFromConfig(),
            static function (string $cnfPath) use ($mysql, $db, $path): bool {
                $cmd = sprintf(
                    '%s --defaults-extra-file=%s %s < %s',
                    escapeshellarg($mysql),
                    escapeshellarg($cnfPath),
                    escapeshellarg($db),
                    escapeshellarg($path)
                );

                return TrustedShellRunner::passthru($cmd) === 0;
            },
        );
    }

    private function mysqlClientSectionFromConfig(): array
    {
        $cfg = config('database.connections.mysql');

        return [
            'host'     => $this->configString($cfg['hostname'] ?? '127.0.0.1'),
            'port'     => $this->configString($cfg['hostport'] ?? '3306'),
            'user'     => $this->configString($cfg['username'] ?? ''),
            'password' => $this->configString($cfg['password'] ?? ''),
        ];
    }

    private function restoreViaPdo(string $path): void
    {
        // 故意保留裸 Db::execute：还原 mysqldump 多语句备份，无 ORM 等价路径。
        $sql = (string) file_get_contents($path);
        if ($sql === '') {
            throw new \RuntimeException('备份文件为空');
        }

        foreach ($this->splitSqlStatements($sql) as $statement) {
            if ($statement === '') {
                continue;
            }
            \think\facade\Db::execute($statement);
        }
    }

    /**
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*\n/', $sql) ?: [];

        return array_values(array_filter(array_map(static fn ($part) => trim($part), $parts)));
    }

    /**
     * @param list<string> $tables
     */
    private function dumpDatabase(string $targetFile, array $tables): void
    {
        $cfg = config('database.connections.mysql');
        $db  = $this->configString($cfg['database'] ?? '');

        $mysqldump = $this->findMysqldump();
        if ($mysqldump !== null) {
            $dumped = TrustedShellRunner::withMysqlDefaultsExtraFile(
                $this->mysqlClientSectionFromConfig(),
                function (string $cnfPath) use ($mysqldump, $db, $tables, $targetFile): bool {
                    $tableArgs = implode(' ', array_map(static fn (string $t) => escapeshellarg($t), $tables));
                    $cmd       = sprintf(
                        '%s --defaults-extra-file=%s --skip-comments --single-transaction --quick --no-tablespaces %s %s > %s',
                        escapeshellarg($mysqldump),
                        escapeshellarg($cnfPath),
                        escapeshellarg($db),
                        $tableArgs,
                        escapeshellarg($targetFile)
                    );

                    return TrustedShellRunner::passthru($cmd) === 0
                        && is_file($targetFile)
                        && filesize($targetFile) > 0;
                },
            );
            if ($dumped) {
                return;
            }
        }

        $this->dumpDatabaseViaPdo($targetFile, $tables);
    }

    /**
     * @param list<string> $tables
     */
    private function dumpDatabaseViaPdo(string $targetFile, array $tables): void
    {
        // 故意保留 SHOW CREATE TABLE + 行级 INSERT：mysqldump 不可用时的 PDO 兜底。
        // 流式落盘：禁止把全库 INSERT 攒进内存再 implode（Community 大站会 OOM 128M）。
        $pdo = \think\facade\Db::connect()->getPdo();
        $fh  = fopen($targetFile, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('无法写入备份文件');
        }
        try {
            $write = static function (string $chunk) use ($fh): void {
                if (fwrite($fh, $chunk) === false) {
                    throw new \RuntimeException('无法写入备份文件');
                }
            };
            $write('-- PivArk PDO backup ' . AppTime::format('c') . "\nSET NAMES utf8mb4;\n\n");
            foreach ($tables as $table) {
                $table  = DbTable::assertPhysicalTableName($table, null);
                $create = \think\facade\Db::query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
                $write('DROP TABLE IF EXISTS `' . $table . "`;\n");
                $write((string) ($create[0]['Create Table'] ?? '') . "\n\n");
                $batchSize = 200;
                $offset    = 0;
                $quoted    = '`' . str_replace('`', '``', $table) . '`';
                while (true) {
                    $rows = \think\facade\Db::query(
                        'SELECT * FROM ' . $quoted . ' LIMIT ' . $batchSize . ' OFFSET ' . $offset
                    );
                    $count = \count($rows);
                    if ($count === 0) {
                        break;
                    }
                    foreach ($rows as $r) {
                        $cols = array_map(
                            fn ($v) => $this->sqlValueLiteral($pdo, $v),
                            array_values($r)
                        );
                        $write('INSERT INTO ' . $quoted . ' VALUES (' . implode(',', $cols) . ");\n");
                    }
                    unset($rows, $cols, $r);
                    if ($count < $batchSize) {
                        break;
                    }
                    $offset += $batchSize;
                }
                $write("\n");
            }
        } finally {
            fclose($fh);
        }
    }

    private function zipUploads(string $zipPath): void
    {
        $uploadRoot = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($uploadRoot)) {
            throw new \RuntimeException('uploads 目录不存在');
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('需要 PHP ZipArchive 扩展');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建 zip');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($uploadRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $local = 'uploads/' . substr($path, strlen($uploadRoot) + 1);
            $zip->addFile($path, str_replace('\\', '/', $local));
        }
        $zip->close();
    }

    private function findMysqldump(): ?string
    {
        $resolved = TrustedShellRunner::resolveTrustedBinary(
            getenv('PIVARK_MYSQLDUMP_PATH'),
            ['mysqldump', 'mysqldump.exe'],
            $this->siteModeService->isDev() ? 'mysqldump' : null,
        );
        if ($resolved !== null) {
            return $resolved;
        }
        if (!$this->siteModeService->isDev()) {
            return null;
        }

        return TrustedShellRunner::resolveBinaryOnPath('mysqldump.exe')
            ?? TrustedShellRunner::resolveBinaryOnPath('mysqldump');
    }

    private function findMysql(): ?string
    {
        $resolved = TrustedShellRunner::resolveTrustedBinary(
            getenv('PIVARK_MYSQL_PATH'),
            ['mysql', 'mysql.exe'],
            $this->siteModeService->isDev() ? 'mysql' : null,
        );
        if ($resolved !== null) {
            return $resolved;
        }
        if (!$this->siteModeService->isDev()) {
            return null;
        }

        return TrustedShellRunner::resolveBinaryOnPath('mysql.exe')
            ?? TrustedShellRunner::resolveBinaryOnPath('mysql');
    }

    private function sqlValueLiteral(\PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            return $pdo->quote($json === false ? '{}' : $json);
        }

        return $pdo->quote((string) $value);
    }

    private function configString(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }

    /**
     * 按保留天数清理旧备份（仅 db_*.sql / uploads_*.zip）
     *
     * @return array{deleted:int,kept:int}
     */
    public function pruneOlderThanDays(int $days): array
    {
        $days = max(1, $days);
        $cutoff = time() - $days * 86400;
        $deleted = 0;
        $kept    = 0;
        foreach ($this->listBackups() as $item) {
            $name = (string) ($item['name'] ?? '');
            $path = $name !== '' ? $this->storageDir() . DIRECTORY_SEPARATOR . $name : '';
            if ($path === '' || !is_file($path)) {
                continue;
            }
            if ((int) filemtime($path) >= $cutoff) {
                $kept++;
                continue;
            }
            if (LocalFile::unlinkIfExists($path)) {
                $deleted++;
            }
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    private function backupSignaturePath(string $path): string
    {
        return $path . '.sig';
    }

    private function backupHmacKey(): string
    {
        $key = trim((string) env('APP_KEY', ''));
        if ($key === '') {
            $key = trim((string) env('CIPHER_KEY', ''));
        }

        return $key;
    }

    private function writeBackupSignature(string $path): void
    {
        $key = $this->backupHmacKey();
        if ($key === '') {
            return;
        }
        $payload = (string) file_get_contents($path);
        if ($payload === '') {
            return;
        }
        $sig = hash_hmac('sha256', $payload, $key);
        file_put_contents($this->backupSignaturePath($path), $sig . "\n");
    }

    private function verifyBackupSignature(string $path): bool
    {
        $sigFile = $this->backupSignaturePath($path);
        if (!is_file($sigFile)) {
            return true;
        }
        $key = $this->backupHmacKey();
        if ($key === '') {
            return false;
        }
        $expected = trim((string) file_get_contents($sigFile));
        if ($expected === '') {
            return false;
        }
        $payload = (string) file_get_contents($path);
        if ($payload === '') {
            return false;
        }

        return hash_equals($expected, hash_hmac('sha256', $payload, $key));
    }
}
