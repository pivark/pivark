<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 系统数据库升级（dig 入口：`migrations/run.php`；客户升级从包工作区执行）
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\enum\ApiErrorCode;

use app\common\support\ServiceResult;
use app\common\support\QueryLimit;
use app\common\model\SchemaMigration;

use app\common\support\CliProcessRunner;
use app\common\support\ProjectPaths;

use app\common\service\release\PivarkEditionService;

class SchemaMigrationService
{

    public function __construct(
        private readonly PivarkEditionService $edition,
    ) {
    }

    /**
     * @return array{
     *   pending:list<array{name:string,file:string,basename:string}>,
     *   applied:list<array{name:string,applied_at:string}>,
     *   pending_count:int,
     *   applied_count:int,
     *   migrations_dir:string
     * }
     */
    /** 控制台等轻量场景：60s 内复用 pending_count，避免每次扫全量迁移文件 */
    public function pendingCount(): int
    {
        static $cached = null;
        static $cachedAt = 0;
        if ($cached !== null && (time() - $cachedAt) < 60) {
            return $cached;
        }
        $cached   = $this->status()['pending_count'] ?? 0;
        $cachedAt = time();

        return $cached;
    }

    public function status(): array
    {
        $this->ensureBootstrapLoaded();
        ['pdo' => $pdo, 'pfx' => $pfx] = \migration_bootstrap(ProjectPaths::root());
        \migration_ensure_versions_table($pdo, $pfx);

        $pending  = [];
        $queue    = $this->buildQueue();
        foreach ($queue as $file) {
            $base = basename($file);
            $name = preg_replace('/\.php$/', '', $base) ?? $base;
            if (!\migration_has_applied($pdo, $pfx, $name)) {
                $pending[] = [
                    'name'     => $name,
                    'file'     => $file,
                    'basename' => $base,
                ];
            }
        }

        $appliedRows = SchemaMigration::order('applied_at', 'desc')->limit(QueryLimit::MIGRATION_RECENT)->select()->toArray();
        $applied     = [];
        foreach ($appliedRows as $row) {
            $applied[] = [
                'name'       => (string) ($row['name'] ?? ''),
                'applied_at' => (string) ($row['applied_at'] ?? ''),
            ];
        }

        return [
            'pending'         => $pending,
            'applied'         => $applied,
            'pending_count'   => count($pending),
            'applied_count'   => count($appliedRows),
            'migrations_dir'  => $this->migrationsDir(),
        ];
    }

    /**
     * 执行下一项待迁移（后台分批调用，避免 HTTP 超时）
     *
     * @return ServiceResult
     */
    public function runNext(bool $dryRun = false): ServiceResult
    {
        $status = $this->status();
        $next   = $status['pending'][0] ?? null;
        $total  = \count($this->buildQueue());
        if ($next === null) {
            return ServiceResult::ok([
                'done'              => true,
                'ran'               => 0,
                'pending'           => 0,
                'migration_total'   => $total,
                'migration_applied' => $total,
            ], '数据库结构已是最新');
        }

        if ($dryRun) {
            return ServiceResult::ok(['done' => false, 'dry_run' => true, 'next' => $next['name']], '将执行：' . (string) $next['basename']);
        }

        $file = (string) ($next['file'] ?? '');
        if ($file === '' || !is_readable($file)) {
            return ServiceResult::fail('迁移脚本不可读：' . (string) ($next['basename'] ?? ''));
        }

        $result = $this->executeMigrationFile($file);
        $exitCode = (int) ($result['exit_code'] ?? 1);
        $output = (string) ($result['output'] ?? '');
        if ($exitCode !== 0) {
            $tail = trim(implode("\n", array_slice(explode("\n", $output), -8)));
            $detail = $tail !== '' ? (': ' . $tail) : '';

            return ServiceResult::fail('迁移失败：' . (string) ($next['basename'] ?? '') . '（exit ' . $exitCode . '）' . $detail, ApiErrorCode::VALIDATION, [
                    'done'   => false,
                    'failed' => (string) ($next['name'] ?? ''),
                    'output' => implode("\n", array_slice(explode("\n", $output), -20)),
                ]);
        }

        $name = preg_replace('/\.php$/', '', basename($file)) ?? basename($file);
        ['pdo' => $pdo, 'pfx' => $pfx] = \migration_bootstrap(ProjectPaths::root());
        if (!\migration_has_applied($pdo, $pfx, $name)) {
            return ServiceResult::fail('迁移未写入版本表：' . (string) ($next['basename'] ?? ''), ApiErrorCode::VALIDATION, [
                    'done'   => false,
                    'failed' => (string) ($next['name'] ?? ''),
                    'output' => implode("\n", array_slice(explode("\n", $output), -20)),
                ]);
        }

        $after = $this->status();
        $total = \count($this->buildQueue());

        return ServiceResult::ok([
                'done'              => $after['pending_count'] < 1,
                'ran'               => 1,
                'pending'           => $after['pending_count'],
                'name'              => (string) ($next['name'] ?? ''),
                'migration_total'   => $total,
                'migration_applied' => max(0, $total - (int) $after['pending_count']),
            ], '已执行：' . (string) ($next['basename'] ?? ''));
    }

