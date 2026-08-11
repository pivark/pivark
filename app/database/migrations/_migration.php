<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 数据库迁移公共工具（PDO、版本表、事务包装）
 */
declare(strict_types=1);


use app\common\service\plugin\extension\PluginPortalInvoke;
if (defined('PIVARK_MIGRATION_BOOTSTRAP')) {
    return;
}
define('PIVARK_MIGRATION_BOOTSTRAP', true);

/** Web 安装向导内嵌执行迁移时，替代 exit() 以免中断 JSON 响应 */
if (!\class_exists('MigrationEmbeddedExit', false)) {
    final class MigrationEmbeddedExit extends \RuntimeException
    {
    }
}

if (!\function_exists('migration_exit')) {
    function migration_exit(int $code = 0): never
    {
        if (\defined('PIVARK_MIGRATION_EMBEDDED') && PIVARK_MIGRATION_EMBEDDED) {
            throw new MigrationEmbeddedExit('migration exit', $code);
        }

        exit($code);
    }
}

if (!\function_exists('migration_stderr')) {
    function migration_stderr(string $message): void
    {
        if (\defined('STDERR') && \is_resource(\STDERR)) {
            \fwrite(\STDERR, $message);

            return;
        }
        echo $message;
    }
}

function migration_table_exists(PDO $pdo, string $db, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([$db, $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function migration_skip_when_table_missing(PDO $pdo, string $pfx, string $db, string $name, string $tableSuffix): bool
{
    if (migration_table_exists($pdo, $db, $pfx . $tableSuffix)) {
        return false;
    }
    migration_mark_applied($pdo, $pfx, $name);
    echo "=== {$name} skipped ({$tableSuffix} missing) ===\n";

    return true;
}

function migration_normalize_site_name(string $raw): string
{
    if (class_exists(\app\common\service\site\SiteBrandService::class)) {
        return app(\app\common\service\site\SiteBrandService::class)->sanitizeSiteNameInput($raw);
    }

    return trim(strip_tags($raw));
}

/** 迁移目录 → 项目根（SSOT：devtools/daily/schema/migrations） */
function migration_read_source(string $file): string
{
    if (!is_file($file) || !is_readable($file)) {
        return '';
    }
    $src = file_get_contents($file);

    return is_string($src) ? $src : '';
}

function migration_project_root(): string
{
    $laneRoot = trim((string) (getenv('PIVARK_LANE_ROOT') ?: ''));
    $laneRoot = $laneRoot !== '' ? rtrim(str_replace('\\', '/', $laneRoot), '/') : '';
    if ($laneRoot !== '' && is_file($laneRoot . '/data/site.env')) {
        return $laneRoot;
    }

    $dir = str_replace('\\', '/', __DIR__);
    // 升级包解压工作区：…/app/database/migrations
    if (
        basename($dir) === 'migrations'
        && basename(dirname($dir)) === 'database'
        && basename(dirname($dir, 2)) === 'app'
    ) {
        return dirname($dir, 3);
    }
    // dig 研发仓：…/devtools/daily/schema/migrations
    if (
        basename($dir) === 'migrations'
        && basename(dirname($dir)) === 'schema'
        && basename(dirname($dir, 2)) === 'daily'
    ) {
        return dirname($dir, 4);
    }

    $cursor = $dir;
    for ($i = 0; $i < 8; ++$i) {
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            break;
        }
        $cursor = $parent;
        if (is_file($cursor . '/data/site.env') || is_file($cursor . '/composer.json')) {
            return $cursor;
        }
    }

    return dirname($dir, 4);
}

function migration_env_path(string $root): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $site = $root . '/data/site.env';
    if (is_readable($site)) {
        return $site;
    }
    $legacy = $root . '/.env';
    if (is_readable($legacy)) {
        return $legacy;
    }
    migration_stderr("data/site.env or .env not found\n");
    migration_exit(1);
}

/**
 * QA 安装/rollback lane：PIVARK_QA_DB_NAME=qa_* 时覆盖连接目标（禁止 dev/b/demo 库）。
 *
 * @return array{host:string,port:string,db:string,user:string,pass:string,pfx:string,qa_lane:bool}
 */
function migration_resolve_db_env(string $root): array
{
    $envPath = migration_env_path($root);
    $env     = parse_ini_file($envPath) ?: [];
    $host    = (string) ($env['DB_HOST'] ?? '127.0.0.1');
    $port    = (string) ($env['DB_PORT'] ?? '3306');
    $db      = (string) ($env['DB_NAME'] ?? '');
    $user    = (string) ($env['DB_USER'] ?? '');
    $pass    = (string) ($env['DB_PASS'] ?? '');
    $pfx     = (string) ($env['DB_PREFIX'] ?? 'pv_');
    $qaLane  = false;

    $qaName = trim((string) (getenv('PIVARK_QA_DB_NAME') ?: ''));
    if ($qaName !== '') {
        $blocked = ['dev_pivark_com', 'b_pivark_com', 'demo1_pivark_com', 'demo2_pivark_com'];
        if (in_array($qaName, $blocked, true)) {
            migration_stderr("PIVARK_QA_DB_NAME refused: {$qaName}\n");
            migration_exit(1);
        }
        foreach (['dev_', 'b_', 'demo1_', 'demo2_'] as $prefix) {
            if (str_starts_with($qaName, $prefix)) {
                migration_stderr("PIVARK_QA_DB_NAME refused prefix {$prefix} on {$qaName}\n");
                migration_exit(1);
            }
        }
        if (!str_starts_with($qaName, 'qa_')) {
            migration_stderr("PIVARK_QA_DB_NAME must be qa_* prefix, got {$qaName}\n");
            migration_exit(1);
        }
        $db     = $qaName;
        $qaLane = true;
        $qaUser = trim((string) (getenv('PIVARK_QA_DB_USER') ?: ''));
        $qaPass = (string) (getenv('PIVARK_QA_DB_PASS') ?: '');
        if ($qaUser !== '') {
            $user = $qaUser;
        }
        if ($qaPass !== '' || getenv('PIVARK_QA_DB_PASS') !== false) {
            $pass = $qaPass;
        }
        $qaHost = trim((string) (getenv('PIVARK_QA_DB_HOST') ?: ''));
        $qaPort = trim((string) (getenv('PIVARK_QA_DB_PORT') ?: ''));
        if ($qaHost !== '') {
            $host = $qaHost;
        }
        if ($qaPort !== '') {
            $port = $qaPort;
        }
    }

    return [
        'host'    => $host,
        'port'    => $port,
        'db'      => $db,
        'user'    => $user,
        'pass'    => $pass,
        'pfx'     => $pfx,
        'qa_lane' => $qaLane,
    ];
}

/**
 * @return array{pdo: PDO, pfx: string, db: string}
 */
function migration_bootstrap(string $root): array
{
    $cfg  = migration_resolve_db_env($root);
    $host = $cfg['host'];
    $port = $cfg['port'];
    $db   = $cfg['db'];
    $user = $cfg['user'];
    $pass = $cfg['pass'];
    $pfx  = $cfg['pfx'];

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        migration_stderr('DB connect failed: ' . $e->getMessage() . "\n");
        migration_exit(1);
    }

    if ($cfg['qa_lane']) {
        echo "=== QA lane DB {$db} ===\n";
    }

    return ['pdo' => $pdo, 'pfx' => $pfx, 'db' => $db];
}

