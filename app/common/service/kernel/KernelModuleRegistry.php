<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\kernel;

use app\common\model\Plugin;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;
use app\common\support\ProjectPaths;

class KernelModuleRegistry
{

    /** @var list<string>|null 单请求内 memo，避免 isActive() 反复全量扫描 */
    private static ?array $activeModulesCache = null;

    /** 插件启停/安装后调用，避免同 worker 内 activeModules 过期 */
    public function forgetRequestCache(): void
    {
        self::$activeModulesCache = null;
    }

    /** @return array<string, array<string, mixed>> */
    public function catalog(): array
    {
        static $cache = null;
        if ($cache === null) {
            $path = ProjectPaths::root() . '/config/kernel/modules.php';
            $cache = is_file($path) ? include $path : [];
            if (!is_array($cache)) {
                $cache = [];
            }
        }

        return $cache;
    }

    /**
     * @return list<string>
     */
    public function needsForPlugin(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }

        $manifest = app(PluginService::class)->readManifest($identifier);
        if ($manifest === null) {
            return [];
        }

        $needs = $manifest['needs'] ?? [];
        if (!is_array($needs)) {
            $needs = [];
        }
        $needs = array_values(array_unique(array_filter(array_map(
            static fn ($v) => strtolower(trim((string) $v)),
            $needs
        ))));

        if ($needs !== []) {
            return $needs;
        }

        $out = [];
        foreach ($this->catalog() as $moduleId => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $fallback = $meta['fallback_needs'] ?? [];
            if (!is_array($fallback)) {
                continue;
            }
            if (in_array($identifier, $fallback, true)) {
                $out[] = (string) $moduleId;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function activeModules(): array
    {
        if (self::$activeModulesCache !== null) {
            return self::$activeModulesCache;
        }

        $active = [];
        foreach ($this->catalog() as $moduleId => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            if (!empty($meta['kernel_builtin'])) {
                $active[(string) $moduleId] = true;
            }
        }

        $rows = Plugin::where('installed', 1)->where('enabled', 1)->select()->toArray();
        foreach ($rows as $row) {
            $id = (string) ($row['identifier'] ?? '');
            if ($id === '' || !app(EntitlementService::class)->can($id)) {
                continue;
            }
            foreach ($this->needsForPlugin($id) as $moduleId) {
                $active[$moduleId] = true;
            }
        }

        return self::$activeModulesCache = array_keys($active);
    }

    public function isActive(string $moduleId): bool
    {
        $moduleId = strtolower(trim($moduleId));
        if ($moduleId === '') {
            return false;
        }

        return in_array($moduleId, $this->activeModules(), true);
    }

    public function sync(): void
    {
        $this->forgetRequestCache();
        foreach ($this->activeModules() as $moduleId) {
            $this->ensureModule($moduleId);
        }
    }

    /**
     * 模块点亮钩子（禁止在此跑 DDL；表结构走 SchemaMigrationRegistry / weapp install.sql）
     */
    public function ensureModule(string $moduleId): void
    {
        // intentional no-op：缺表须升级迁移，禁止请求路径 ensureSchema
    }
}
