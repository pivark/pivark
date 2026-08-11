<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace install\service;

use app\common\service\infra\SchemaMigrationService;
use app\common\support\CliProcessRunner;
use app\common\support\OpsLog;
use app\common\support\ProjectPaths;
use app\common\support\ServiceResult;
use app\common\support\SiteEnv;
use app\common\support\TrustedShellRunner;
use PDO;

/** 装站一次性：库连接/建表/迁移；装完可随 install/ 删除 */
final class InstallDatabaseService
{
    /**
     * 历史无前缀遗留表（早期 AI 迁移曾不带 DB_PREFIX；wipe 只删 pv_* 时会漏网）
     *
     * @var list<string>
     */
    private const LEGACY_UNPREFIXED_LEFTOVER_TABLES = [
        'ai_chunks',
        'ai_document_process',
    ];

    public function __construct(
        private readonly SchemaMigrationService $schemaMigrationService,
    ) {
    }

    /**
     * @param array<string, string> $config
     */
    public function testDatabase(array $config): ServiceResult
    {
        try {
            $pdo = $this->connect($config);
            $pdo->query('SELECT 1');
            $inspect = $this->inspectPrefixedTables($config);

            return ServiceResult::ok($inspect, '连接成功');
        } catch (\Throwable $e) {
            return ServiceResult::fail($this->friendlyDatabaseErrorMessage($e));
        }
    }

    /**
     * 安装向导可见的中文库错（禁直接抛 SQLSTATE；否则 ApiUserMessage 会降级成「提交的数据有误」）
     */
    private function friendlyDatabaseErrorMessage(\Throwable $e): string
    {
        $raw = $e->getMessage();
        if (str_contains($raw, '1045') || stripos($raw, 'Access denied') !== false) {
            return '连接失败：数据库账号或密码不正确';
        }
        if (str_contains($raw, '1049') || stripos($raw, 'Unknown database') !== false) {
            return '连接失败：数据库不存在，请先在面板创建空库后再填库名';
        }
        if (str_contains($raw, '2002') || str_contains($raw, '2003') || stripos($raw, 'Connection refused') !== false) {
            return '连接失败：无法连接数据库主机，请检查主机与端口是否可通';
        }
        if (stripos($raw, 'could not find driver') !== false) {
            return '连接失败：PHP 未启用 pdo_mysql 扩展';
        }

        return '连接失败：请检查主机、端口、库名、账号与密码是否匹配';
    }

    /**
     * @param array<string, string> $config
     * @return array{table_count:int,sample_tables:list<string>,needs_overwrite:bool}
     */
    public function inspectPrefixedTables(array $config): array
    {
        $tables = $this->listPrefixedTables($config);
        $leftovers = $this->listLegacyUnprefixedLeftoverTables($config);
        $visible = array_values(array_unique([...$tables, ...$leftovers]));

        return [
            'table_count'     => \count($visible),
            'sample_tables'   => \array_slice($visible, 0, 8),
            'needs_overwrite' => $visible !== [],
        ];
    }

    /**
     * @param array<string, string> $config
     */
    public function databaseHasPrefixedTables(array $config): bool
    {
        return $this->listPrefixedTables($config) !== []
            || $this->listLegacyUnprefixedLeftoverTables($config) !== [];
    }

    public function countPrefixedTablesFromEnv(): int
    {
        return \count($this->listPrefixedTables($this->readExistingDatabaseDefaults()));
    }

