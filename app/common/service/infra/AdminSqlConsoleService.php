<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\service\audit\AuditLogService;
use app\common\support\ServiceResult;
use think\facade\Db;

/** 运维 SQL 控制台：受限执行/导入（须权限 + 敏感确认 + 写操作口令） */
class AdminSqlConsoleService
{
    public const MAX_EXECUTE_BYTES = 256_000;
    public const MAX_IMPORT_BYTES = 2_000_000;
    public const MAX_STATEMENTS = 40;
    public const MAX_SELECT_ROWS = 200;

    /** 勾选写入时须原样提交的确认文案（防运营误点） */
    public const WRITE_CONFIRM_PHRASE = '我确认写入';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'max_execute_bytes'    => self::MAX_EXECUTE_BYTES,
            'max_import_bytes'     => self::MAX_IMPORT_BYTES,
            'max_statements'       => self::MAX_STATEMENTS,
            'max_select_rows'      => self::MAX_SELECT_ROWS,
            'read_verbs'           => ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'],
            'write_confirm_phrase' => self::WRITE_CONFIRM_PHRASE,
            'blocked_always'       => ['DROP', 'TRUNCATE', '库级/账号/文件危险语句'],
            'warning'              => '高危运维能力：误操作可能导致数据丢失。写操作须勾选允许写入、输入确认口令，并完成敏感确认。DROP/TRUNCATE 一律禁止（请用数据备份恢复）。',
        ];
    }

    public function execute(string $sql, bool $allowWrite, string $writeConfirm = ''): ServiceResult
    {
        return $this->runSql($sql, $allowWrite, $writeConfirm, self::MAX_EXECUTE_BYTES, 'execute');
    }

    public function import(string $sql, bool $allowWrite, string $writeConfirm = ''): ServiceResult
    {
        return $this->runSql($sql, $allowWrite, $writeConfirm, self::MAX_IMPORT_BYTES, 'import');
    }

    private function runSql(
        string $sql,
        bool $allowWrite,
        string $writeConfirm,
        int $maxBytes,
        string $mode,
    ): ServiceResult {
        $sql = trim(str_replace("\0", '', $sql));
        if ($sql === '') {
            return ServiceResult::fail('SQL 不能为空');
        }
        if (strlen($sql) > $maxBytes) {
            return ServiceResult::fail('SQL 超过大小限制（' . $this->formatBytes($maxBytes) . '）');
        }

        $statements = $this->splitStatements($sql);
        if ($statements === []) {
            return ServiceResult::fail('没有可执行的 SQL 语句');
        }
        if (count($statements) > self::MAX_STATEMENTS) {
            return ServiceResult::fail('单次最多 ' . self::MAX_STATEMENTS . ' 条语句');
        }

        $needsWrite = false;
        foreach ($statements as $stmt) {
            $deny = $this->denyReason($stmt, $allowWrite);
            if ($deny !== null) {
                return ServiceResult::fail($deny);
            }
            if (!$this->isReadOnly($stmt)) {
                $needsWrite = true;
            }
        }

        if ($needsWrite) {
            if (!$allowWrite) {
                return ServiceResult::fail('写操作被拒绝：请勾选「允许写入」后再执行');
            }
            if (trim($writeConfirm) !== self::WRITE_CONFIRM_PHRASE) {
                return ServiceResult::fail(
                    '写操作须在确认框原样输入：' . self::WRITE_CONFIRM_PHRASE
                );
            }
        }

        @set_time_limit(60);
        $results = [];
        $affectedTotal = 0;

        try {
            foreach ($statements as $i => $stmt) {
                $preview = mb_substr(preg_replace('/\s+/', ' ', $stmt) ?? $stmt, 0, 120);
                if ($this->isReadOnly($stmt)) {
                    $rows = Db::query($stmt);
                    if (!is_array($rows)) {
                        $rows = [];
                    }
                    $truncated = false;
                    if (count($rows) > self::MAX_SELECT_ROWS) {
                        $rows = array_slice($rows, 0, self::MAX_SELECT_ROWS);
                        $truncated = true;
                    }
                    $columns = $rows === [] ? [] : array_keys($rows[0] ?? []);
                    $results[] = [
                        'index'     => $i + 1,
                        'kind'      => 'query',
                        'sql'       => $preview,
                        'columns'   => $columns,
                        'rows'      => $rows,
                        'row_count' => count($rows),
                        'truncated' => $truncated,
                    ];
                } else {
                    $affected = (int) Db::execute($stmt);
                    $affectedTotal += max(0, $affected);
                    $results[] = [
                        'index'    => $i + 1,
                        'kind'     => 'exec',
                        'sql'      => $preview,
                        'affected' => $affected,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->auditLogService->operate('SQL控制台失败', 'admin.sql_console', [
                'mode'  => $mode,
                'error' => $e->getMessage(),
                'sql'   => mb_substr($sql, 0, 500),
            ]);

            return ServiceResult::fail('执行失败：' . $e->getMessage());
        }

        $this->auditLogService->operate('SQL控制台执行', 'admin.sql_console', [
            'mode'           => $mode,
            'allow_write'    => $allowWrite ? 1 : 0,
            'statements'     => count($statements),
            'affected_total' => $affectedTotal,
            'sql_preview'    => mb_substr($sql, 0, 500),
        ]);

        return ServiceResult::ok([
            'mode'            => $mode,
            'statement_count' => count($statements),
            'affected_total'  => $affectedTotal,
            'results'         => $results,
        ], '执行完成');
    }

    private function denyReason(string $stmt, bool $allowWrite): ?string
    {
        // 一律禁止（含已勾选写入）：结构破坏 / 账号 / 文件 / 耗尽
        if (preg_match(
            '/\b(DROP\b|TRUNCATE\b|INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE\s*\(|LOAD\s+DATA|CREATE\s+USER|ALTER\s+USER|DROP\s+USER|GRANT\b|REVOKE\b|SET\s+PASSWORD|SHUTDOWN|BENCHMARK\s*\(|SLEEP\s*\()/i',
            $stmt
        )) {
            return '已拦截危险 SQL（DROP/TRUNCATE / 文件读写 / 账号权限等一律禁止；结构变更请用迁移或数据备份）';
        }
        if (!$allowWrite && !$this->isReadOnly($stmt)) {
            return '写操作被拒绝：请勾选「允许写入」后再执行';
        }

        return null;
    }

    private function isReadOnly(string $stmt): bool
    {
        return (bool) preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $stmt);
    }

    /**
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*[\r\n]+|;\s*$/m', $sql) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $stmt = trim((string) $part);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $out[] = rtrim($stmt, " \t\n\r;");
        }

        return array_values(array_filter($out, static fn (string $s): bool => $s !== ''));
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
}