/** 迁移脚本需 ConfigService 等：优先 app/bootstrap（发行包） */
function migration_require_app(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $root = migration_project_root();
    $candidates = [
        $root . '/app/bootstrap/cli.php',
    ];
    $toolsCli = $root . '/devtools/daily/bootstrap/cli.php';
    if (is_dir($root . '/devtools') && is_readable($toolsCli)) {
        $candidates[] = $toolsCli;
    }
    foreach ($candidates as $cli) {
        if (!is_readable($cli)) {
            continue;
        }
        require_once $cli;
        if (\function_exists('pivark_app')) {
            pivark_app();
            $loaded = true;

            return;
        }
    }
    migration_stderr("App bootstrap not found: app/bootstrap/cli.php\n");
    migration_exit(1);
}

/** 与 {@see \app\common\support\AppTime} 默认时区一致（PDO 迁移无需 ThinkPHP bootstrap） */
function migration_now(): string
{
    static $tz = null;
    if ($tz === null) {
        $tz = new \DateTimeZone('Asia/Shanghai');
    }

    return (new \DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
}

/**
 * 按 comment_map 补全表/字段中文 COMMENT（幂等：仅修改 COMMENT 不一致的列）
 *
 * @return array{tables:int, columns:int, skipped:int}
 */
function migration_apply_comment_map(PDO $pdo, string $pfx, array $map): array
{
    $buildModifySql = static function (array $col, string $comment): string {
        $field = $col['Field'];
        $sql   = "`{$field}` {$col['Type']}";
        $sql .= ($col['Null'] === 'NO') ? ' NOT NULL' : ' NULL';
        $default = $col['Default'];
        if ($default !== null) {
            if (strtoupper((string) $default) === 'CURRENT_TIMESTAMP') {
                $sql .= ' DEFAULT CURRENT_TIMESTAMP';
            } elseif ($default !== 'NULL') {
                $sql .= is_numeric($default)
                    ? " DEFAULT {$default}"
                    : " DEFAULT '" . str_replace("'", "''", (string) $default) . "'";
            }
        }
        $extra = $col['Extra'] ?? '';
        if ($extra !== '') {
            if (stripos($extra, 'on update CURRENT_TIMESTAMP') !== false) {
                if (stripos($sql, 'DEFAULT CURRENT_TIMESTAMP') === false) {
                    $sql .= ' DEFAULT CURRENT_TIMESTAMP';
                }
                $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
            } elseif (stripos($extra, 'auto_increment') !== false) {
                $sql .= ' AUTO_INCREMENT';
            }
        }
        return $sql . " COMMENT '" . str_replace("'", "''", $comment) . "'";
    };

    $tables = 0;
    $columns = 0;
    $skipped = 0;

    foreach ($map as $logical => $def) {
        $table = $pfx . $logical;
        $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
        if (!$exists) {
            continue;
        }
        $pdo->exec("ALTER TABLE `{$table}` COMMENT = '" . str_replace("'", "''", $def['table']) . "'");
        $tables++;
        $cols = $pdo->query("SHOW FULL COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            $name = $col['Field'];
            if (!isset($def['columns'][$name])) {
                $skipped++;
                continue;
            }
            $target = $def['columns'][$name];
            $current = (string) ($col['Comment'] ?? '');
            if ($current === $target) {
                continue;
            }
            $modify = $buildModifySql($col, $target);
            $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN {$modify}");
            $columns++;
        }
    }

    return ['tables' => $tables, 'columns' => $columns, 'skipped' => $skipped];
}

function migration_ensure_versions_table(PDO $pdo, string $pfx): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}schema_migrations` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `name` varchar(100) NOT NULL COMMENT '迁移脚本名',
        `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '执行时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_migration_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='已执行迁移版本'");
}