    /**
     * 重装时预填数据库表单（读已有 data/site.env，密码须用户重新输入或保留原值提交）
     *
     * @return array{db_host:string,db_port:string,db_name:string,db_user:string,db_pass:string,db_prefix:string}
     */
    public function readExistingDatabaseDefaults(): array
    {
        $defaults = [
            'db_host'   => '127.0.0.1',
            'db_port'   => '3306',
            'db_name'   => '',
            'db_user'   => '',
            'db_pass'   => '',
            'db_prefix' => 'pv_',
        ];
        $path = SiteEnv::resolvePath(ROOT_PATH);
        if ($path === null || !\is_readable($path)) {
            return $defaults;
        }
        $env = parse_ini_file($path, true, INI_SCANNER_RAW);
        if (!\is_array($env)) {
            return $defaults;
        }
        foreach (['db_host' => 'DB_HOST', 'db_port' => 'DB_PORT', 'db_name' => 'DB_NAME', 'db_user' => 'DB_USER', 'db_pass' => 'DB_PASS', 'db_prefix' => 'DB_PREFIX'] as $key => $envKey) {
            $value = trim((string) ($env[$envKey] ?? ''));
            if ($value !== '') {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    /**
     * @param array<string, string> $config
     */
    public function wipePrefixedTables(array $config): int
    {
        $pdo = $this->connect($config);
        $tables = array_values(array_unique([
            ...$this->listPrefixedTables($config),
            ...$this->listLegacyUnprefixedLeftoverTables($config),
        ]));
        if ($tables === []) {
            return 0;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        return \count($tables);
    }

    /**
     * @return list<string>
     */
    public function runSchemaSetup(): array
    {
        $log = [];
        $guard = 0;
        while ($guard++ < 500) {
            $step = $this->runSchemaSetupNext();
            if (!$step->isOk()) {
                throw new \RuntimeException((string) ($step->message() ?: '数据库初始化失败'));
            }
            $log[] = (string) ($step->message() ?: 'schema');
            if (!empty($step->dataArray()['done'])) {
                break;
            }
        }

        return $log;
    }

    /**
     * 安装向导分批建表：返回 applied/total/pending/table_count（与迁移进度字段对齐，供前端进度条）
     */
    public function runSchemaSetupNext(): ServiceResult
    {
        if ($this->schemaSetupAlreadyInitialized()) {
            $tables = $this->countPrefixedTablesFromEnv();

            return ServiceResult::ok([
                'done'              => true,
                'ran'               => 0,
                'pending'           => 0,
                'migration_total'   => $tables,
                'migration_applied' => $tables,
                'table_count'       => $tables,
                'name'              => 'already_initialized',
            ], '数据库表结构已就绪（' . $tables . ' 张表）');
        }

        $setup = rtrim(ProjectPaths::installSetupDir(), '/\\') . DIRECTORY_SEPARATOR;
        $schemaSql = $setup . 'schema.sql';

        // 无 schema.sql：一次跑完旧路径
        if (!\is_readable($schemaSql)) {
            $this->runLegacyInitScripts($setup);
            $tables = $this->countPrefixedTablesFromEnv();
            $this->clearSchemaProgressJob();

            return ServiceResult::ok([
                'done'              => true,
                'ran'               => 1,
                'pending'           => 0,
                'migration_total'   => $tables,
                'migration_applied' => $tables,
                'table_count'       => $tables,
                'name'              => 'init_db',
            ], '数据库表结构已初始化（' . $tables . ' 张表）');
        }

        $statements = $this->loadSchemaSqlStatements($schemaSql);
        $total = \count($statements);
        if ($total < 1) {
            throw new \RuntimeException('schema.sql 无有效语句');
        }

        $job = $this->readSchemaProgressJob();
        if ($job === null || (int) ($job['total'] ?? 0) !== $total) {
            $job = ['offset' => 0, 'total' => $total, 'seeded' => false];
        }

        $offset = (int) ($job['offset'] ?? 0);
        if ($offset < $total) {
            $batchSize = 12;
            ['pdo' => $pdo, 'pfx' => $pfx] = $this->connectFromSiteEnv();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $end = min($total, $offset + $batchSize);
            $lastName = '';
            for ($i = $offset; $i < $end; $i++) {
                $stmt = $statements[$i];
                $pdo->exec($stmt);
                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^`\s(]+)`?/i', $stmt, $m)) {
                    $lastName = str_replace($pfx, '', (string) $m[1]);
                } else {
                    $lastName = 'sql_' . ($i + 1);
                }
            }
            $offset = $end;
            $job['offset'] = $offset;
            $pending = $total - $offset;
            if ($pending < 1) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
            $this->writeSchemaProgressJob($job);
            // 末批建完须落种（init_db），禁止在「剩余 0」处提前 return，否则编排器会跳过种子导致无 super_admin
            if ($pending > 0) {
                $tables = $this->countPrefixedTablesFromEnv();

                return ServiceResult::ok([
                    'done'              => false,
                    'ran'               => 1,
                    'pending'           => $pending,
                    'migration_total'   => $total,
                    'migration_applied' => $offset,
                    'table_count'       => $tables,
                    'name'              => $lastName !== '' ? $lastName : ('batch_' . $offset),
                ], '建表 ' . $offset . '/' . $total . ' · 剩余 ' . $pending . ' · 已建 ' . $tables . ' 张表');
            }
        }

        if (empty($job['seeded'])) {
            $this->runLegacyInitScripts($setup);
            $this->stampSchemaSqlBaseline();
            $job['seeded'] = true;
            $this->writeSchemaProgressJob($job);
        }

        $this->clearSchemaProgressJob();
        $tables = $this->countPrefixedTablesFromEnv();

        return ServiceResult::ok([
            'done'              => true,
            'ran'               => 1,
            'pending'           => 0,
            'migration_total'   => $total,
            'migration_applied' => $total,
            'table_count'       => $tables,
            'name'              => 'seed_roles_configs',
        ], '数据库表结构已初始化（' . $tables . ' 张表）');
    }

    public function clearSchemaProgressJob(): void
    {
        $path = $this->schemaProgressJobPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return list<string>
     */
    private function loadSchemaSqlStatements(string $path): array
    {
        $sql = (string) file_get_contents($path);
        if (trim($sql) === '') {
            return [];
        }
        ['pfx' => $pfx] = $this->connectFromSiteEnv();
        $sql = str_replace('__DB_PREFIX__', $pfx, $sql);

        return $this->splitSqlStatements($sql);
    }

    private function runLegacyInitScripts(string $setup): void
    {
        foreach (['init_db.php', 'init_configs.php', 'init_document.php'] as $base) {
            $script = $setup . $base;
            if (!\is_readable($script)) {
                throw new \RuntimeException('缺少脚本：' . $script);
            }
            $code = $this->executeSetupScript($script);
            if ($code !== 0) {
                throw new \RuntimeException($base . ' 执行失败，exit ' . $code);
            }
        }
        // 客户轨无 migrate_menu_*：菜单唯一真源 = admin_menu_ssot（init_* 不再插旧 /admin/* 菜单）
        $this->applyAdminMenuSsot();
        // 客户轨无 migrate_cron.php：默认定时任务须在装库种子写入，否则后台 cron 空表
        $this->seedDefaultCronJobs();
        try {
            app(\app\common\service\infra\MetaSqlCacheService::class)->clearFrontMeta();
            app(\app\common\service\site\SiteAdSlotService::class)->bustPublicCache();
        } catch (\Throwable) {
            // CLI 装库早期可无完整容器；忽略
        }
    }

    /**
     * 幂等写入内核默认 cron_jobs（对齐 migrate_cron 种子；Community zip 无 migrations 树）
     */
    private function seedDefaultCronJobs(): void
    {
        $bootstrap = ProjectPaths::migrationsDir() . DIRECTORY_SEPARATOR . '_migration.php';
        if (!\is_readable($bootstrap)) {
            throw new \RuntimeException('缺少迁移引导');
        }
        require_once $bootstrap;
        if (!\function_exists('migration_ensure_cron_job')) {
            throw new \RuntimeException('migration_ensure_cron_job 不可用');
        }
        ['pdo' => $pdo, 'pfx' => $pfx] = $this->connectFromSiteEnv();
        // migration_ensure_cron_job 会 echo；HTTP 装包响应须保持纯 JSON
        ob_start();
        try {
            \migration_ensure_cron_job($pdo, $pfx, 'static_rebuild', '全站静态化', 1440);
            \migration_ensure_cron_job($pdo, $pfx, 'backup_database', '数据库备份', 10080);
            \migration_ensure_cron_job($pdo, $pfx, 'cleanup_audit_logs', '清理操作日志', 43200, '{"days":90}');
            \migration_ensure_cron_job($pdo, $pfx, 'cleanup_old_form_submissions', '清理已处理表单提交', 10080, '{"days":180,"status":2}');
        } finally {
            ob_end_clean();
        }
    }

    /**
     * 将 app/database/seeds/admin_menu_ssot.php 写入 menus（ON DUPLICATE KEY UPDATE）
     */
    private function applyAdminMenuSsot(): void
    {
        $bootstrap = ProjectPaths::migrationsDir() . DIRECTORY_SEPARATOR . '_migration.php';
        if (!\is_readable($bootstrap)) {
            throw new \RuntimeException('缺少数据库迁移引导文件，无法写入后台菜单');
        }
        require_once $bootstrap;
        if (!\function_exists('migration_apply_admin_menu_ssot')) {
            throw new \RuntimeException('migration_apply_admin_menu_ssot 不可用');
        }
        $seed = ProjectPaths::root() . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
            . 'database' . DIRECTORY_SEPARATOR . 'seeds' . DIRECTORY_SEPARATOR . 'admin_menu_ssot.php';
        if (!\is_readable($seed)) {
            throw new \RuntimeException('缺少 app/database/seeds/admin_menu_ssot.php');
        }
        ['pdo' => $pdo, 'pfx' => $pfx] = $this->connectFromSiteEnv();
        $applied = (int) \migration_apply_admin_menu_ssot($pdo, $pfx);
        if ($applied < 40) {
            throw new \RuntimeException('后台菜单初始化不完整（写入行数过少），请重试安装或检查数据库权限');
        }
    }

    /** @return array{offset:int,total:int,seeded:bool}|null */
    private function readSchemaProgressJob(): ?array
    {
        $path = $this->schemaProgressJobPath();
        if (!is_readable($path)) {
            return null;
        }
        $raw = json_decode((string) file_get_contents($path), true);

        return \is_array($raw) ? $raw : null;
    }

    /** @param array{offset:int,total:int,seeded:bool} $job */
    private function writeSchemaProgressJob(array $job): void
    {
        $path = $this->schemaProgressJobPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法写入安装进度目录');
        }
        file_put_contents($path, json_encode($job, JSON_UNESCAPED_UNICODE));
    }

    private function schemaProgressJobPath(): string
    {
        return rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
            . 'runtime' . DIRECTORY_SEPARATOR . 'install_schema_progress.json';
    }

    public function runNextMigration(): ServiceResult
    {
        // 客户轨：盘上无 migrate_*.php 时直接 done（schema.sql 已建库），避免误依赖缺失 bootstrap
        if ($this->onDiskMigrateScriptCount() < 1) {
            $tables = $this->countPrefixedTablesFromEnv();

            return ServiceResult::ok([
                'done'              => true,
                'ran'               => 0,
                'pending'           => 0,
                'migration_total'   => $tables > 0 ? $tables : 1,
                'migration_applied' => $tables > 0 ? $tables : 1,
                'table_count'       => $tables,
                'name'              => 'schema_sql_baseline',
            ], '建表已由 schema.sql 完成（' . $tables . ' 张表），无需迁移脚本');
        }

        $migrate = $this->schemaMigrationService->runNext(false);
        if (!$migrate->isOk()) {
            throw new \RuntimeException((string) ($migrate->message() ?: '数据库迁移失败'));
        }
        $data = $migrate->dataArray();
        $data['table_count'] = $this->countPrefixedTablesFromEnv();
        $msg = (string) ($migrate->message() ?: '数据库迁移');
        if (!empty($data['name'])) {
            $applied = (int) ($data['migration_applied'] ?? 0);
            $total   = (int) ($data['migration_total'] ?? 0);
            $pending = (int) ($data['pending'] ?? 0);
            $tables  = (int) ($data['table_count'] ?? 0);
            $msg = '迁移 ' . ($total > 0 ? ($applied . '/' . $total) : '1/1')
                . ' · 剩余 ' . $pending
                . ' · 已建 ' . $tables . ' 张表'
                . ' · ' . (string) $data['name'];
        }

        return ServiceResult::ok($data, $msg);
    }

    /** 盘上 migrate_*.php 数量（含 weapp 下 database/migrations，不加载 bootstrap） */
    private function onDiskMigrateScriptCount(): int
    {
        $count = 0;
        $root = ProjectPaths::migrationsDir();
        if (is_dir($root)) {
            foreach (glob($root . DIRECTORY_SEPARATOR . 'migrate_*.php') ?: [] as $_) {
                $count++;
            }
            foreach (glob($root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'migrate_*.php') ?: [] as $_) {
                $count++;
            }
        }
        $weapp = rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR . 'weapp';
        if (is_dir($weapp)) {
            foreach (glob($weapp . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . 'migrate_*.php') ?: [] as $_) {
                $count++;
            }
        }

        return $count;
    }

    /** CLI finalize / 无人值守：子进程跑完全部 pending 迁移，避免同进程重复 include */
    public function runAllMigrationsViaCli(): ServiceResult
    {
        // 客户轨 zip 无 migrate_*.php：队列为空即视为结构已由 schema.sql 建好
        if ($this->schemaMigrationService->buildQueue() === []) {
            return ServiceResult::ok(['done' => true], '数据库结构已是最新（schema.sql 基线）');
        }
        $script = ProjectPaths::migrationsDir() . DIRECTORY_SEPARATOR . 'run.php';
        if (!\is_readable($script)) {
            $all = $this->schemaMigrationService->runAllPending();
            if (!$all->isOk()) {
                return ServiceResult::fail((string) ($all->message() ?: '数据库迁移失败'));
            }

            return ServiceResult::ok(['done' => true], (string) ($all->message() ?: '数据库迁移已完成'));
        }
        $run = CliProcessRunner::runPhpScript($script);
        if ((int) ($run['exit_code'] ?? 1) !== 0
            && !CliProcessRunner::isUnavailable($run)) {
            $out = trim((string) ($run['output'] ?? ''));

            return ServiceResult::fail($out !== '' ? $out : '数据库迁移失败');
        }
        if (CliProcessRunner::isUnavailable($run)) {
            $external = $this->runAllMigrationsViaNodeExternal();
            if ($external !== null) {
                return $external;
            }
            $all = $this->schemaMigrationService->runAllPending();
            if (!$all->isOk()) {
                return ServiceResult::fail((string) ($all->message() ?: '数据库迁移失败'));
            }

            return ServiceResult::ok(['done' => true], (string) ($all->message() ?: '数据库迁移已完成'));
        }

        return ServiceResult::ok(['done' => true], '数据库迁移已完成');
    }

    public function runEmbeddedSetupScript(string $script): int
    {
        return $this->executeSetupScript($script);
    }

    /**
     * @param array<string, string> $config
     * @return list<string>
     */
    private function listPrefixedTables(array $config): array
    {
        try {
            $pdo = $this->connect($config);
            $pfx = trim($config['db_prefix'] ?? 'pv_');
            $dbName = (string) ($config['db_name'] ?? '');
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pfx) . '%';
            $stmt = $pdo->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ? ESCAPE \'\\\\\''
            );
            $stmt->execute([$dbName, $like]);
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            return \is_array($tables) ? array_values(array_map('strval', $tables)) : [];
        } catch (\Throwable $e) {
            OpsLog::businessWarning('install_list_tables_failed', ['msg' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 仅 DROP 白名单内、且当前库真实存在的无前缀遗留表（不扫全库、不碰其它前缀）。
     *
     * @param array<string, string> $config
     * @return list<string>
     */
    private function listLegacyUnprefixedLeftoverTables(array $config): array
    {
        try {
            $pdo = $this->connect($config);
            $dbName = (string) ($config['db_name'] ?? '');
            if ($dbName === '') {
                return [];
            }
            $found = [];
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            foreach (self::LEGACY_UNPREFIXED_LEFTOVER_TABLES as $name) {
                $stmt->execute([$dbName, $name]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $found[] = $name;
                }
            }

            return $found;
        } catch (\Throwable $e) {
            OpsLog::businessWarning('install_list_legacy_leftover_failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 库内已有迁移记录时跳过 init_db/init_document（避免在已演进表结构上重插种子数据）
     */
    private function schemaSetupAlreadyInitialized(): bool
    {
        try {
            ['pdo' => $pdo, 'pfx' => $pfx] = $this->connectFromSiteEnv();
            $dbName = (string) ($this->readExistingDatabaseDefaults()['db_name'] ?? '');
            $stmt   = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $stmt->execute([$dbName, $pfx . 'schema_migrations']);
            if ((int) $stmt->fetchColumn() === 0) {
                return false;
            }

            $migCount = (int) $pdo->query(
                'SELECT COUNT(*) FROM `' . str_replace('`', '``', $pfx . 'schema_migrations') . '`'
            )->fetchColumn();
            if ($migCount < 1) {
                return false;
            }

            // 半装态：有 schema 基线但缺 super_admin → 继续跑 init_db 种子
            $stmt->execute([$dbName, $pfx . 'roles']);
            if ((int) $stmt->fetchColumn() === 0) {
                return false;
            }
            $roleCount = (int) $pdo->query(
                'SELECT COUNT(*) FROM `' . str_replace('`', '``', $pfx . 'roles') . '` WHERE `code` = '
                . $pdo->quote('super_admin')
            )->fetchColumn();

            return $roleCount > 0;
        } catch (\Throwable $e) {
            OpsLog::businessWarning('install_schema_migrations_probe_failed', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 客户轨：导入 install/setup/schema.sql（前缀占位 __DB_PREFIX__）
     */
    private function importSchemaSqlFile(string $path): void
    {
        ['pdo' => $pdo, 'pfx' => $pfx] = $this->connectFromSiteEnv();
        $sql = (string) file_get_contents($path);
        if (trim($sql) === '') {
            throw new \RuntimeException('schema.sql 为空');
        }
        $sql = str_replace('__DB_PREFIX__', $pfx, $sql);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->splitSqlStatements($sql) as $stmt) {
            $pdo->exec($stmt);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** 写入基线标记，避免重跑 schema setup；客户包无 migrate_*.php 时迁移步直接 done */
    private function stampSchemaSqlBaseline(): void
    {
        ['pdo' => $pdo, 'pfx' => $pfx] = $this->connectFromSiteEnv();
        $table = '`' . str_replace('`', '``', $pfx . 'schema_migrations') . '`';
        $name = 'community_schema_sql_baseline';
        $exists = (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE `name` = " . $pdo->quote($name))->fetchColumn();
        if ($exists > 0) {
            return;
        }
        $pdo->exec(
            "INSERT INTO {$table} (`name`, `applied_at`) VALUES ("
            . $pdo->quote($name) . ', ' . $pdo->quote(date('Y-m-d H:i:s')) . ')'
        );
    }

    /**
     * @return array{pdo:PDO,pfx:string}
     */
    private function connectFromSiteEnv(): array
    {
        $defaults = $this->readExistingDatabaseDefaults();
        $pdo = $this->connect($defaults);

        return ['pdo' => $pdo, 'pfx' => trim((string) ($defaults['db_prefix'] ?? 'pv_')) ?: 'pv_'];
    }

    /**
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }
            $out[] = $part;
        }

        return $out;
    }

    /**
     * @param array<string, string> $config
     */
    private function connect(array $config): PDO
    {
        $host = preg_replace('/[^a-zA-Z0-9._:\-\[\]]/', '', (string) ($config['db_host'] ?? '127.0.0.1')) ?: '127.0.0.1';
        $port = preg_replace('/[^0-9]/', '', (string) ($config['db_port'] ?? '3306')) ?: '3306';
        $name = preg_replace('/[^a-zA-Z0-9._\-]/', '', (string) ($config['db_name'] ?? ''));
        $user = (string) ($config['db_user'] ?? '');
        $pass = (string) ($config['db_pass'] ?? '');

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function executeSetupScript(string $script): int
    {
        $result = CliProcessRunner::runPhpScript($script);
        $forceEmbed = CliProcessRunner::isUnavailable($result)
            || (int) ($result['exit_code'] ?? 1) !== 0;

        // 宝塔等：PATH 无 php / 调到 php-fpm 时 exit≠0，一律改走 include，避免装库卡死
        if (!$forceEmbed) {
            return (int) $result['exit_code'];
        }

        if (!\defined('PIVARK_MIGRATION_EMBEDDED')) {
            \define('PIVARK_MIGRATION_EMBEDDED', true);
        }

        \ob_start();
        $code = 0;
        try {
            include $script;
        } catch (\MigrationEmbeddedExit $e) {
            $code = max(0, (int) $e->getCode());
        } catch (\Throwable $e) {
            $code = 1;
        }
        \ob_end_clean();

        return $code;
    }

    /** exec/proc_open 禁用时：Node 逐条 spawn php 迁移（避免 migration_down 重声明） */
    private function runAllMigrationsViaNodeExternal(): ?ServiceResult
    {
        if (CliProcessRunner::canShellFunction('exec') || CliProcessRunner::canShellFunction('shell_exec')) {
            return null;
        }
        $nodeScript = ProjectPaths::optionalWorkspaceToolsFile([
            'test',
            'scripts',
            'qa',
            'qa_migrations_run_external.mjs',
        ]);
        if ($nodeScript === '' || !\is_readable($nodeScript)) {
            return null;
        }
        $node = \trim((string) (getenv('PIVARK_NODE_BIN') ?: 'node'));
        $siteRoot = ProjectPaths::root();
        $cmd = \escapeshellarg($node) . ' ' . \escapeshellarg($nodeScript) . ' ' . \escapeshellarg($siteRoot);
        $output = '';
        $exitCode = 1;
        if (TrustedShellRunner::canPassthru()) {
            \ob_start();
            $exitCode = TrustedShellRunner::passthru($cmd);
            $output = (string) \ob_get_clean();
        } else {
            return null;
        }
        if ((int) $exitCode !== 0) {
            $tail = \trim(\implode("\n", \array_slice(\explode("\n", $output), -12)));

            return ServiceResult::fail($tail !== '' ? $tail : 'Node 外部迁移失败（exit ' . $exitCode . '）');
        }

        return ServiceResult::ok(['done' => true], '数据库迁移已完成（Node external）');
    }
}
