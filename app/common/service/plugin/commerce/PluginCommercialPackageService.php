<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\support\ServiceResult;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\package\PluginPackageSignatureService;
use app\common\service\plugin\lifecycle\PluginDistributionService;
use app\common\service\plugin\encode\PluginEncodeBuildService;
use app\common\service\plugin\manifest\PluginManifestPolicyDiscovery;

use app\common\service\audit\AuditLogService;

/** 商业加密插件 zip 构建（构建 → 签名 → 发布） */
final class PluginCommercialPackageService
{
    public function __construct(
        private readonly PluginEncodeBuildService $pluginEncodeBuildService,
        private readonly PluginDistributionService $pluginDistributionService,
        private readonly PluginPackageSignatureService $pluginPackageSignatureService,
        private readonly AuditLogService $auditLogService,
        private readonly PluginService $pluginService,
    ) {
    }

    /**
     * @return ServiceResult
     */
    public function buildCommercialZip(string $identifier): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        $built      = $this->pluginEncodeBuildService->buildToDirectory($identifier);
        if (!$built->isOk()) {
            return $built;
        }
        $outDir = (string) ($built->dataArray()['out_dir'] ?? '');
        if ($outDir === '' || !is_dir($outDir)) {
            return ServiceResult::fail('构建目录无效');
        }
        if (!class_exists(\ZipArchive::class)) {
            return ServiceResult::fail('需要 ZipArchive');
        }

        $manifestPath = $outDir . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!is_file($manifestPath)) {
            return ServiceResult::fail('构建产物 plugin.json 无效');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return ServiceResult::fail('构建产物 plugin.json 无效');
        }

        $zipErrors = $this->pluginDistributionService->validateZipContentsFromDir($outDir, $manifest);
        if ($zipErrors !== []) {
            return ServiceResult::fail(implode('；', $zipErrors));
        }

        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pivark_enc_' . $identifier . '_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ServiceResult::fail('无法创建 zip');
        }
        $prefix = $identifier . '/';
        $it     = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($outDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $rel = $prefix . str_replace('\\', '/', substr($file->getPathname(), strlen($outDir) + 1));
            if ($file->isDir()) {
                $zip->addEmptyDir(rtrim($rel, '/'));
            } else {
                $zip->addFile($file->getPathname(), $rel);
            }
        }
        $zip->close();

        $this->pluginPackageSignatureService->writeSidecar($tmp, $identifier, $manifest);
        $version  = (string) ($manifest['version'] ?? '1.0.0');
        $filename = $identifier . '-' . preg_replace('/[^a-z0-9._-]+/i', '-', $version) . '-encoded.zip';

        $this->auditLogService->operate('构建商业加密插件包', 'admin.plugin', ['identifier' => $identifier]);

        return ServiceResult::ok(['file' => $tmp, 'filename' => $filename, 'out_dir' => $outDir], 'ok');
    }

    public function isCommercialIdentifier(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $this->isSourceOpen($identifier)) {
            return false;
        }

        return in_array($identifier, $this->listEncodedIdentifiers(), true);
    }

    public function isSourceOpen(string $identifier): bool
    {
        return strtolower(trim($identifier)) === $this->sourceOpenIdentifier();
    }

    public function sourceOpenIdentifier(): string
    {
        $override = strtolower(trim((string) config('plugin.commercial.source_open_identifier', '')));
        if ($override !== '') {
            return $override;
        }

        return PluginManifestPolicyDiscovery::sourceOpenIdentifier();
    }

    /**
     * @return list<string>
     */
    public function listEncodedIdentifiers(): array
    {
        $configured = config('plugin.commercial.encoded_identifiers');
        if (!is_array($configured)) {
            $configured = [];
        }
        $configured = array_values(array_filter(array_map(
            static fn ($id): string => strtolower(trim((string) $id)),
            $configured
        )));

        if ($configured === [] || in_array('*', $configured, true)) {
            return $this->discoverWeappIdentifiers(excludeSourceOpen: true);
        }

        $sourceOpen = $this->sourceOpenIdentifier();
        $out        = [];
        foreach ($configured as $id) {
            if ($id === '*' || $id === $sourceOpen) {
                continue;
            }
            $out[] = $id;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    public function discoverWeappIdentifiers(bool $excludeSourceOpen = true): array
    {
        $root = $this->pluginService->weappRoot();
        if (!is_dir($root)) {
            return [];
        }
        $sourceOpen = $this->sourceOpenIdentifier();
        $out        = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $id = strtolower(trim($entry));
            if ($id === '' || !is_dir($root . $id)) {
                continue;
            }
            if (!is_file($root . $id . DIRECTORY_SEPARATOR . 'plugin.json')) {
                continue;
            }
            if ($excludeSourceOpen && $id === $sourceOpen) {
                continue;
            }
            if ($this->pluginService->isPermanentKernelSurface($id)) {
                continue;
            }
            $out[] = $id;
        }
        sort($out);

        return $out;
    }
}
