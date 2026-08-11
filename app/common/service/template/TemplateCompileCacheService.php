<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;
use app\common\service\template\TemplateEngineState;
use app\common\service\template\TemplateTagTokenizer;

use app\common\support\ProjectPaths;

use app\common\service\infra\FrontCacheInvalidator;
use app\common\support\LocalFile;

final class TemplateCompileCacheService
{

    public function __construct(
        private readonly TemplateTagTokenizer $templateTagTokenizer,
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly TemplateEngineState $templateEngineState,
    ) {
    }

    /** @var array<string, string> path => normalized */
    private static array $memory = [];

    public function ttl(): int
    {
        return max(0, (int) config('pivark.tpl_compile_cache_ttl', 604800));
    }

    public function enabled(): bool
    {
        return $this->ttl() > 0;
    }

    /**
     * 读取或写入编译产物；$source 须已 stripLeadMeta、且无 PHP
     */
    public function remember(string $path, string $source): string
    {
        if ($path === '' || !is_file($path)) {
            return $this->templateTagTokenizer->compileSource($source);
        }

        if (isset(self::$memory[$path])) {
            return self::$memory[$path];
        }

        if (!$this->enabled()) {
            $normalized = $this->templateTagTokenizer->compileSource($source);

            return self::$memory[$path] = $normalized;
        }

        $file = $this->filePath($path);
        $sourceHash = hash('xxh128', $source);
        if (is_file($file) && time() - (int) filemtime($file) <= $this->ttl()) {
            $payload = json_decode((string) file_get_contents($file), true);
            if (is_array($payload)
                && isset($payload['normalized'], $payload['mtime'], $payload['source_hash'])
                && (int) $payload['mtime'] === (int) filemtime($path)
                && (string) ($payload['source_hash'] ?? '') === $sourceHash
                && (int) ($payload['fc_gen'] ?? 0) === $this->frontCacheInvalidator->generation()
                && (string) ($payload['plugins'] ?? '') === $this->templateEngineState->extensionTagDetectPart()
                && is_string($payload['normalized'])
                && $payload['normalized'] !== '') {
                return self::$memory[$path] = $payload['normalized'];
            }
        }

        $normalized = $this->templateTagTokenizer->compileSource($source);
        $analyze    = $this->templateTagTokenizer->analyze($normalized);
        $this->write($path, [
            'normalized'  => $normalized,
            'mtime'       => (int) filemtime($path),
            'source_hash' => $sourceHash,
            'fc_gen'      => $this->frontCacheInvalidator->generation(),
            'plugins'     => $this->templateEngineState->extensionTagDetectPart(),
            'analyze'     => $analyze,
        ]);

        return self::$memory[$path] = $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function analyzeForPath(string $path, string $source): ?array
    {
        $this->remember($path, $source);
        if (!$this->enabled() || !is_file($this->filePath($path))) {
            return $this->templateTagTokenizer->analyze($this->templateTagTokenizer->compileSource($source));
        }
        $payload = json_decode((string) file_get_contents($this->filePath($path)), true);

        return is_array($payload['analyze'] ?? null) ? $payload['analyze'] : null;
    }

    public function clearAll(): void
    {
        self::$memory = [];
        $dir          = $this->cacheRoot();
        if (!is_dir($dir)) {
            return;
        }
        LocalFile::removeDirRecursive($dir, 'template_compile_cache');
    }

    public function invalidatePath(string $path): void
    {
        if ($path === '') {
            return;
        }
        unset(self::$memory[$path]);
        LocalFile::unlinkIfExists($this->filePath($path));
    }

    /**
     * @param list<string> $paths
     */
    public function invalidatePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $this->invalidatePath($path);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(string $path, array $payload): void
    {
        if (!$this->enabled()) {
            return;
        }
        $file = $this->filePath($path);
        $dir  = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function filePath(string $path): string
    {
        $hash = hash('sha256', str_replace('\\', '/', $path));

        return $this->cacheRoot() . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . $hash . '.json';
    }

    private function cacheRoot(): string
    {
        return ProjectPaths::runtimeDir() . 'compile_cache';
    }
}
