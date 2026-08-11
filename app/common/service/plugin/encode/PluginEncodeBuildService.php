<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\encode;

use app\common\service\plugin\lifecycle\PluginDistributionService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\package\PluginPackageService;
use app\common\service\plugin\commerce\PluginCommercialPackageService;
use app\common\support\ServiceResult;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;

/** 商业插件构建：明文 weapp → 加密发布目录（不修改源码树） */
final class PluginEncodeBuildService
{
    public function __construct(
        private readonly PluginPackageService $pluginPackageService,
        private readonly PluginService $pluginService,
        private readonly PluginIonCubeBuildService $pluginIonCubeBuildService,
        private readonly PluginEncodedLoader $pluginEncodedLoader,
        private readonly PluginDistributionService $pluginDistributionService,
    ) {
    }

    /** @var list<string> */
    private const PLAIN_REL_PATHS = [
        'plugin.json',
        'Plugin.php',
        'README.md',
        'COMMERCIAL_TERMS.md',
        'NOTICE.md',
    ];

    /**
     * @return ServiceResult
     */
    public function buildToDirectory(string $identifier, ?string $destDir = null): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $this->pluginPackageService->safeIdentifier($identifier) === null) {
            return ServiceResult::fail('插件标识无效');
        }
        if (app(PluginCommercialPackageService::class)->isSourceOpen($identifier)) {
            return ServiceResult::fail('标装插件「' . $identifier . '」仅支持 source_open 明文交付，请直接打包 weapp 目录');
        }
        $src = $this->pluginService->weappRoot() . $identifier;
        if (!is_dir($src) || !is_file($src . DIRECTORY_SEPARATOR . 'plugin.json')) {
            return ServiceResult::fail('weapp/' . $identifier . ' 不存在');
        }

        return $this->buildFromDirectory($identifier, $src, $destDir);
    }

    /**
     * 从任意已解压目录构建加密发布包（开发者审包 zip 等）
     *
     * @return ServiceResult
     */
    public function buildFromDirectory(string $identifier, string $srcDir, ?string $destDir = null): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        $srcDir     = rtrim(str_replace('\\', '/', $srcDir), '/');
        if ($identifier === '' || $srcDir === '' || !is_dir($srcDir) || !is_file($srcDir . '/plugin.json')) {
            return ServiceResult::fail('源目录无效');
        }

        $manifestPath = $srcDir . '/plugin.json';
        $manifest     = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return ServiceResult::fail('plugin.json 无效');
        }

        $driver = strtolower(trim((string) config('plugin.commercial.encode_driver', 'pivark')));
        if ($driver === 'ioncube') {
            return $this->pluginIonCubeBuildService->buildToDirectory($identifier, $destDir);
        }

        if ($this->pluginEncodedLoader->encodeKey() === '') {
            return ServiceResult::fail('未配置 PIVARK_ENCODE_KEY，无法构建加密包');
        }

        // 仓根 build/output/…（禁止落 app/build：dirname(__DIR__,4) 只到 app/）
        $destDir = $destDir ?? (rtrim(ProjectPaths::root(), '/\\') . '/build/output/encoded-weapp/' . $identifier);
        if (is_dir($destDir)) {
            $this->removeDir($destDir);
        }
        if (!LocalFile::mkdirIfMissing($destDir)) {
            return ServiceResult::fail('无法创建输出目录');
        }

        $manifest = $this->pluginDistributionService->normalize($manifest);
        $manifest['distribution']['mode']                   = PluginDistributionService::MODE_ENCODED_COMMERCIAL;
        $manifest['distribution']['encryption']             = 'pivark';
        $manifest['distribution']['allows_secondary_dev']   = false;
        $manifest['distribution']['allows_redistribution']  = false;

        $this->copyTree($srcDir, $destDir, $identifier, encodePhp: false);
        $this->writeManifest($destDir, $manifest);
        $this->patchPluginBoot($destDir, $identifier);
        $this->encodePhpTree($srcDir, $destDir, $identifier);

        if (!is_file($destDir . DIRECTORY_SEPARATOR . 'COMMERCIAL_TERMS.md')) {
            $this->writeDefaultTerms($destDir);
        }

        return ServiceResult::ok(['out_dir' => $destDir], 'ok');
    }

    private function encodePhpTree(string $src, string $dest, string $identifier): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
            if ($this->shouldStayPlain($rel)) {
                continue;
            }
            if (str_starts_with($rel, 'database/') || str_starts_with($rel, 'assets/')) {
                continue;
            }

            $php = (string) file_get_contents($file->getPathname());
            if ($php === '' || !preg_match('/\b(namespace|class|function)\s+/i', $php)) {
                continue;
            }

            $destPhp = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $destDir = dirname($destPhp);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            $pveName = basename($rel) . '.pve';
            $pvePath = dirname($destPhp) . DIRECTORY_SEPARATOR . $pveName;
            file_put_contents($pvePath, $this->pluginEncodedLoader->encodePayload($php));
            file_put_contents($destPhp, $this->pluginEncodedLoader->stubPhp($pveName));
        }
    }

    private function shouldStayPlain(string $rel): bool
    {
        foreach (self::PLAIN_REL_PATHS as $plain) {
            if ($rel === $plain) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(string $dest, array $manifest): void
    {
        unset($manifest['_manifest_valid'], $manifest['_manifest_errors']);
        file_put_contents(
            $dest . DIRECTORY_SEPARATOR . 'plugin.json',
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
        );
    }

    private function patchPluginBoot(string $dest, string $identifier): void
    {
        $file = $dest . DIRECTORY_SEPARATOR . 'Plugin.php';
        if (!is_file($file)) {
            return;
        }
        $body = (string) file_get_contents($file);
        if (str_contains($body, 'PluginEncodedLoader') && str_contains($body, 'registerAutoload(')) {
            return;
        }
        $inject = "        app(\\app\\common\\service\\plugin\\encode\\PluginEncodedLoader::class)->registerAutoload('{$identifier}');\n";
        if (preg_match('/function\s+boot\s*\([^)]*\)\s*\{/', $body)) {
            $body = (string) preg_replace(
                '/(function\s+boot\s*\([^)]*\)\s*\{)/',
                '$1' . "\n" . $inject,
                $body,
                1
            );
            file_put_contents($file, $body);
        }
    }

    private function copyTree(string $src, string $dest, string $identifier, bool $encodePhp): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $rel = substr($file->getPathname(), strlen($src) + 1);
            $target = $dest . DIRECTORY_SEPARATOR . $rel;
            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                $relUnix = str_replace('\\', '/', $rel);
                if (!$encodePhp && str_ends_with(strtolower($relUnix), '.php') && !$this->shouldStayPlain($relUnix)) {
                    continue;
                }
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                copy($file->getPathname(), $target);
            }
        }
    }

    private function writeDefaultTerms(string $dest): void
    {
        $text = <<<'MD'
# 商业插件授权条款（摘要）

- **交付形式**：加密运行包（`.pve` / 编码 PHP），非源码。
- **授权存储**：授权记录保存在您站点数据库（`site_plugin_entitlements`），**不依赖** PivArk 服务器在线验票。
- **禁止使用**：反编译、解密、二次开发、再分发本插件文件。
- **业务连续性**：已授予的永久/未过期授权，在供应商停止运营后仍可本地继续使用已安装版本；仅无法获取官方更新。
- **扩展开发**：仅允许通过 Core 公开 Hook/API 扩展，不得修改加密文件。

完整条款以购销合同为准。
MD;
        file_put_contents($dest . DIRECTORY_SEPARATOR . 'COMMERCIAL_TERMS.md', $text);
    }

    private function removeDir(string $dir): void
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
            is_dir($path) ? $this->removeDir($path) : LocalFile::unlinkIfExists($path);
        }
        LocalFile::rmdirIfExists($dir);
    }
}
