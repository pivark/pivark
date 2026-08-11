<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\package;

use app\common\model\Plugin;
use app\common\service\plugin\PluginService;

/** plugin.json requires.plugins 同伴插件版本约束 */
final class PluginPeerVersionRequirementService
{
    public function __construct(
        private readonly PluginCoreVersionRequirementService $pluginCoreVersionRequirement,
    ) {
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function syntaxErrors(array $manifest): array
    {
        $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : null;
        if ($requires === null) {
            return [];
        }

        $plugins = $requires['plugins'] ?? null;
        if ($plugins === null) {
            return [];
        }
        if (!is_array($plugins)) {
            return ['requires.plugins 须为对象'];
        }

        $errors = [];
        foreach ($plugins as $peerId => $constraint) {
            $peerId = strtolower(trim((string) $peerId));
            $constraint = trim((string) $constraint);
            if ($peerId === '' || $constraint === '') {
                $errors[] = 'requires.plugins 项须为非空键值';

                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $peerId)) {
                $errors[] = 'requires.plugins 含非法 identifier：' . $peerId;

                continue;
            }
            if (!$this->pluginCoreVersionRequirement->isValidConstraintSyntax($constraint)) {
                $errors[] = 'requires.plugins.' . $peerId . ' 约束语法无效：' . $constraint;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function runtimeErrors(array $manifest): array
    {
        if ($this->syntaxErrors($manifest) !== []) {
            return $this->syntaxErrors($manifest);
        }

        $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];
        $plugins  = is_array($requires['plugins'] ?? null) ? $requires['plugins'] : [];
        if ($plugins === []) {
            return [];
        }

        $errors = [];
        foreach ($plugins as $peerId => $constraint) {
            $peerId     = strtolower(trim((string) $peerId));
            $constraint = trim((string) $constraint);
            if ($peerId === '' || $constraint === '') {
                continue;
            }

            $installed = $this->installedVersion($peerId);
            if ($installed === null) {
                $errors[] = '需要已安装插件 ' . $peerId . ' ' . $constraint;

                continue;
            }
            if (!$this->pluginCoreVersionRequirement->satisfies($constraint, $installed)) {
                $errors[] = '需要插件 ' . $peerId . ' ' . $constraint . '（当前 ' . $installed . '）';
            }
        }

        return $errors;
    }

    /**
     * enable 前：返回未满足的同伴版本描述（identifier => message）
     *
     * @return list<string>
     */
    public function unsatisfiedForEnable(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }

        $manifest = app(PluginService::class)->readManifest($identifier);
        if ($manifest === null) {
            return [];
        }

        return $this->runtimeErrors($manifest);
    }

    private function installedVersion(string $peerId): ?string
    {
        $peerId = strtolower(trim($peerId));
        if ($peerId === '') {
            return null;
        }

        $pluginService = app(PluginService::class);
        if (!is_dir($pluginService->weappRoot() . $peerId)) {
            return null;
        }
        if (!$pluginService->isEnabled($peerId)) {
            return null;
        }

        $row = Plugin::where('identifier', $peerId)->find();
        if ($row === null) {
            $manifest = $pluginService->readManifest($peerId);
            $ver      = is_array($manifest) ? trim((string) ($manifest['version'] ?? '')) : '';

            return $ver !== '' ? $ver : null;
        }
        $ver = trim((string) ($row->getAttr('version') ?? $row['version'] ?? ''));

        return $ver !== '' ? $ver : null;
    }
}
