<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split from StaticHtmlService — manifest 与静态文件落盘
 */
declare(strict_types=1);

namespace app\common\service\static;
use app\common\service\static\StaticRemotePublishService;
use app\common\service\static\StaticHtmlPathService;

use app\common\service\config\ConfigService;
use app\common\service\seo\SeoStaticConfigService;
use app\common\support\LocalFile;

class StaticHtmlManifestService
{

    public function __construct(
        private readonly StaticHtmlPathService $staticHtmlPathService,
        private readonly SeoStaticConfigService $seoStaticConfigService,
        private readonly ConfigService $configService,
        private readonly StaticRemotePublishService $staticRemotePublishService,
    ) {
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>}|null $stats
     */
    public function purgeAll(?array &$stats = null): void
    {
        $manifest = $this->readManifest();
        foreach ($manifest as $rel) {
            $abs = $this->staticHtmlPathService->absolutePath($rel);
            if (LocalFile::unlinkIfExists($abs)) {
                if ($stats !== null) {
                    $stats['deleted']++;
                }
            }
        }
        $this->saveManifest([]);
    }

    public function publicUrlForRelative(string $relativePath): string
    {
        $path = '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
        $sub  = $this->seoStaticConfigService->subdir();
        if ($sub !== '' && !str_starts_with($path, '/' . $sub . '/')) {
            $path = '/' . $sub . $path;
        }

        $base = rtrim((string) $this->configService->get('site_url', ''), '/');

        return $base !== '' ? $base . $path : $path;
    }

    public function writeRelative(string $relativePath, string $html): void
    {
        // 落盘前自愈站点根 /{subdir}→public/{subdir}（包根文档根 + Nginx 无 alias 也能开静态页）
        $this->seoStaticConfigService->ensureSubdirWebAlias();

        $abs = $this->staticHtmlPathService->absolutePath($relativePath);
        $dir = dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建目录：' . $dir);
        }
        $tmp = $abs . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $html) === false) {
            throw new \RuntimeException('写入失败：' . $abs);
        }
        if (!rename($tmp, $abs)) {
            LocalFile::unlinkIfExists($tmp);
            throw new \RuntimeException('替换失败：' . $abs);
        }

        $publicUrl = $this->publicUrlForRelative($relativePath);
        $this->staticRemotePublishService->afterHtmlWritten($abs, $relativePath, $publicUrl);
        $this->trackManifest($relativePath);
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>}|null $stats
     */
    public function deleteRelative(string $relativePath, ?array &$stats = null): void
    {
        $abs = $this->staticHtmlPathService->absolutePath($relativePath);
        if (LocalFile::unlinkIfExists($abs)) {
            if ($stats !== null) {
                $stats['deleted']++;
            }
        }
        $this->untrackManifest($relativePath);
    }

    private function manifestFile(): string
    {
        return \app\common\support\ProjectPaths::runtimeDir() . 'static_html_manifest.json';
    }

    /** @return list<string> */
    private function readManifest(): array
    {
        $file = $this->manifestFile();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    /** @param list<string> $paths */
    private function saveManifest(array $paths): void
    {
        $file = $this->manifestFile();
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode(array_values(array_unique($paths)), JSON_UNESCAPED_UNICODE));
    }

    private function trackManifest(string $relativePath): void
    {
        $manifest   = $this->readManifest();
        $manifest[] = $relativePath;
        $this->saveManifest($manifest);
    }

    private function untrackManifest(string $relativePath): void
    {
        $manifest = array_values(array_filter(
            $this->readManifest(),
            static fn (string $path): bool => $path !== $relativePath
        ));
        $this->saveManifest($manifest);
    }
}
