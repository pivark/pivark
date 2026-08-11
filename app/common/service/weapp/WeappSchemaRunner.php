<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\contract\WeappSchemaMigration as WeappSchemaMigrationContract;
use app\common\enum\ApiErrorCode;
use app\common\support\AppTime;
use app\common\support\PluginSqlRunner;
use app\common\support\ServiceResult;

use app\common\model\PluginSchemaVersion;
use app\common\service\infra\DistributedLockService;
use app\common\service\plugin\PluginService;
use think\facade\Db;

/** weapp 插件 schema 台阶执行器 */
final class WeappSchemaRunner
{
    public function __construct(
        private readonly DistributedLockService $distributedLock,
        private readonly PluginService $plugin,
    ) {
    }

    public function ensureVersionsTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        $pfx = (string) config('database.connections.mysql.prefix');
        PluginSqlRunner::executeBatch("CREATE TABLE IF NOT EXISTS `{$pfx}weapp_plugin_schema_versions` (
            `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
            `identifier` varchar(64) NOT NULL COMMENT '插件 identifier',
            `version` int unsigned NOT NULL DEFAULT 0 COMMENT '已应用最高台阶版本',
            `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '最后应用时间',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_identifier` (`identifier`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='weapp 插件 schema 版本'");
    }

    /**
     * @return array{
     *   identifier:string,
     *   applied_version:int,
     *   target_version:int,
     *   pending:list<int>,
     *   migrations:list<array{version:int,class:string}>
     * }
     */
    public function status(string $identifier): array
    {
        $identifier = $this->normalizeIdentifier($identifier);
        $this->ensureVersionsTable();
        $migrations = $this->discoverMigrations($identifier);
        $applied    = $this->appliedVersion($identifier);
        $target     = $migrations === [] ? $applied : (int) ($migrations[array_key_last($migrations)]['version'] ?? $applied);
        $pending    = [];
        foreach ($migrations as $row) {
            if ((int) $row['version'] > $applied) {
                $pending[] = (int) $row['version'];
            }
        }

        return [
            'identifier'      => $identifier,
            'applied_version' => $applied,
            'target_version'  => $target,
            'pending'         => $pending,
            'migrations'      => $migrations,
        ];
    }

    /**
     * @return ServiceResult
     */
    public function apply(string $identifier): ServiceResult
    {
        $identifier = $this->normalizeIdentifier($identifier);
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }

        $result = $this->distributedLock->using(
            'weapp_schema_apply:' . $identifier,
            120,
            fn (): ServiceResult => $this->applyUnlocked($identifier),
        );

        return $result ?? ServiceResult::fail('schema 迁移进行中，请稍后重试');
    }

    /**
     * @return ServiceResult
     */
    private function applyUnlocked(string $identifier): ServiceResult
    {
        $this->ensureVersionsTable();
        PluginService::registerAutoloadPublic($identifier);

        $ran     = [];
        $applied = $this->appliedVersion($identifier);
        foreach ($this->discoverMigrationClasses($identifier) as $class) {
            /** @var class-string<WeappSchemaMigrationContract> $class */
            $version = (int) $class::version();
            if ($version <= $applied) {
                continue;
            }
            if ($class::identifier() !== $identifier) {
                continue;
            }
            Db::startTrans();
            try {
                $class::up();
                $this->setAppliedVersion($identifier, $version);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();

                return ServiceResult::fail(
                    'schema 台阶 v' . $version . ' 失败：' . $e->getMessage(),
                    ApiErrorCode::VALIDATION,
                    ['failed_version' => $version, 'applied_version' => $applied]
                );
            }
            $applied = $version;
            $ran[]   = $version;
        }

        return ServiceResult::ok(['applied_version' => $applied, 'ran' => $ran], $ran === [] ? 'schema 已是最新' : '已应用台阶 ' . implode(',', array_map('strval', $ran)));
    }

    /**
     * dry-run：列出待执行台阶，不写入数据库
     *
     * @return ServiceResult
     */
    public function preview(string $identifier): ServiceResult
    {
        $identifier = $this->normalizeIdentifier($identifier);
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }

        $st = $this->status($identifier);
        $would = [];
        foreach ($st['migrations'] as $row) {
            $version = (int) ($row['version'] ?? 0);
            if ($version > (int) ($st['applied_version'] ?? 0)) {
                $would[] = [
                    'version' => $version,
                    'class'   => (string) ($row['class'] ?? ''),
                ];
            }
        }

        return ServiceResult::ok([
            'identifier'      => $identifier,
            'applied_version' => (int) ($st['applied_version'] ?? 0),
            'target_version'  => (int) ($st['target_version'] ?? 0),
            'would_apply'     => $would,
            'dry_run'         => true,
        ], $would === [] ? '无待执行台阶' : '将应用 ' . count($would) . ' 个台阶（未写入）');
    }

    /**
     * 回滚 schema 台阶（按 version 降序调用 down；targetVersion=0 表示清空全部已应用台阶）
     *
     * @return ServiceResult
     */
    public function rollback(string $identifier, int $targetVersion = 0): ServiceResult
    {
        $identifier = $this->normalizeIdentifier($identifier);
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }
        if ($targetVersion < 0) {
            return ServiceResult::fail('targetVersion 无效');
        }

        $result = $this->distributedLock->using(
            'weapp_schema_apply:' . $identifier,
            120,
            fn (): ServiceResult => $this->rollbackUnlocked($identifier, $targetVersion),
        );

        return $result ?? ServiceResult::fail('schema 迁移进行中，请稍后重试');
    }

    /**
     * @return ServiceResult
     */
    private function rollbackUnlocked(string $identifier, int $targetVersion): ServiceResult
    {
        $this->ensureVersionsTable();
        PluginService::registerAutoloadPublic($identifier);

        $applied = $this->appliedVersion($identifier);
        if ($applied <= $targetVersion) {
            return ServiceResult::ok(['applied_version' => $applied, 'ran' => []], 'schema 已处于目标版本或更低');
        }

        $ran = [];
        foreach (array_reverse($this->discoverMigrationClasses($identifier)) as $class) {
            /** @var class-string<WeappSchemaMigrationContract> $class */
            $version = (int) $class::version();
            if ($version <= $targetVersion || $version > $applied) {
                continue;
            }
            $class::down();
            $ran[] = $version;
        }

        $this->setAppliedVersion($identifier, $targetVersion);

        return ServiceResult::ok(['applied_version' => $targetVersion, 'ran' => $ran], $ran === [] ? '无可执行 down，已对齐版本号' : '已回滚台阶 ' . implode(',', array_map('strval', $ran)));
    }

    public function purgeVersionRecord(string $identifier): void
    {
        $identifier = $this->normalizeIdentifier($identifier);
        if ($identifier === '') {
            return;
        }
        $this->ensureVersionsTable();
        PluginSchemaVersion::where('identifier', $identifier)->delete();
    }

    public function appliedVersion(string $identifier): int
    {
        $this->ensureVersionsTable();
        $identifier = $this->normalizeIdentifier($identifier);
        if ($identifier === '') {
            return 0;
        }

        return (int) PluginSchemaVersion::where('identifier', $identifier)->value('version');
    }

    private function setAppliedVersion(string $identifier, int $version): void
    {
        $now = AppTime::now();
        $row = PluginSchemaVersion::where('identifier', $identifier)->find();
        if ($row) {
            PluginSchemaVersion::where('identifier', $identifier)->update([
                'version'    => $version,
                'applied_at' => $now,
            ]);

            return;
        }
        PluginSchemaVersion::insert([
            'identifier' => $identifier,
            'version'    => $version,
            'applied_at' => $now,
        ]);
    }

    /**
     * @return list<array{version:int,class:string}>
     */
    private function discoverMigrations(string $identifier): array
    {
        $out = [];
        foreach ($this->discoverMigrationClasses($identifier) as $class) {
            $out[] = ['version' => (int) $class::version(), 'class' => $class];
        }
        usort($out, static fn (array $a, array $b): int => $a['version'] <=> $b['version']);

        return $out;
    }

    /**
     * @return list<class-string<WeappSchemaMigrationContract>>
     */
    private function discoverMigrationClasses(string $identifier): array
    {
        $identifier = $this->normalizeIdentifier($identifier);
        if ($identifier === '') {
            return [];
        }
        PluginService::registerAutoloadPublic($identifier);
        $dir = $this->plugin->weappRoot() . $identifier . '/database';
        if (!is_dir($dir)) {
            return [];
        }
        $classes = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (!preg_match('/SchemaMigration.*\.php$/i', $name)) {
                continue;
            }
            $base  = preg_replace('/\.php$/', '', $name) ?? $name;
            $class = 'weapp\\' . $identifier . '\\database\\' . $base;
            if (!class_exists($class)) {
                continue;
            }
            if (!is_subclass_of($class, WeappSchemaMigrationContract::class)) {
                continue;
            }
            $classes[] = $class;
        }
        usort($classes, static fn (string $a, string $b): int => $a::version() <=> $b::version());

        return $classes;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
    }
}