function migration_has_applied(PDO $pdo, string $pfx, string $name): bool
{
    migration_ensure_versions_table($pdo, $pfx);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$pfx}schema_migrations` WHERE `name` = ?");
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn() > 0;
}

function migration_mark_applied(PDO $pdo, string $pfx, string $name): void
{
    $stmt = $pdo->prepare("INSERT INTO `{$pfx}schema_migrations` (`name`) VALUES (?)");
    $stmt->execute([$name]);
}

function migration_unmark_applied(PDO $pdo, string $pfx, string $name): void
{
    migration_ensure_versions_table($pdo, $pfx);
    $stmt = $pdo->prepare("DELETE FROM `{$pfx}schema_migrations` WHERE `name` = ? LIMIT 1");
    $stmt->execute([$name]);
}

/** 脚本是否声明了 down（migration_entry 第三参闭包；禁止再写全局 migration_down） */
function migration_file_supports_down(string $file): bool
{
    $src = migration_read_source($file);
    if ($src === '') {
        return false;
    }
    if (preg_match('/function\s+migration_down\s*\(/', $src) === 1) {
        return true;
    }

    // migration_entry($name, $up, static function (...) { ... })
    return preg_match(
        '/migration_entry\s*\(\s*\$name\s*,[\s\S]*,\s*(?:static\s+)?function\s*\(\s*PDO\s+\$pdo/s',
        $src
    ) === 1;
}

/**
 * 新迁移 SSOT：up 闭包 + 可选 down 闭包（禁止再声明全局 function migration_down）。
 * down 时设 PIVARK_MIGRATION_DOWN_ONLY=1 再 include。
 *
 * @param callable(PDO $pdo, string $pfx, string $db): void      $up
 * @param callable(PDO $pdo, string $pfx, string $db): void|null $down
 */
function migration_entry(string $name, callable $up, ?callable $down = null): void
{
    $root = migration_project_root();
    ['pdo' => $pdo, 'pfx' => $pfx, 'db' => $db] = migration_bootstrap($root);

    $downOnly = (\defined('PIVARK_MIGRATION_DOWN_ONLY') && PIVARK_MIGRATION_DOWN_ONLY)
        || \in_array(
            \strtolower(\trim((string) \getenv('PIVARK_MIGRATION_DOWN_ONLY'))),
            ['1', 'true', 'yes'],
            true
        );

    if ($downOnly) {
        if ($down === null) {
            migration_stderr("{$name}: migration_entry 未提供 down 闭包，无法 rollback\n");
            migration_exit(1);
        }
        if (!migration_has_applied($pdo, $pfx, $name)) {
            migration_stderr("{$name}: 未执行过，无法 rollback\n");
            migration_exit(1);
        }
        $down($pdo, $pfx, $db);
        migration_unmark_applied($pdo, $pfx, $name);
        echo "=== {$name} rolled back ===\n";
        migration_exit(0);
    }

    $argv  = $GLOBALS['argv'] ?? [];
    $force = \in_array('--force', $argv, true);
    if (!$force && migration_has_applied($pdo, $pfx, $name)) {
        echo "=== {$name} already applied, skip ===\n";
        migration_exit(0);
    }

    $up($pdo, $pfx, $db);
    migration_mark_applied($pdo, $pfx, $name);
    echo "=== {$name} done ===\n";
    migration_exit(0);
}

/**
 * 不可逆迁移 SSOT：仅 forward；rollback CLI 拒绝执行。
 * 须写明理由（数据清洗/删行/无法无损还原）；升级前须全库备份。
 *
 * @param callable(PDO $pdo, string $pfx, string $db): void $up
 */
function migration_irreversible(string $name, string $reason, callable $up): void
{
    $root = migration_project_root();
    ['pdo' => $pdo, 'pfx' => $pfx, 'db' => $db] = migration_bootstrap($root);

    $downOnly = (\defined('PIVARK_MIGRATION_DOWN_ONLY') && PIVARK_MIGRATION_DOWN_ONLY)
        || \in_array(
            \strtolower(\trim((string) \getenv('PIVARK_MIGRATION_DOWN_ONLY'))),
            ['1', 'true', 'yes'],
            true
        );
    if ($downOnly) {
        migration_stderr("{$name}: irreversible — {$reason}\n");
        migration_exit(1);
    }

    $argv  = $GLOBALS['argv'] ?? [];
    $force = \in_array('--force', $argv, true);
    if (!$force && migration_has_applied($pdo, $pfx, $name)) {
        echo "=== {$name} already applied, skip ===\n";
        migration_exit(0);
    }

    $up($pdo, $pfx, $db);
    migration_mark_applied($pdo, $pfx, $name);
    echo "=== {$name} done (irreversible: {$reason}) ===\n";
    migration_exit(0);
}

/** 脚本是否声明 migration_irreversible() */
function migration_file_is_irreversible(string $file): bool
{
    $src = migration_read_source($file);

    return $src !== '' && preg_match('/migration_irreversible\s*\(/', $src) === 1;
}

/**
 * 幂等写入 cron_jobs（已存在同 handler 则跳过）
 */
function migration_ensure_cron_job(
    PDO $pdo,
    string $pfx,
    string $handler,
    string $name,
    int $intervalMinutes,
    ?string $payload = null
): void {
    $jobs = "`{$pfx}cron_jobs`";
    $stmt = $pdo->prepare("SELECT id FROM {$jobs} WHERE handler = ? LIMIT 1");
    $stmt->execute([$handler]);
    if ($stmt->fetchColumn()) {
        return;
    }
    $now = migration_now();
    $payloadSql = $payload === null ? 'NULL' : $pdo->quote($payload);
    $pdo->exec(
        "INSERT INTO {$jobs} (`name`,`handler`,`interval_minutes`,`payload`,`status`,`next_run_at`,`created_at`,`updated_at`) VALUES "
        . '(' . $pdo->quote($name) . ',' . $pdo->quote($handler) . ",{$intervalMinutes},{$payloadSql},1,'{$now}','{$now}','{$now}')"
    );
    echo "  + cron {$handler}\n";
}

/**
 * 站点子菜单幂等写入：按 parent_id + route（不区分大小写）保留最小 id，删除其余重复行。
 */
function migration_upsert_site_menu(
    PDO $pdo,
    string $menuTable,
    int $parentId,
    string $title,
    string $route,
    string $permissionCode,
    string $icon = '',
    int $sort = 0
): void {
    $routeNorm = strtolower(trim($route));
    if ($routeNorm === '') {
        return;
    }

    $sel = $pdo->prepare(
        "SELECT id FROM `{$menuTable}` WHERE parent_id = ? AND LOWER(TRIM(route)) = ? ORDER BY id ASC"
    );
    $sel->execute([$parentId, $routeNorm]);
    $ids = $sel->fetchAll(PDO::FETCH_COLUMN);
    $keepId = (int) ($ids[0] ?? 0);

    if ($keepId > 0) {
        $upd = $pdo->prepare(
            "UPDATE `{$menuTable}` SET title = ?, permission_code = ?, icon = ?, sort = ?, status = 1
             WHERE id = ?"
        );
        $upd->execute([$title, $permissionCode, $icon, $sort, $keepId]);
        if (count($ids) > 1) {
            $extra = array_map('intval', array_slice($ids, 1));
            $pdo->exec(
                'DELETE FROM `' . $menuTable . '` WHERE id IN (' . implode(',', $extra) . ')'
            );
        }

        return;
    }

    $ins = $pdo->prepare(
        "INSERT INTO `{$menuTable}` (title, permission_code, parent_id, icon, route, params, sort, status, created_at)
         VALUES (?, ?, ?, ?, ?, NULL, ?, 1, NOW())"
    );
    $ins->execute([$title, $permissionCode, $parentId, $icon, $route, $sort]);
}

/**
 * 包裹 DML/可回滚步骤；DDL 在 MySQL 中会隐式提交，勿与 DDL 混在同一事务期望回滚。
 */
function migration_transaction(PDO $pdo, callable $fn): void
{
    $pdo->beginTransaction();
    try {
        $fn($pdo);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return callable(PDO, string, string, string): bool
 */
function migration_column_exists(string $db): callable
{
    return static function (PDO $pdo, string $pfx, string $table, string $column) use ($db): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$db, $pfx . $table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    };
}

function migration_index_exists(PDO $pdo, string $db, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$db, $table, $indexName]);

    return (int) $stmt->fetchColumn() > 0;
}

function migration_add_index(PDO $pdo, string $db, string $table, string $indexName, string $ddl): void
{
    if (migration_index_exists($pdo, $db, $table, $indexName)) {
        echo "  SKIP index {$indexName} on {$table}\n";

        return;
    }
    $pdo->exec($ddl);
    echo "  + index {$indexName} on {$table}\n";
}

function migration_drop_index(PDO $pdo, string $db, string $table, string $indexName): void
{
    if (!migration_index_exists($pdo, $db, $table, $indexName)) {
        echo "  SKIP drop index {$indexName} on {$table}\n";

        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
    echo "  - index {$indexName} on {$table}\n";
}

function migration_drop_column_if_exists(PDO $pdo, string $db, string $table, string $column): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$db, $table, $column]);
    if ((int) $stmt->fetchColumn() === 0) {
        echo "  SKIP drop column {$column} on {$table}\n";

        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
    echo "  - column {$column} on {$table}\n";
}

function migration_drop_table_if_exists(PDO $pdo, string $db, string $table): void
{
    if (!migration_table_exists($pdo, $db, $table)) {
        echo "  SKIP drop table {$table}\n";

        return;
    }
    $pdo->exec("DROP TABLE `{$table}`");
    echo "  - table {$table}\n";
}

/** 按 code 精确匹配删除权限及 role_permissions（不删子 code） */
function migration_remove_permission(PDO $pdo, string $pfx, string $code): void
{
    $permTable = $pfx . 'permissions';
    $rpTable   = $pfx . 'role_permissions';
    $stmt      = $pdo->prepare("SELECT id FROM `{$permTable}` WHERE `code` = ? LIMIT 1");
    $stmt->execute([$code]);
    $permId = (int) $stmt->fetchColumn();
    if ($permId < 1) {
        echo "  SKIP permission {$code}\n";

        return;
    }
    $pdo->exec("DELETE FROM `{$rpTable}` WHERE permission_id = {$permId}");
    $pdo->exec("DELETE FROM `{$permTable}` WHERE id = {$permId}");
    echo "  - permission {$code}\n";
}

/** 删除 root code 及其子 code（如 admin.plugin.payment.%） */
function migration_remove_permission_tree(PDO $pdo, string $pfx, string $rootCode): void
{
    $permTable = $pfx . 'permissions';
    $rpTable   = $pfx . 'role_permissions';
    $like      = $rootCode . '.%';
    $stmt      = $pdo->prepare(
        "SELECT id FROM `{$permTable}` WHERE `code` = ? OR `code` LIKE ?"
    );
    $stmt->execute([$rootCode, $like]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) {
        echo "  SKIP permission tree {$rootCode}\n";

        return;
    }
    $idList = implode(',', $ids);
    $pdo->exec("DELETE FROM `{$rpTable}` WHERE permission_id IN ({$idList})");
    $pdo->exec("DELETE FROM `{$permTable}` WHERE id IN ({$idList})");
    echo "  - permission tree {$rootCode}\n";
}

function migration_remove_cron_job(PDO $pdo, string $pfx, string $handler): void
{
    $jobs = "`{$pfx}cron_jobs`";
    $stmt = $pdo->prepare("DELETE FROM {$jobs} WHERE handler = ? LIMIT 1");
    $stmt->execute([$handler]);
    if ($stmt->rowCount() > 0) {
        echo "  - cron {$handler}\n";
    } else {
        echo "  SKIP cron {$handler}\n";
    }
}

/** Think 引导：runtime 与 index.php 一致（FPM 元数据/页缓存 SSOT） */
function migration_think_app(string $root): \think\App
{
    if (!class_exists(\Composer\Autoload\ClassLoader::class, false)) {
        require_once $root . '/vendor/autoload.php';
    }
    \app\common\support\SiteEnv::injectIntoProcessEnv($root);
    $runtimePath = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR;
    $app         = new think\App($root);
    $app->setRuntimePath($runtimePath);
    $app->initialize();

    return $app;
}

/** site_pages / 导航变更后：清 MetaSqlCache + 前台页缓存代际 */
function migration_invalidate_front_meta(string $root): void
{
    migration_think_app($root);
    app(\app\common\service\infra\FrontCacheInvalidator::class)->invalidateMeta();
    echo "  front meta cache invalidated\n";
}

/** 官网 help 单页 upsert（SSOT：DocsSectionPagesService::requiredSitePages） */
function migration_upsert_required_site_pages(PDO $pdo, string $pfx): void
{
    $t   = "`{$pfx}site_pages`";
    $now = migration_now();
    $pages = \app\common\service\plugin\extension\PluginPortalInvoke::portalInvoke('DocsSectionPagesService', 'requiredSitePages', []);
    if (!is_array($pages)) {
        return;
    }
    foreach ($pages as $page) {
        $path = trim((string) ($page['path'] ?? ''), '/');
        $tpl  = trim((string) ($page['tpl_name'] ?? 'list_page_docs_section'));
        $stmt = $pdo->prepare("SELECT id FROM {$t} WHERE path = ? LIMIT 1");
        $stmt->execute([$path]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $pdo->prepare("UPDATE {$t} SET status = 1, tpl_name = ?, title = ?, seo_description = ?, updated_at = ? WHERE id = ?")
                ->execute([$tpl, $page['title'], $page['seo_description'], $now, $id]);
            echo "  site_pages {$path} updated\n";
            continue;
        }
        $pdo->prepare("INSERT INTO {$t} (path, tpl_name, title, seo_title, seo_description, content, status, created_at, updated_at) VALUES (?,?,?,?,?,?,1,?,?)")
            ->execute([
                $path,
                $tpl,
                $page['title'],
                $page['title'] . ' · 元舟 PivArk',
                $page['seo_description'],
                '',
                $now,
                $now,
            ]);
        echo "  site_pages {$path} OK\n";
    }
}

/** 导航 seed + 前台缓存失效（Think 已 bootstrap 时传 $bootThink=false） */
function migration_apply_www_help_after_db(string $root, bool $bootThink = true): void
{
    if ($bootThink) {
        migration_think_app($root);
    }
    if (\app\common\support\SeedExecutionGuard::shouldSkip('www_migration_nav')) {
        echo '  SKIP www nav seed: ' . \app\common\support\SeedExecutionGuard::skipReasonForLog('www_migration_nav') . "\n";

        return;
    }
    \app\common\service\plugin\extension\PluginPortalInvoke::portalInvoke('SiteNavSeedService', 'applyWithThink', []);
    app(\app\common\service\infra\FrontCacheInvalidator::class)->invalidateMeta();
    echo "  front meta cache invalidated\n";
}

/** menu/ 子目录迁移 basename 列表（SSOT，供 baseline 批量标记） */
function migration_menu_data_basenames(): array
{
    $dir = __DIR__ . '/menu';
    if (!is_dir($dir)) {
        return [];
    }
    $names = [];
    foreach (glob($dir . '/migrate_*.php') ?: [] as $file) {
        $base = basename($file, '.php');
        if ($base !== 'migrate_menu_ssot_baseline') {
            $names[] = $base;
        }
    }
    sort($names);

    return $names;
}

function migration_mark_applied_bulk(PDO $pdo, string $pfx, array $names): int
{
    $marked = 0;
    foreach ($names as $name) {
        $name = trim((string) $name);
        if ($name === '' || migration_has_applied($pdo, $pfx, $name)) {
            continue;
        }
        migration_mark_applied($pdo, $pfx, $name);
        $marked++;
    }

    return $marked;
}

function migration_menu_ssot_baseline_applied(PDO $pdo, string $pfx): bool
{
    return migration_has_applied($pdo, $pfx, 'migrate_menu_ssot_baseline');
}

/** @return list<array<string, mixed>> */
function migration_load_admin_menu_ssot_seed(): array
{
    $file = __DIR__ . '/../seeds/admin_menu_ssot.php';
    if (!is_readable($file)) {
        return [];
    }
    $rows = require $file;
    if (!is_array($rows)) {
        return [];
    }

    // 禁重复 PHP 键/重复 id 静默丢行（曾导致「搜索管理」整行消失）
    $out = [];
    $seenIds = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        if (isset($seenIds[$id])) {
            throw new RuntimeException(
                "admin_menu_ssot duplicate menu id={$id} ({$seenIds[$id]} vs " . (string) ($row['title'] ?? '') . ')'
            );
        }
        $seenIds[$id] = (string) ($row['title'] ?? '');
        $out[] = $row;
    }

    return $out;
}

function migration_apply_admin_menu_ssot(PDO $pdo, string $pfx): int
{
    $table = "`{$pfx}menus`";
    $rows  = migration_load_admin_menu_ssot_seed();
    if ($rows === []) {
        return 0;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO {$table}
            (`id`, `title`, `permission_code`, `parent_id`, `icon`, `route`, `params`, `sort`, `status`, `created_at`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            `title` = VALUES(`title`),
            `permission_code` = VALUES(`permission_code`),
            `parent_id` = VALUES(`parent_id`),
            `icon` = VALUES(`icon`),
            `route` = VALUES(`route`),
            `params` = VALUES(`params`),
            `sort` = VALUES(`sort`),
            `status` = VALUES(`status`)"
    );
    $applied = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $stmt->execute([
            $id,
            (string) ($row['title'] ?? ''),
            $row['permission_code'] ?? null,
            (int) ($row['parent_id'] ?? 0),
            $row['icon'] ?? null,
            $row['route'] ?? null,
            $row['params'] ?? null,
            (int) ($row['sort'] ?? 0),
            (int) ($row['status'] ?? 1),
        ]);
        $applied++;
    }

    return $applied;
}

/** 解析顶级「用户权限」分组（兼容旧名「用户与权限」） */
function migration_resolve_user_rbac_top_id(PDO $pdo, string $menuTable): int
{
    $stmt = $pdo->query(
        "SELECT `id`, `title` FROM `{$menuTable}`
         WHERE `parent_id` = 0 AND `status` = 1 AND `title` IN ('用户权限', '用户与权限')
         ORDER BY CASE `title` WHEN '用户权限' THEN 0 ELSE 1 END, `id` ASC"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($rows === []) {
        return 0;
    }
    $canonicalId = (int) ($rows[0]['id'] ?? 0);
    if ($canonicalId < 1) {
        return 0;
    }
    $pdo->exec(
        "UPDATE `{$menuTable}` SET `title` = '用户权限', `icon` = 'fa fa-users'
         WHERE `id` = {$canonicalId}"
    );
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1 || $id === $canonicalId) {
            continue;
        }
        $pdo->exec("UPDATE `{$menuTable}` SET `parent_id` = {$canonicalId} WHERE `parent_id` = {$id}");
        $pdo->exec("UPDATE `{$menuTable}` SET `status` = 0 WHERE `id` = {$id}");
    }

    return $canonicalId;
}

/** menu/ 增量迁移：baseline 已执行时直接标记并跳过（兼容 exec 子进程） */
function migration_auto_skip_menu_increment_if_baseline(): void
{
    /** @var list<string> baseline 后仍须执行的 menu 修复脚本 basename */
    static $alwaysRunAfterBaseline = [
        'migrate_menu_dedupe_user_rbac_v16',
        'migrate_menu_dedupe_route_dupes_v17',
        'migrate_menu_ssot_sync_v18',
        'migrate_menu_sidebar_weight_v19',
        'migrate_menu_sidebar_config_first_v20',
        'migrate_menu_data_retention_v21',
        'migrate_menu_hub_single_entry_v22',
        'migrate_menu_remove_cockpit_v23',
        'migrate_menu_enterprise_nav_placeholder_v24',
        'migrate_menu_product_center_kernel',
        'migrate_menu_product_center_hide_settings',
        'migrate_menu_product_center_settings_variant_naming',
        'migrate_menu_product_center_remove_shop_skus',
        'migrate_menu_product_center_children_fixup',
        'migrate_menu_nav_page_to_content_v25',
        'migrate_menu_hide_tag_group_v26',
        'migrate_menu_login_notice_settings',
        'migrate_menu_product_under_content_channels_v27',
        'migrate_menu_product_center_root_v28',
        'migrate_menu_product_after_content_v29',
        'migrate_menu_show_tag_group_under_content_v31',
        'migrate_menu_product_center_top_level_guard_v32',
        'migrate_menu_purge_host_plugin_ssot_static_v33',
        'migrate_menu_rate_limit_ops_v34',
        'migrate_menu_nav_title_plain_v35',
        'migrate_menu_seo_under_ops_v36',
        'migrate_menu_sql_console_ops_v37',
        'migrate_menu_tag_title_rename_v39',
        'migrate_menu_drop_weapp_schema_ui_v40',
    ];

    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
        $file = isset($frame['file']) ? str_replace('\\', '/', (string) $frame['file']) : '';
        if (!str_contains($file, '/migrations/menu/migrate_') || str_contains($file, 'migrate_menu_ssot_baseline')) {
            continue;
        }
        $name = basename($file, '.php');
        if (in_array($name, $alwaysRunAfterBaseline, true)) {
            return;
        }
        $root = migration_project_root();
        ['pdo' => $pdo, 'pfx' => $pfx] = migration_bootstrap($root);
        if (!migration_menu_ssot_baseline_applied($pdo, $pfx)) {
            return;
        }
        if (!migration_has_applied($pdo, $pfx, $name)) {
            migration_mark_applied($pdo, $pfx, $name);
        }
        echo "=== {$name} skip (menu SSOT baseline) ===\n";
        migration_exit(0);
    }
}

migration_auto_skip_menu_increment_if_baseline();

function migration_is_weapp_plugin_migration_path(string $file): bool
{
    $file = str_replace('\\', '/', $file);

    return (bool) preg_match('#/weapp/[^/]+/database/migrations/migrate_[^/]+\.php$#', $file);
}

function migration_is_kernel_plugin_migration_path(string $file): bool
{
    return str_contains(str_replace('\\', '/', $file), '/migrations/plugin/migrate_');
}

function migration_is_plugin_scoped_migration_path(string $file): bool
{
    return migration_is_kernel_plugin_migration_path($file) || migration_is_weapp_plugin_migration_path($file);
}

/** @return list<string> */
function migration_collect_plugin_migration_files(): array
{
    $files = glob(__DIR__ . '/plugin/migrate_*.php') ?: [];
    $root  = rtrim(str_replace('\\', '/', migration_project_root()), '/');
    $weapp = $root . '/weapp';
    if (!is_dir($weapp)) {
        return $files;
    }
    foreach (scandir($weapp) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $migDir = $weapp . '/' . $entry . '/database/migrations';
        if (!is_dir($migDir)) {
            continue;
        }
        foreach (glob($migDir . '/migrate_*.php') ?: [] as $file) {
            $files[] = $file;
        }
    }

    return $files;
}

/** plugin/ 无 DDL 的权限类迁移 basename（fast-path baseline 批量标记） */
function migration_plugin_perm_basenames(): array
{
    $names = [];
    foreach (migration_collect_plugin_migration_files() as $file) {
        $base = basename($file, '.php');
        if ($base === 'migrate_plugin_perm_registry_baseline') {
            continue;
        }
        $src = (string) file_get_contents($file);
        if (!str_contains($src, 'CREATE TABLE') && !str_contains($src, 'ALTER TABLE')
            && !str_contains($src, '/schema/') && !str_contains($src, 'install.sql')) {
            $names[] = $base;
        }
    }
    sort($names);

    return $names;
}

function migration_plugin_perm_baseline_applied(PDO $pdo, string $pfx): bool
{
    return migration_has_applied($pdo, $pfx, 'migrate_plugin_perm_registry_baseline');
}

/** plugin/ 权限增量：baseline 已执行时直接标记并跳过 */
function migration_auto_skip_plugin_perm_if_baseline(): void
{
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
        $file = isset($frame['file']) ? str_replace('\\', '/', (string) $frame['file']) : '';
        if (!migration_is_plugin_scoped_migration_path($file)
            || str_contains($file, 'migrate_plugin_perm_registry_baseline')
            || str_contains($file, 'migrate_plugin_schema_registry_baseline')) {
            continue;
        }
        $src = migration_read_source($file);
        if (str_contains($src, 'CREATE TABLE') || str_contains($src, 'ALTER TABLE')) {
            return;
        }
        if (str_contains($src, '/schema/') || str_contains($src, 'install.sql')) {
            return;
        }
        $root = migration_project_root();
        ['pdo' => $pdo, 'pfx' => $pfx] = migration_bootstrap($root);
        $name = basename($file, '.php');
        if (!migration_plugin_perm_baseline_applied($pdo, $pfx)) {
            return;
        }
        if (!migration_has_applied($pdo, $pfx, $name)) {
            migration_mark_applied($pdo, $pfx, $name);
        }
        echo "=== {$name} skip (plugin perm baseline) ===\n";
        migration_exit(0);
    }
}

migration_auto_skip_plugin_perm_if_baseline();
/** plugin/ 含 DDL 的迁移 basename */
function migration_plugin_ddl_basenames(): array
{
    $names = [];
    foreach (migration_collect_plugin_migration_files() as $file) {
        $base = basename($file, '.php');
        if (in_array($base, ['migrate_plugin_perm_registry_baseline', 'migrate_plugin_schema_registry_baseline'], true)) {
            continue;
        }
        $src = (string) file_get_contents($file);
        if (str_contains($src, 'CREATE TABLE') || str_contains($src, '/schema/') || str_contains($src, 'install.sql')) {
            $names[] = $base;
        }
    }
    sort($names);

    return $names;
}

function migration_plugin_schema_baseline_applied(PDO $pdo, string $pfx): bool
{
    return migration_has_applied($pdo, $pfx, 'migrate_plugin_schema_registry_baseline');
}
/** 从 plugin DDL 迁移源码解析表名（无 {{prefix}} 的 $create 调用） */
function migration_plugin_ddl_tables_from_source(string $src): array
{
    $tables = [];
    if (preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?\{\{prefix\}\}([a-z0-9_]+)`?/i', $src, $m)) {
        $tables = array_merge($tables, $m[1]);
    }
    if (preg_match_all('/CREATE TABLE `?\{\{prefix\}\}([a-z0-9_]+)`?/i', $src, $m)) {
        $tables = array_merge($tables, $m[1]);
    }
    if (preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`\{\$pfx\}([a-z0-9_]+)`/i', $src, $m)) {
        $tables = array_merge($tables, $m[1]);
    }
    if (preg_match_all("/\\\$create\(\\\$pdo,\s*\\\$db,\s*\\\$pfx,\s*'([a-z0-9_]+)'/i", $src, $m)) {
        $tables = array_merge($tables, $m[1]);
    }
    if (preg_match_all("/migration_add_index\([^,]+,[^,]+,[^,]+,\s*'([a-z0-9_]+)'/i", $src, $m)) {
        // skip index-only
    }

    return array_values(array_unique(array_filter($tables)));
}

