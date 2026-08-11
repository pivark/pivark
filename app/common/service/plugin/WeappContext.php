<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin;

use app\common\service\plugin\registry\WeappLogicalTableRegistry;
use app\common\service\plugin\security\PluginDataAccessGuard;
use app\common\service\plugin\gateway\PluginGatewayCallerContext;
use app\common\service\plugin\weapp\WeappAdminUiService;
use app\common\model\Plugin;
use app\common\service\admin\WeappAdminSpaHostRoutes;
use app\common\support\DbTable;
use app\common\support\ProjectPaths;

class WeappContext
{
    /** @var array<string, array<string, mixed>> identifier => plugins row */
    private static array $byIdentifier = [];

    /** @var array<string, array<string, mixed>> instance_id => row */
    private static array $byInstance = [];

    public function resetCache(): void
    {
        self::$byIdentifier = [];
        self::$byInstance   = [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(string $identifier): ?array
    {
        if ($identifier === '') {
            return null;
        }
        if (!isset(self::$byIdentifier[$identifier])) {
            $row = $this->pluginRow(Plugin::where('identifier', $identifier)->find());
            self::$byIdentifier[$identifier] = $row ?? [];
        }
        $row = self::$byIdentifier[$identifier];

        return $row !== [] ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveRouteKey(string $key): ?array
    {
        $key = trim($key, '/');
        if ($key === '') {
            return null;
        }

        if (isset(self::$byInstance[$key])) {
            return self::$byInstance[$key] !== [] ? self::$byInstance[$key] : null;
        }

        if (!isset(self::$byInstance[$key])) {
            $row = $this->pluginRow(Plugin::where('instance_id', $key)->find());
            self::$byInstance[$key] = $row ?? [];
        }
        if (self::$byInstance[$key] !== []) {
            return self::$byInstance[$key];
        }

        if (str_contains($key, '/')) {
            $row = $this->pluginRow(Plugin::where('package', $key)->find());
            if ($row !== null) {
                return $row;
            }
            $slug = substr($key, strrpos($key, '/') + 1);

            return $this->row($slug);
        }

        return $this->row($key);
    }

    public function packageForIdentifier(string $identifier): string
    {
        $row = $this->row($identifier);
        if ($row !== null && !empty($row['package'])) {
            return (string) $row['package'];
        }

        $manifest = app(PluginService::class)->readManifest($identifier);
        if (is_array($manifest) && !empty($manifest['package'])) {
            return (string) $manifest['package'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public function identifierFromRow(array $row): string
    {
        return (string) ($row['identifier'] ?? '');
    }

    public function instanceId(string $identifier): string
    {
        $row = $this->row($identifier);

        return (string) ($row['instance_id'] ?? '');
    }

    /** 物理表名（不含 DB prefix） */
    public function table(string $identifier, string $logical): string
    {
        $legacy = app(WeappLogicalTableRegistry::class)->tablesFor($identifier)[$logical] ?? null;
        if ($legacy !== null) {
            return $legacy;
        }

        $inst = $this->instanceId($identifier);
        if ($inst === '') {
            $inst = 'inst_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($identifier));
        }
        $safe = preg_replace('/[^a-z0-9_]+/', '_', strtolower($inst));

        return 'weapp_' . $safe . '_' . $logical;
    }

    /** @return \think\db\Query */
    public function db(string $identifier, string $logical)
    {
        $table = $this->table($identifier, $logical);
        self::maybeEnforceThirdPartyTableAccess($table);

        return DbTable::query($table);
    }

    /** 第三方插件经 Gateway 访问表时校验归属（官方插件不校验） */
    private static function maybeEnforceThirdPartyTableAccess(string $table): void
    {
        if (!(bool) config('plugin.security.data_access_guard_enforce', false)) {
            return;
        }
        $caller = PluginGatewayCallerContext::currentIdentifier();
        if ($caller === null || $caller === '') {
            return;
        }
        PluginDataAccessGuard::assertWritableTable($caller, $table);
    }

    public function isTableOwnedByIdentifier(string $identifier, string $table): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }
        $table = $this->normalizePhysicalTableName($table);
        foreach (app(WeappLogicalTableRegistry::class)->tablesFor($identifier) as $physical) {
            if ($table === $this->normalizePhysicalTableName($physical)) {
                return true;
            }
        }
        $slug = str_replace('-', '_', $identifier);
        if (preg_match('/^weapp_' . preg_quote($slug, '/') . '_/', $table) === 1) {
            return true;
        }
        $inst = str_replace('-', '_', $this->instanceId($identifier));
        if ($inst !== '' && preg_match('/^weapp_' . preg_quote($inst, '/') . '_/', $table) === 1) {
            return true;
        }

        return false;
    }

    private function normalizePhysicalTableName(string $table): string
    {
        $table = strtolower(trim($table));
        $prefix = (string) config('database.connections.mysql.prefix', '');
        if ($prefix !== '' && str_starts_with($table, strtolower($prefix))) {
            $table = substr($table, strlen($prefix));
        }

        return $table;
    }

    public function configPrefix(string $identifier): string
    {
        $row = $this->row($identifier);
        $inst = (string) ($row['instance_id'] ?? '');
        if ($inst !== '' && app(WeappLogicalTableRegistry::class)->tablesFor($identifier) === []) {
            return str_replace('-', '_', $inst) . '_';
        }

        return $identifier . '_';
    }

    public function adminHomeRoute(string $identifier): string
    {
        $identifier = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
        if ($identifier === '') {
            return '';
        }

        $manifest = app(PluginService::class)->readManifest($identifier);
        if (is_array($manifest)) {
            $fromManifest = app(WeappAdminUiService::class)->adminRouteFromManifest($manifest, $identifier);
            if ($fromManifest !== '') {
                return $fromManifest;
            }
        }

        if (is_dir(ProjectPaths::root() . 'weapp/' . $identifier)) {
            return WeappAdminSpaHostRoutes::hostPath($identifier, 'settings');
        }

        $row = $this->row($identifier);
        $inst = (string) ($row['instance_id'] ?? '');
        if ($inst !== '') {
            $safeInst = preg_replace('/[^a-z0-9_-]/', '', strtolower($inst)) ?? '';
            if ($safeInst !== '') {
                return WeappAdminSpaHostRoutes::hostPath($safeInst, 'settings');
            }
        }

        return '';
    }

    public function generateInstanceId(): string
    {
        return 'inst_' . bin2hex(random_bytes(8));
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function normalizeManifest(array $manifest, string $dirIdentifier): array
    {
        $identifier = (string) ($manifest['identifier'] ?? $dirIdentifier);
        if (empty($manifest['package'])) {
            $manifest['package'] = 'pivark/' . $identifier
                ?? ('vendor/' . $identifier);
        }
        if (empty($manifest['kind'])) {
            $manifest['kind'] = 'document-addon';
        }
        $manifest['identifier'] = $identifier;

        $admin = $manifest['admin'] ?? null;
        if (!is_array($admin)) {
            $admin = [];
        }
        if (empty($admin['portals']) || !is_array($admin['portals'])) {
            $kind = (string) $manifest['kind'];
            $admin['portals'] = $kind === 'application'
                ? ['internal']
                : ['external'];
        }
        $manifest['admin'] = $admin;

        return $manifest;
    }

    public function substituteSql(string $sql, string $identifier, string $instanceId): string
    {
        $pfx = (string) config('database.connections.mysql.prefix', 'pv_');
        $sql = str_replace('{{prefix}}', $pfx, $sql);
        $sql = str_replace('{{instance}}', (string) (preg_replace('/[^a-z0-9_]+/', '_', strtolower($instanceId)) ?? ''), $sql);

        foreach (app(WeappLogicalTableRegistry::class)->tablesFor($identifier) as $logical => $physical) {
            $sql = str_replace('{{table:' . $logical . '}}', $pfx . $physical, $sql);
        }

        return $sql;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pluginRow(mixed $result): ?array
    {
        return $result instanceof Plugin ? $result->toArray() : null;
    }
}
