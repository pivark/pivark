<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\auth;

use app\common\model\Plugin;
use app\common\service\plugin\seed\EnterprisePermissionSeedService;

/**
 * 跨插件 ACL 运行时注册表（内核接口 · 边由已装 weapp 的 config 合并）
 */
final class CrossPluginAclRegistry
{

    public function __construct(
        private readonly EnterprisePermissionSeedService $enterprisePermissionSeedService,
    ) {
    }

    /** @var array<string, array<string, string>>|null */
    private ?array $merged = null;

    /** @return array<string, string> */
    public function get(string $aclId): array
    {
        $aclId = strtoupper(trim($aclId));
        $row   = $this->mergedTable()[$aclId] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('CrossPluginAclRegistry: unknown ACL ' . $aclId);
        }

        /** @var array<string, string> $row */
        return $row;
    }

    /** @return array<string, array<string, string>> */
    public function all(): array
    {
        return $this->mergedTable();
    }

    public function bustCache(): void
    {
        $this->merged = null;
    }

    /**
     * 从已安装的 Enterprise 应用插件合并 cross_plugin_acl.php
     */
    public function rebuildFromInstalledPlugins(): void
    {
        $this->merged = $this->loadMergedFromDisk();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function mergedTable(): array
    {
        if (is_array($this->merged)) {
            return $this->merged;
        }

        $this->merged = $this->loadMergedFromDisk();

        return $this->merged;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadMergedFromDisk(): array
    {
        $merged = [];
        $root   = dirname(__DIR__, 3);

        $identifiers = Plugin::where('installed', 1)->column('identifier');
        foreach ($identifiers as $rawId) {
            $id = strtolower(trim((string) $rawId));
            if ($id === '' || !$this->enterprisePermissionSeedService->isEnterpriseApplication($id)) {
                continue;
            }
            $path = $root . '/weapp/' . $id . '/config/cross_plugin_acl.php';
            if (!is_readable($path)) {
                continue;
            }
            /** @var array<string, mixed> $chunk */
            $chunk = require $path;
            if (!is_array($chunk)) {
                continue;
            }
            foreach ($chunk as $aclId => $row) {
                if (!is_string($aclId) || !is_array($row)) {
                    continue;
                }
                $aclId = strtoupper(trim($aclId));
                $norm  = $this->normalizeRow($row);
                if (isset($merged[$aclId]) && $merged[$aclId] !== $norm) {
                    throw new \RuntimeException(
                        'CrossPluginAclRegistry: conflicting ACL ' . $aclId . ' from plugin ' . $id
                    );
                }
                $merged[$aclId] = $norm;
            }
        }

        ksort($merged);

        return $merged;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function normalizeRow(array $row): array
    {
        $out = [];
        foreach (['link', 'caller_plugin', 'callee_plugin', 'actor', 'caller_rbac', 'callee_rbac'] as $key) {
            if (isset($row[$key]) && (string) $row[$key] !== '') {
                $out[$key] = (string) $row[$key];
            }
        }

        return $out;
    }
}