/** plugin/ DDL 增量：schema baseline 后表已存在则标记跳过 */
function migration_auto_skip_plugin_ddl_if_schema_baseline(): void
{
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
        $file = isset($frame['file']) ? str_replace('\\', '/', (string) $frame['file']) : '';
        if (!migration_is_plugin_scoped_migration_path($file)
            || str_contains($file, 'migrate_plugin_perm_registry_baseline')
            || str_contains($file, 'migrate_plugin_schema_registry_baseline')) {
            continue;
        }
        $src = migration_read_source($file);
        if (!str_contains($src, 'CREATE TABLE') && !str_contains($src, '/schema/') && !str_contains($src, 'install.sql')) {
            return;
        }
        $root = migration_project_root();
        ['pdo' => $pdo, 'pfx' => $pfx] = migration_bootstrap($root);
        if (!migration_plugin_schema_baseline_applied($pdo, $pfx)) {
            return;
        }
        $name = basename($file, '.php');
        $tables = migration_plugin_ddl_tables_from_source($src);
        if ($tables === []) {
            return;
        }
        $allExist = true;
        foreach ($tables as $t) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$pfx . $t]);
            if ((int) $stmt->fetchColumn() === 0) {
                $allExist = false;
                break;
            }
        }
        if (!$allExist) {
            return;
        }
        if (!migration_has_applied($pdo, $pfx, $name)) {
            migration_mark_applied($pdo, $pfx, $name);
        }
        echo "=== {$name} skip (plugin DDL schema baseline) ===\n";
        migration_exit(0);
    }
}

migration_auto_skip_plugin_ddl_if_schema_baseline();