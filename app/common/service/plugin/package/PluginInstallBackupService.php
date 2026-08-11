<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\package;

use app\common\service\plugin\PluginService;
use app\common\support\ServiceResult;

use app\common\service\audit\AuditLogService;
use app\common\support\AppTime;

use app\common\support\LocalFile;
use app\common\support\ProjectPaths;

final class PluginInstallBackupService
{
    public function __construct(
        private readonly PluginService $pluginService,
        private readonly PluginPackageService $pluginPackageService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * @return list<array{filename:string,rel_path:string,size:int,mtime:int,mtime_label:string}>
     */
    public function listForIdentifier(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }

        $pattern = $this->backupRoot() . DIRECTORY_SEPARATOR . $identifier . '-*.zip';
        $files   = glob($pattern) ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $out = [];
        foreach ($files as $abs) {
            if (!is_file($abs)) {
                continue;
            }
            $name = basename($abs);
            $mtime = (int) filemtime($abs);
            $out[] = [
                'filename'     => $name,
                'rel_path'     => 'data/runtime/plugin_backups/' . $name,
                'size'         => (int) filesize($abs),
                'mtime'        => $mtime,
                'mtime_label'  => $mtime > 0 ? AppTime::format('Y-m-d H:i:s', $mtime) : '',
            ];
        }

        return $out;
    }

    /**
     * @return ServiceResult
     */
    public function restoreFromArchive(string $identifier, string $filename): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        $filename   = basename(trim($filename));
        if ($identifier === '' || $filename === '') {
            return ServiceResult::fail('参数无效');
        }
        if (!preg_match('/^' . preg_quote($identifier, '/') . '-\d{14}\.zip$/', $filename)) {
            return ServiceResult::fail('备份文件名无效');
        }
        if (!class_exists(\ZipArchive::class)) {
            return ServiceResult::fail('ZipArchive 未启用');
        }

        $absZip = $this->backupRoot() . DIRECTORY_SEPARATOR . $filename;
        if (!is_readable($absZip)) {
            return ServiceResult::fail('备份文件不存在');
        }

        $dest = $this->pluginService->weappRoot() . $identifier;
        if (!is_dir($dest)) {
            return ServiceResult::fail('插件目录不存在，请先安装');
        }

        $zip = new \ZipArchive();
        if ($zip->open($absZip) !== true) {
            return ServiceResult::fail('无法打开备份 zip');
        }

        try {
            $prefix = $identifier . '/';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if ($entry === '' || !$this->pluginPackageService->isSafeZipEntry($entry)) {
                    return ServiceResult::fail('备份包含非法路径：' . $entry);
                }
                if (!str_starts_with($entry, $prefix)) {
                    return ServiceResult::fail('备份包结构无效（缺少 ' . $prefix . ' 前缀）');
                }
            }
        } finally {
            $zip->close();
        }

        $preRestore = $this->archiveWeappDirectory($identifier, $dest);
        $this->removeDirectory($dest);
        if (!$this->extractArchive($absZip, $identifier)) {
            return ServiceResult::fail('解压备份失败，请检查目录权限');
        }

        $manifest = $this->pluginService->readManifest($identifier);
        if ($manifest === null || empty($manifest['_manifest_valid'])) {
            $this->removeDirectory($dest);
            if ($preRestore !== null) {
                $this->extractArchive(
                    $this->backupRoot() . DIRECTORY_SEPARATOR . basename($preRestore),
                    $identifier,
                );
            }

            return ServiceResult::fail('还原后 plugin.json 校验失败');
        }

        $upgrade = $this->pluginService->upgrade($identifier);

        if ($upgrade->isOk()) {
            $this->auditLogService->operate('还原插件安装备份', 'admin.plugin', [
                'identifier' => $identifier,
                'filename'   => $filename,
                'pre_backup' => $preRestore ?? '',
            ]);
        }

        return $upgrade->isOk()
            ? ServiceResult::ok(['backup' => $preRestore ?? ''], (string) ($upgrade->message() ?: '还原完成'))
            : ServiceResult::fail((string) ($upgrade->message() ?: '还原失败'), data: ['backup' => $preRestore ?? '']);
    }

    /**
     * 将 weapp/{identifier} 打包归档；失败不阻断安装
     *
     * @return string|null 相对项目根路径
     */
    public function archiveWeappDirectory(string $identifier, string $absSourceDir): ?string
    {
        if (!(bool) config('plugin.security.install_backup_zip', true)) {
            return null;
        }
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !is_dir($absSourceDir)) {
            return null;
        }
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }

        $backupRoot = $this->backupRoot();
        if (!LocalFile::mkdirIfMissing($backupRoot)) {
            return null;
        }

        $fileName = $identifier . '-' . AppTime::format('YmdHis') . '.zip';
        $absZip   = $backupRoot . DIRECTORY_SEPARATOR . $fileName;
        $zip      = new \ZipArchive();
        if ($zip->open($absZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $sourceNorm = rtrim(str_replace('\\', '/', $absSourceDir), '/');
        $iterator   = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absSourceDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $pathNorm = str_replace('\\', '/', $file->getPathname());
            $rel      = ltrim(substr($pathNorm, strlen($sourceNorm)), '/');
            if ($rel === '') {
                continue;
            }
            $zip->addFile($file->getPathname(), $identifier . '/' . $rel);
        }
        $zip->close();

        if (!is_file($absZip)) {
            return null;
        }

        $this->pruneOldArchives($identifier, $backupRoot);

        return 'data/runtime/plugin_backups/' . $fileName;
    }

    private function backupRoot(): string
    {
        return rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'plugin_backups';
    }

    private function extractArchive(string $absZip, string $identifier): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($absZip) !== true) {
            return false;
        }

        $destRoot = $this->pluginService->weappRoot() . $identifier;
        if (!is_dir($destRoot) && !mkdir($destRoot, 0755, true) && !is_dir($destRoot)) {
            $zip->close();

            return false;
        }

        $prefix = $identifier . '/';
        $plen   = strlen($prefix);

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if ($entry === '' || !$this->pluginPackageService->isSafeZipEntry($entry)) {
                    $this->removeDirectory($destRoot);

                    return false;
                }
                if (!str_starts_with($entry, $prefix)) {
                    continue;
                }
                $rel = substr($entry, $plen);
                if ($rel === '') {
                    continue;
                }

                $target = $destRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (str_ends_with($entry, '/')) {
                    if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                        $this->removeDirectory($destRoot);

                        return false;
                    }
                    continue;
                }

                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                    $this->removeDirectory($destRoot);

                    return false;
                }

                $body = $zip->getFromIndex($i);
                if (!is_string($body) || file_put_contents($target, $body) === false) {
                    $this->removeDirectory($destRoot);

                    return false;
                }
            }
        } finally {
            $zip->close();
        }

        return is_file($destRoot . DIRECTORY_SEPARATOR . 'plugin.json');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                LocalFile::unlinkIfExists($path);
            }
        }
        LocalFile::rmdirIfExists($dir);
    }

    private function pruneOldArchives(string $identifier, string $backupRoot): void
    {
        $keep = max(1, (int) config('plugin.security.install_backup_retention', 5));
        $files = glob($backupRoot . DIRECTORY_SEPARATOR . $identifier . '-*.zip') ?: [];
        if (count($files) <= $keep) {
            return;
        }
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $keep) as $old) {
            if (is_file($old)) {
                LocalFile::unlinkQuiet($old, 'plugin_install_backup_prune');
            }
        }
    }
}
