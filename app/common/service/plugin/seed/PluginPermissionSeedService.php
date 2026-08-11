<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 插件安装时同步 manifest.permissions → permissions 表
 */
declare(strict_types=1);

namespace app\common\service\plugin\seed;
use app\common\support\AppTime;

use app\common\model\Permission;

class PluginPermissionSeedService
{
    /**
     * @param array<string, mixed> $manifest
     * @return list<string> 本次写入/更新的 code
     */
    public function syncFromManifest(string $identifier, array $manifest): array
    {
        $identifier = strtolower(trim($identifier));
        $raw        = $manifest['permissions'] ?? [];
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $codes = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $code = trim((string) ($item['code'] ?? ''));
            } else {
                $code = trim((string) $item);
            }
            $code = $this->normalizePermissionCode($code);
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        $codes = $this->expandWithParentCodes($codes);
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return [];
        }

        $now = AppTime::now();
        $existingCodes = Permission::whereIn('code', $codes)->column('code');
        $existingSet   = array_flip(array_map('strval', $existingCodes));
        foreach ($codes as $index => $code) {
            $exists = isset($existingSet[$code]);
            $name   = $this->permissionName($code, $identifier, $manifest);
            $data = [
                'name'   => $name,
                'module' => 'plugin',
                'sort'   => 500 + $index,
                'status' => 1,
            ];
            if ($exists) {
                Permission::where('code', $code)->update($data);
            } else {
                Permission::insert(array_merge($data, [
                    'code'       => $code,
                    'parent_id'  => null,
                    'icon'       => null,
                    'created_at' => $now,
                ]));
            }
        }

        return $codes;
    }

    /**
     * 卸载插件时移除 manifest 写入的 permissions 行（含 plugin.{id} 与子码）
     */
    public function purgeForIdentifier(string $identifier): int
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return 0;
        }

        $prefix = 'plugin.' . $identifier;

        return (int) Permission::where('code', $prefix)
            ->whereOr('code', 'like', $prefix . '.%')
            ->delete();
    }

    /**
     * 单元测试脚手架插件，不应出现在角色权限表单
     */
    public function isDevScaffoldIdentifier(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));

        return (bool) preg_match('/^ut_scaffold_[0-9a-f]{6}$/', $identifier);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function permissionName(string $code, string $identifier, array $manifest): string
    {
        $pluginName = trim((string) ($manifest['name'] ?? $identifier));
        if (str_ends_with($code, '.use')) {
            return $pluginName . ' · 使用';
        }
        if (str_ends_with($code, '.manage')) {
            return $pluginName . ' · 使用';
        }
        if (str_ends_with($code, '.settings')) {
            return $pluginName . ' · 设置';
        }

        return $pluginName . ' · ' . $code;
    }

    /** manifest 仍写 .manage 时归一为 .use */
    private function normalizePermissionCode(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code !== '' && str_ends_with($code, '.manage')) {
            return substr($code, 0, -7) . '.use';
        }

        return $code;
    }

    /**
     * 为 plugin.doc_bundle.use 等自动补父级权限码 plugin.doc_bundle（不生成单段 plugin）
     *
     * @param list<string> $codes
     * @return list<string>
     */
    private function expandWithParentCodes(array $codes): array
    {
        $all = $codes;
        foreach ($codes as $code) {
            $parts = explode('.', $code);
            while (count($parts) > 2) {
                array_pop($parts);
                $parent = implode('.', $parts);
                if ($parent !== '') {
                    $all[] = $parent;
                }
            }
        }

        return $all;
    }
}
