<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\package;

use app\common\service\plugin\manifest\PluginManifestPolicyDiscovery;
use app\common\support\ProjectPaths;

/**
 * 发行包内插件 zip 定位（常驻）。
 *
 * 扫描 install/assets/packages 与 public/static/market/plugins。
 * 装站向导与后台装插件共用；禁止放回 service/install（装完可删 install/ 后仍要能装 zip）。
 */
final class PluginBundledPackageLocator
{
    /**
     * @return list<string>
     */
    public function packageSearchDirs(): array
    {
        return [
            ProjectPaths::installPackagesDir(),
            ProjectPaths::root() . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'market' . DIRECTORY_SEPARATOR . 'plugins',
        ];
    }

    public function resolveBundledPackagePath(string $identifier): ?string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }
        foreach ($this->packageSearchDirs() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach ([$identifier . '.zip', $identifier . '-*.zip'] as $pattern) {
                foreach (glob($dir . DIRECTORY_SEPARATOR . $pattern) ?: [] as $zip) {
                    if (is_readable($zip)) {
                        return $zip;
                    }
                }
            }
        }

        return null;
    }

    /**
     * 增强包 identifier 列表（配置/manifest 发现 + 磁盘 zip），供预检/授权对账。
     *
     * @return list<string>
     */
    public function listEnhancementPackIdentifiers(): array
    {
        $seen = [];
        $rows = config('pivark.plugin_install_enhancement_pack');
        if (!is_array($rows) || $rows === []) {
            $rows = PluginManifestPolicyDiscovery::enhancementPackCatalogRows();
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(trim((string) ($row['id'] ?? '')));
            if ($id !== '') {
                $seen[$id] = true;
            }
        }
        foreach ($this->discoverPackagedEnhancementIdentifiers() as $id) {
            $seen[$id] = true;
        }

        return array_keys($seen);
    }

    /** @return list<string> */
    public function discoverPackagedEnhancementIdentifiers(): array
    {
        $ids = [];
        foreach ($this->packageSearchDirs() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $zipPath) {
                $manifest = $this->readPluginJsonFromZipFile($zipPath);
                if ($manifest === null) {
                    continue;
                }
                $id = strtolower(trim((string) ($manifest['identifier'] ?? '')));
                if ($id === '' || !$this->manifestIsEnhancementPack($manifest)) {
                    continue;
                }
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readManifestFromBundledZip(string $identifier): ?array
    {
        $path = $this->resolveBundledPackagePath($identifier);
        if ($path === null) {
            return null;
        }

        return $this->readPluginJsonFromZipFile($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readPluginJsonFromZipFile(string $zipPath): ?array
    {
        if (!is_readable($zipPath) || !class_exists(\ZipArchive::class)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        try {
            $raw = null;
            $idx = $zip->locateName('plugin.json');
            if ($idx !== false) {
                $raw = $zip->getFromIndex($idx);
            } else {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                    if (preg_match('#(^|/)plugin\.json$#', $name) === 1) {
                        $raw = $zip->getFromIndex($i);
                        break;
                    }
                }
            }
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            if (str_starts_with($raw, "\xEF\xBB\xBF")) {
                $raw = substr($raw, 3);
            }
            $json = json_decode($raw, true);

            return is_array($json) ? $json : null;
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, mixed> $manifest */
    public function manifestIsEnhancementPack(array $manifest): bool
    {
        $policy = is_array($manifest['pivark_policy'] ?? null) ? $manifest['pivark_policy'] : [];
        $pack   = is_array($policy['enhancement_pack'] ?? null) ? $policy['enhancement_pack'] : [];
        if (!empty($pack['enabled'])) {
            return true;
        }
        $community = is_array($policy['community_release'] ?? null) ? $policy['community_release'] : [];

        return !empty($community['enhancement_pack']);
    }
}
