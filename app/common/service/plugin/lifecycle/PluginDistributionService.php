<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\lifecycle;

use app\common\service\plugin\encode\PluginEncodedLoader;


/** 插件交付轨 distribution（明文开源 / 商业加密） */
class PluginDistributionService
{
    public function __construct(
        private readonly PluginEncodedLoader $encodedLoader,
    ) {
    }

    public const MODE_SOURCE_OPEN        = 'source_open';
    public const MODE_ENCODED_COMMERCIAL = 'encoded_commercial';

    /** @var list<string> */
    public const MODES = [
        self::MODE_SOURCE_OPEN,
        self::MODE_ENCODED_COMMERCIAL,
    ];

    /** @var array<string, string> */
    public const MODE_LABELS = [
        self::MODE_SOURCE_OPEN        => '开源明文',
        self::MODE_ENCODED_COMMERCIAL => '商业加密',
    ];

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function normalize(array $manifest): array
    {
        $dist = is_array($manifest['distribution'] ?? null) ? $manifest['distribution'] : [];
        $mode = $this->normalizeMode((string) ($dist['mode'] ?? ''));
        if ($mode === '') {
            $mode = self::MODE_SOURCE_OPEN;
        }

        $allowsDev = $mode === self::MODE_SOURCE_OPEN;
        $manifest['distribution'] = [
            'mode'                   => $mode,
            'license_spdx'           => trim((string) ($dist['license_spdx'] ?? ($allowsDev ? 'MIT' : 'LicenseRef-PivArk-Commercial'))),
            'allows_secondary_dev'   => array_key_exists('allows_secondary_dev', $dist)
                ? (bool) $dist['allows_secondary_dev']
                : $allowsDev,
            'allows_redistribution'  => array_key_exists('allows_redistribution', $dist)
                ? (bool) $dist['allows_redistribution']
                : $allowsDev,
            'encryption'             => $this->normalizeEncryption((string) ($dist['encryption'] ?? ''), $mode),
            'notice_file'            => trim((string) ($dist['notice_file'] ?? 'NOTICE.md')) ?: 'NOTICE.md',
            'terms_file'             => trim((string) ($dist['terms_file'] ?? '')),
        ];
        if ($mode === self::MODE_ENCODED_COMMERCIAL && $manifest['distribution']['terms_file'] === '') {
            $manifest['distribution']['terms_file'] = 'COMMERCIAL_TERMS.md';
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function validateManifest(array $manifest): array
    {
        $errors = [];
        $manifest = $this->normalize($manifest);
        $dist     = is_array($manifest['distribution'] ?? null) ? $manifest['distribution'] : [];
        $mode     = (string) ($dist['mode'] ?? '');

        if ($mode === self::MODE_ENCODED_COMMERCIAL) {
            $enc = (string) ($dist['encryption'] ?? '');
            if (!in_array($enc, ['pivark', 'ioncube', 'sourceguardian'], true)) {
                $errors[] = 'encoded_commercial 须声明有效 encryption（pivark / ioncube / sourceguardian）';
            }
        }

        if ($mode === self::MODE_SOURCE_OPEN) {
            $enc = (string) ($dist['encryption'] ?? 'none');
            if ($enc !== '' && $enc !== 'none') {
                $errors[] = 'source_open 交付轨 encryption 须为 none';
            }
        }

        return $errors;
    }

    /**
     * 商业加密包安装前运行时探测（manifest 已通过 validateManifest）
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function preflightInstallEncoded(array $manifest): array
    {
        $manifest = $this->normalize($manifest);
        if ($this->mode($manifest) !== self::MODE_ENCODED_COMMERCIAL) {
            return [];
        }

        $enc = (string) ($manifest['distribution']['encryption'] ?? '');
        if ($enc === 'pivark' && !extension_loaded('zlib')) {
            return ['pivark 加密包安装需要 PHP zlib 扩展'];
        }

        return [];
    }

    /**
     * 上传 zip 内是否含可疑明文业务 PHP（加密轨）或完全无 php（异常包）
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function validateZipContents(\ZipArchive $zip, array $manifest): array
    {
        $errors   = [];
        $manifest = $this->normalize($manifest);
        $mode     = (string) ($manifest['distribution']['mode'] ?? self::MODE_SOURCE_OPEN);
        $phpCount = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (!preg_match('/\.php$/i', $name)) {
                continue;
            }
            ++$phpCount;
            if ($mode !== self::MODE_ENCODED_COMMERCIAL) {
                continue;
            }
            $body = $zip->getFromIndex($i);
            if ($body === false || $body === '') {
                continue;
            }
            $rel = $name;
            if (preg_match('#^[^/]+/(.+)$#', $name, $m)) {
                $rel = $m[1];
            }
            if (
                $rel === 'Plugin.php'
                || str_starts_with($rel, 'database/')
                || str_starts_with($rel, 'assets/')
                || $this->encodedLoader->isStubBody((string) $body)
            ) {
                continue;
            }
            if ($this->looksLikePlainPhp((string) $body)) {
                $errors[] = '加密交付轨包内不得包含可读明文 PHP：' . $name;
            }
        }

        if ($mode === self::MODE_SOURCE_OPEN && $phpCount === 0) {
            $errors[] = '开源明文包内应至少包含一个 .php 文件';
        }

        return $errors;
    }

    /**
     * 构建目录内 PHP 校验（加密轨不得含可读业务 PHP）
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function validateZipContentsFromDir(string $dir, array $manifest): array
    {
        $errors   = [];
        $manifest = $this->normalize($manifest);
        $mode     = (string) ($manifest['distribution']['mode'] ?? self::MODE_SOURCE_OPEN);
        $dir      = rtrim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            return ['构建目录无效'];
        }

        $phpCount = 0;
        $it       = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            ++$phpCount;
            if ($mode !== self::MODE_ENCODED_COMMERCIAL) {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            if ($body === '') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            if (
                $rel === 'Plugin.php'
                || str_starts_with($rel, 'database/')
                || str_starts_with($rel, 'assets/')
                || $this->encodedLoader->isStubOnly($file->getPathname())
            ) {
                continue;
            }
            if ($this->looksLikePlainPhp($body)) {
                $errors[] = '加密交付轨包内不得包含可读明文 PHP：' . $rel;
            }
        }

        if ($mode === self::MODE_SOURCE_OPEN && $phpCount === 0) {
            $errors[] = '开源明文包内应至少包含一个 .php 文件';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function mode(array $manifest): string
    {
        $manifest = $this->normalize($manifest);

        return (string) ($manifest['distribution']['mode'] ?? self::MODE_SOURCE_OPEN);
    }

    public function modeLabel(string $mode): string
    {
        $mode = $this->normalizeMode($mode);

        return self::MODE_LABELS[$mode] ?? $mode;
    }

    private function normalizeMode(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return in_array($raw, self::MODES, true) ? $raw : '';
    }

    private function normalizeEncryption(string $raw, string $mode): string
    {
        $raw = strtolower(trim($raw));
        if ($mode === self::MODE_SOURCE_OPEN) {
            return 'none';
        }
        if (in_array($raw, ['none', 'ioncube', 'sourceguardian', 'pivark'], true)) {
            return $raw;
        }

        return 'pivark';
    }

    private function looksLikePlainPhp(string $body): bool
    {
        if (str_contains($body, '<?php') || str_contains($body, '<?=')) {
            return true;
        }
        if (preg_match('/\b(namespace|class|function)\s+/i', $body)) {
            return true;
        }

        return false;
    }
}