    /**
     * 安装向导 / CLI：连续执行全部待迁移
     *
     * @return ServiceResult
     */
    public function runAllPending(): ServiceResult
    {
        $ran = 0;
        while (true) {
            $res = $this->runNext(false);
            if (!$res->isOk()) {
                return ServiceResult::fail((string) ($res->message() ?? '迁移失败'));
            }
            if (!empty($res->dataArray()['done'])) {
                break;
            }
            if ((int) ($res->dataArray()['ran'] ?? 0) < 1) {
                break;
            }
            $ran++;
        }

        return ServiceResult::ok(['ran' => $ran], $ran > 0 ? ('已执行 ' . $ran . ' 项数据库迁移') : '数据库结构已是最新');
    }

    /**
     * 已执行且脚本内声明 migration_down() 的迁移（applied_at 降序）
     *
     * @return list<array{name:string,file:string,basename:string,applied_at:string}>
     */
    public function listRollbackable(): array
    {
        $this->ensureBootstrapLoaded();
        $out = [];
        $rows = SchemaMigration::order('applied_at', 'desc')->select()->toArray();
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $file = $this->resolveMigrationFile($name);
            if ($file === null || !\migration_file_supports_down($file)) {
                continue;
            }
            $out[] = [
                'name'        => $name,
                'file'        => $file,
                'basename'    => basename($file),
                'applied_at'  => (string) ($row['applied_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * 回滚最近一次（已执行且支持 down 的）迁移
     *
     * @return ServiceResult
     */
    public function rollbackLast(bool $dryRun = false): ServiceResult
    {
        $candidates = $this->listRollbackable();
        $target     = $candidates[0] ?? null;
        if ($target === null) {
            return ServiceResult::fail('没有支持 rollback 的已执行迁移（须在脚本内声明 migration_down + migration_entry）');
        }

        return $this->rollbackByName((string) $target['name'], $dryRun);
    }

    /**
     * @return ServiceResult
     */
    public function rollbackByName(string $name, bool $dryRun = false): ServiceResult
    {
        $name = trim($name);
        if ($name === '') {
            return ServiceResult::fail('迁移名不能为空');
        }

        $this->ensureBootstrapLoaded();
        ['pdo' => $pdo, 'pfx' => $pfx] = \migration_bootstrap(ProjectPaths::root());
        if (!\migration_has_applied($pdo, $pfx, $name)) {
            return ServiceResult::fail('迁移未执行：' . $name);
        }

        $file = $this->resolveMigrationFile($name);
        if ($file === null || !is_readable($file)) {
            return ServiceResult::fail('迁移脚本不可读：' . $name);
        }
        if (!\migration_file_supports_down($file)) {
            return ServiceResult::fail('该迁移未声明 migration_down()：' . $name);
        }

        if ($dryRun) {
            return ServiceResult::ok(['dry_run' => true, 'name' => $name], '将 rollback：' . basename($file));
        }

        $result   = $this->executeMigrationRollback($file);
        $exitCode = (int) ($result['exit_code'] ?? 1);
        $output   = (string) ($result['output'] ?? '');
        if ($exitCode !== 0) {
            $tail = trim(implode("\n", array_slice(explode("\n", $output), -8)));

            return ServiceResult::fail('rollback 失败：' . $name . ($tail !== '' ? (': ' . $tail) : ''), ApiErrorCode::VALIDATION, [
                'name'   => $name,
                'output' => implode("\n", array_slice(explode("\n", $output), -20)),
            ]);
        }

        if (\migration_has_applied($pdo, $pfx, $name)) {
            return ServiceResult::fail('rollback 未清除版本表：' . $name, ApiErrorCode::VALIDATION, [
                'name'   => $name,
                'output' => implode("\n", array_slice(explode("\n", $output), -20)),
            ]);
        }

        return ServiceResult::ok(['name' => $name], '已 rollback：' . basename($file));
    }

    public function migrationsDir(): string
    {
        return ProjectPaths::migrationsDir();
    }

    /** @return list<string> 绝对路径队列 */
    public function buildQueue(): array
    {
        $discovered = $this->discoverInDir($this->migrationsDir());
        foreach ($this->discoverWeappPluginMigrations() as $base => $file) {
            if (!isset($discovered[$base])) {
                $discovered[$base] = $file;
            }
        }

        $queue = [];
        foreach (SchemaMigrationRegistry::ORDERED as $base) {
            if (isset($discovered[$base])) {
                $queue[] = $discovered[$base];
                unset($discovered[$base]);
            }
        }
        foreach (array_keys($discovered) as $base) {
            $queue[] = $discovered[$base];
        }

        return $this->filterQueueForCommunityEdition($queue);
    }

    /** @param list<string> $queue */
    private function filterQueueForCommunityEdition(array $queue): array
    {
        if (!class_exists(\app\common\service\release\PivarkEditionService::class)
            || !$this->edition->isCommunity()) {
            return $queue;
        }

        $root = ProjectPaths::root();
        $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $out = [];
        foreach ($queue as $file) {
            $fileNorm = str_replace('\\', '/', $file);
            $rel = str_starts_with($fileNorm, $rootNorm)
                ? substr($fileNorm, strlen($rootNorm))
                : basename($fileNorm);
            if (\app\common\support\ReleaseMigrationRules::shouldSkipMigrationRel($rel)) {
                continue;
            }
            $out[] = $file;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function discoverInDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $discovered = [];
        $pattern = $dir . DIRECTORY_SEPARATOR . 'migrate_*.php';
        foreach (glob($pattern) ?: [] as $file) {
            $this->collectMigrationFiles($dir, $file, $discovered);
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (glob($sub . DIRECTORY_SEPARATOR . 'migrate_*.php') ?: [] as $file) {
                $this->collectMigrationFiles($dir, $file, $discovered);
            }
        }

        return $discovered;
    }

    /**
     * 官方 weapp 插件 DDL 迁移（weapp/{id}/database/migrations/migrate_*.php）
     *
     * @return array<string, string> basename => absolute path
     */
    private function discoverWeappPluginMigrations(): array
    {
        $weappRoot = rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR . 'weapp';
        if (!is_dir($weappRoot)) {
            return [];
        }

        $discovered = [];
        foreach (scandir($weappRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $pluginDir = $weappRoot . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($pluginDir)) {
                continue;
            }
            $migDir = $pluginDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
            if (!is_dir($migDir)) {
                continue;
            }
            foreach (glob($migDir . DIRECTORY_SEPARATOR . 'migrate_*.php') ?: [] as $file) {
                $this->collectMigrationFiles($migDir, $file, $discovered);
            }
        }

        return $discovered;
    }

    /** @param array<string,string> $discovered */
    private function collectMigrationFiles(string $rootDir, string $file, array &$discovered): void
    {
        $base = basename($file);
        if (in_array($base, SchemaMigrationRegistry::SKIP, true)) {
            return;
        }
        if (isset($discovered[$base]) && $discovered[$base] !== $file) {
            throw new \RuntimeException(sprintf(
                'Duplicate migration basename %s: %s and %s',
                $base,
                $discovered[$base],
                $file
            ));
        }
        $discovered[$base] = $file;
    }

    /** @return array{exit_code:int,output:string} */
    private function executeMigrationFile(string $file, bool $downOnly = false): array
    {
        $env = $downOnly ? ['PIVARK_MIGRATION_DOWN_ONLY' => '1'] : [];
        $subprocess = CliProcessRunner::runPhpScript($file, $env);
        if (!CliProcessRunner::isUnavailable($subprocess)) {
            return $subprocess;
        }

        // 本机 disable_functions 禁尽壳时：迁移已改为 down 闭包，同进程连续 include 安全
        if ($downOnly && !\defined('PIVARK_MIGRATION_DOWN_ONLY')) {
            \define('PIVARK_MIGRATION_DOWN_ONLY', true);
        }
        if (!\defined('PIVARK_MIGRATION_EMBEDDED')) {
            \define('PIVARK_MIGRATION_EMBEDDED', true);
        }

        \ob_start();
        $exitCode = 0;
        try {
            $this->ensureBootstrapLoaded();
            include $file;
        } catch (\MigrationEmbeddedExit $e) {
            $exitCode = max(0, (int) $e->getCode());
        } catch (\Throwable $e) {
            $exitCode = 1;
            echo $e->getMessage();
        }

        return [
            'exit_code' => $exitCode,
            'output'    => (string) \ob_get_clean(),
        ];
    }

    /** @return array{exit_code:int,output:string} */
    private function executeMigrationRollback(string $file): array
    {
        return $this->executeMigrationFile($file, true);
    }

    private function resolveMigrationFile(string $name): ?string
    {
        $base = $name . '.php';
        foreach ($this->buildQueue() as $file) {
            if (basename($file) === $base) {
                return $file;
            }
        }

        return null;
    }

    private function canExecShell(): bool
    {
        return CliProcessRunner::canShellFunction('exec');
    }

    private function prepareInProcessMigration(): void
    {
        $this->ensureBootstrapLoaded();
        $root = ProjectPaths::root();
        foreach ([
            $root . '/app/bootstrap/cli.php',
            ProjectPaths::optionalWorkspaceToolsFile(['daily', 'bootstrap', 'cli.php']),
        ] as $cli) {
            if ($cli !== '' && is_readable($cli)) {
                require_once $cli;
                break;
            }
        }
        if (function_exists('pivark_app')) {
            pivark_app();
        }
    }

    private function ensureBootstrapLoaded(): void
    {
        $bootstrap = ProjectPaths::migrationsBootstrapFile();
        if (is_readable($bootstrap)) {
            require_once $bootstrap;
        }
        if (!\function_exists('migration_bootstrap')) {
            $fallback = ProjectPaths::migrationsDir() . DIRECTORY_SEPARATOR . '_migration.php';
            if (is_readable($fallback)) {
                require_once $fallback;
            }
        }
        if (!\function_exists('migration_bootstrap')) {
            throw new \RuntimeException('缺少迁移引导：migrations bootstrap');
        }
    }
}
