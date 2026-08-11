<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\package;
use app\common\service\plugin\gateway\PluginGatewayAuditService;
use app\common\service\plugin\package\PluginPackageSignatureService;
use app\common\service\plugin\PluginManifestService;
use app\common\service\plugin\registry\PluginCapabilityService;
use app\common\service\plugin\lifecycle\PluginDistributionService;
use app\common\service\plugin\package\PluginPackageService;
use app\common\service\plugin\manifest\PluginRevenueShareValidationService;

final class PluginPackageAuditService
{
    public function __construct(
        private readonly PluginPackageService $pluginPackageService,
        private readonly PluginDistributionService $pluginDistributionService,
        private readonly PluginCapabilityService $pluginCapabilityService,
        private readonly PluginManifestService $pluginManifestService,
        private readonly PluginPackageSignatureService $pluginPackageSignatureService,
        private readonly PluginGatewayAuditService $pluginGatewayAuditService,
        private readonly PluginRevenueShareValidationService $revenueShareValidation,
    ) {
    }

    /** @var list<string> */
    private const BLOCK_PATTERNS = [
        '/\beval\s*\(/i',
        '/\bassert\s*\(\s*[\'"]/i',
        '/\bcreate_function\s*\(/i',
        '/\b(shell_exec|passthru|proc_open|popen|system|exec)\s*\(/i',
        '/\bpcntl_(exec|fork)\s*\(/i',
        '/\bpreg_replace\s*\([^)]*\/e[\'"]/i',
        '/\b(include|require)(_once)?\s*\(\s*[\'"]https?:\/\//i',
    ];

    /** @var list<string> */
    private const WARN_PATTERNS = [
        '/\bbase64_decode\s*\(/i',
        '/\b(gzinflate|gzuncompress|str_rot13)\s*\(/i',
        '/\bfile_get_contents\s*\(\s*[\'"]https?:\/\//i',
        '/\bcurl_exec\s*\(/i',
        '/\bmove_uploaded_file\s*\(/i',
        '/\bunlink\s*\(\s*[\'"][^\'"]*(app|config|vendor)\//i',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PATH_FRAGMENTS = [
        '../',
        '/app/',
        '/config/',
        '/vendor/',
        '/.env',
        '/composer.json',
        '/composer.lock',
    ];

    /**
     * @return array{
     *   level:string,
     *   blocks:list<string>,
     *   warns:list<string>,
     *   stats:array<string,int>,
     *   sha256:string,
     *   identifier:string,
     *   capability_issues:list<string>,
     *   gateway_issues:list<array{file:string,messages:list<string>}>,
     *   gateway_total:int
     * }
     */
    public function auditZipFile(string $zipPath, string $context = self::AUDIT_CONTEXT_UPLOAD): array
    {
        if (!is_readable($zipPath) || !class_exists(\ZipArchive::class)) {
            return $this->result('block', ['无法读取 zip 或 ZipArchive 未启用'], [], '', '');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return $this->result('block', ['无法打开 zip'], [], '', '');
        }

        try {
            return $this->auditZipArchive($zip, $zipPath, $context);
        } finally {
            $zip->close();
        }
    }

    public const AUDIT_CONTEXT_UPLOAD = 'upload';

    public const AUDIT_CONTEXT_MARKET_CATALOG = 'market_catalog';

    /**
     * @return array{
     *   level:string,
     *   blocks:list<string>,
     *   warns:list<string>,
     *   stats:array<string,int>,
     *   sha256:string,
     *   identifier:string,
     *   capability_issues:list<string>,
     *   gateway_issues:list<array{file:string,messages:list<string>}>,
     *   gateway_total:int
     * }
     */
    public function auditZipArchive(\ZipArchive $zip, string $zipPath = '', string $context = self::AUDIT_CONTEXT_UPLOAD): array
    {
        $blocks = [];
        $warns  = [];
        $stats  = ['entries' => 0, 'php_files' => 0];
        $maxEntries = max(50, (int) config('plugin.security.max_zip_entries', 500));
        $maxPhpBytes = max(8192, (int) config('plugin.security.max_php_file_bytes', 524288));

        if ($zip->numFiles > $maxEntries) {
            $blocks[] = '压缩包文件数超过上限（' . $zip->numFiles . '>' . $maxEntries . '）';
        }

        $layout = $this->pluginPackageService->detectLayout(
            $zip,
            $context === self::AUDIT_CONTEXT_MARKET_CATALOG
        );
        if (!$layout->isOk()) {
            $blocks[] = $layout->message();
        }

        $identifier = '';
        $manifest   = [];
        $capabilityIssues = [];
        if ($layout->isOk()) {
            $layoutData = $layout->dataArray();
            $manifest   = is_array($layoutData['manifest'] ?? null) ? $layoutData['manifest'] : [];
            $identifier = (string) ($layoutData['identifier'] ?? '');
            if ($manifest !== [] && empty($manifest['_manifest_valid'])) {
                foreach ((array) ($manifest['_manifest_errors'] ?? []) as $err) {
                    $err = (string) $err;
                    // 市场/发行包安装时 zip 尚未解压，class_exists 必然失败，不得拦装
                    if (
                        $context === self::AUDIT_CONTEXT_MARKET_CATALOG
                        && str_contains($err, '类不存在')
                    ) {
                        $warns[] = '插件清单（解压后复核）：' . $err;
                        continue;
                    }
                    $blocks[] = '插件清单：' . $err;
                }
            }
            $distErrors = [];
            if ($context !== self::AUDIT_CONTEXT_MARKET_CATALOG) {
                $distErrors = $this->pluginDistributionService->validateZipContents($zip, $manifest);
            }
            foreach ($distErrors as $err) {
                $blocks[] = $err;
            }
            foreach ($this->pluginCapabilityService->auditManifest($manifest, $identifier) as $capIssue) {
                $capabilityIssues[] = $capIssue;
                if ((bool) config('plugin.security.capability_manifest_audit_block', false)) {
                    $blocks[] = '能力清单：' . $capIssue;
                } else {
                    $warns[] = '能力清单：' . $capIssue;
                }
            }
            $publisher = $this->pluginManifestService->resolvePublisherType($manifest);
            if (
                $context !== self::AUDIT_CONTEXT_MARKET_CATALOG
                && $publisher === PluginManifestService::TYPE_OFFICIAL
            ) {
                $blocks[] = '上传包不得声明 publisher_type=official';
            }
            if ($identifier !== '' && $manifest !== []) {
                foreach ($this->revenueShareValidation->enforceAgainstRegistered($manifest, $identifier) as $err) {
                    $blocks[] = $err;
                }
            }
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($entry === '' || str_ends_with($entry, '/')) {
                continue;
            }
            ++$stats['entries'];

            if (!$this->pluginPackageService->isSafeZipEntry($entry)) {
                $blocks[] = '非法路径：' . $entry;
                continue;
            }

            foreach (self::FORBIDDEN_PATH_FRAGMENTS as $frag) {
                if (
                    $context === self::AUDIT_CONTEXT_MARKET_CATALOG
                    && in_array($frag, ['/composer.json', '/composer.lock'], true)
                ) {
                    continue;
                }
                if (str_contains($entry, $frag)) {
                    $blocks[] = '禁止路径片段 ' . $frag . '：' . $entry;
                }
            }

            if (!preg_match('/\.php$/i', $entry)) {
                continue;
            }
            ++$stats['php_files'];

            // 归档迁移不参与运行安装；其中历史 CLI 调用会误触高危模式
            if (preg_match('#(?:^|/)database/archive/#i', $entry) === 1) {
                continue;
            }

            $body = $zip->getFromIndex($i);
            if (!is_string($body)) {
                $blocks[] = '无法读取：' . $entry;
                continue;
            }
            if (strlen($body) > $maxPhpBytes) {
                $blocks[] = 'PHP 文件过大：' . $entry;
            }

            $this->scanPhpBody(
                $entry,
                $body,
                $blocks,
                $warns,
                $context === self::AUDIT_CONTEXT_MARKET_CATALOG
            );

            if (preg_match('#(?:^|/)database/(?:install|upgrade/[^/]+|uninstall)\.sql$#i', $entry)) {
                $this->scanSqlBody($entry, $body, $blocks);
            }
        }

        if (
            $identifier !== ''
            && $zipPath !== ''
            && is_file($zipPath)
            && $manifest !== []
            && $context !== self::AUDIT_CONTEXT_MARKET_CATALOG
        ) {
            foreach ($this->pluginPackageSignatureService->verifyUploaded($zipPath, $identifier, $manifest) as $sigErr) {
                $blocks[] = $sigErr;
            }
        }

        $gatewayIssues = [];
        if ($identifier !== '' && $context !== self::AUDIT_CONTEXT_MARKET_CATALOG) {
            $gatewayReport = $this->pluginGatewayAuditService->auditZipArchive($zip, $identifier);
            $gatewayIssues = $gatewayReport['violations'];
            foreach ($gatewayIssues as $row) {
                $file = $row['file'];
                foreach ($row['messages'] as $msg) {
                    $line = ($file !== '' ? $file . ': ' : '') . (string) $msg;
                    if ((bool) config('plugin.security.gateway_direct_service_block', false)) {
                        $blocks[] = '插件接口：' . $line;
                    } else {
                        $warns[] = '插件接口：' . $line;
                    }
                }
            }
        }

        $sha256 = $zipPath !== '' && is_file($zipPath) ? (hash_file('sha256', $zipPath) ?: '') : '';

        $level = 'pass';
        if ($blocks !== []) {
            $level = 'block';
        } elseif ($warns !== []) {
            $level = 'warn';
        }

        return $this->result($level, $blocks, $warns, $sha256, $identifier, $stats, $capabilityIssues, $gatewayIssues);
    }

    /**
     * @param array{
     *   level?:string,
     *   blocks?:list<string>,
     *   warns?:list<string>,
     *   stats?:array<string,int>,
     *   sha256?:string,
     *   identifier?:string,
     *   capability_issues?:list<string>,
     *   gateway_issues?:list<array{file:string,messages:list<string>}>,
     *   gateway_total?:int
     * } $audit
     */
    public function shouldBlockInstall(array $audit): bool
    {
        if (($audit['level'] ?? '') === 'block') {
            return (bool) config('plugin.security.audit_block_install', true);
        }

        return false;
    }

    /**
     * catalog 声明 sha256 时校验 zip 文件 hash
     *
     * @param array{
     *   level:string,
     *   blocks:list<string>,
     *   warns:list<string>,
     *   stats:array<string,int>,
     *   sha256:string,
     *   identifier:string,
     *   capability_issues:list<string>,
     *   gateway_issues:list<array{file:string,messages:list<string>}>,
     *   gateway_total:int
     * } $audit
     * @return array{
     *   level:string,
     *   blocks:list<string>,
     *   warns:list<string>,
     *   stats:array<string,int>,
     *   sha256:string,
     *   identifier:string,
     *   capability_issues:list<string>,
     *   gateway_issues:list<array{file:string,messages:list<string>}>,
     *   gateway_total:int
     * }
     */
    public function applyCatalogSha256(array $audit, string $expectedSha256): array
    {
        $expectedSha256 = strtolower(trim($expectedSha256));
        if ($expectedSha256 === '' || !(bool) config('plugin.security.verify_catalog_sha256', true)) {
            return $audit;
        }

        $actual = strtolower(trim($audit['sha256']));
        if ($actual === '' || !hash_equals($expectedSha256, $actual)) {
            $blocks   = $audit['blocks'];
            $blocks[] = 'SHA256 与 catalog 不一致（期望 ' . substr($expectedSha256, 0, 12) . '…）';
            $audit['blocks'] = array_values(array_unique($blocks));
            $audit['level']  = 'block';
        }

        return $audit;
    }

    /**
     * @param list<string> $blocks
     * @param list<string> $warns
     * @param list<string> $capabilityIssues
     * @param list<array{file:string,messages:list<string>}> $gatewayIssues
     * @param array<string,int> $stats
     * @return array{
     *   level:string,
     *   blocks:list<string>,
     *   warns:list<string>,
     *   stats:array<string,int>,
     *   sha256:string,
     *   identifier:string,
     *   capability_issues:list<string>,
     *   gateway_issues:list<array{file:string,messages:list<string>}>,
     *   gateway_total:int
     * }
     */
    private function result(
        string $level,
        array $blocks,
        array $warns,
        string $sha256,
        string $identifier,
        array $stats = [],
        array $capabilityIssues = [],
        array $gatewayIssues = []
    ): array {
        $gatewayTotal = 0;
        foreach ($gatewayIssues as $row) {
            $gatewayTotal += count($row['messages']);
        }

        return [
            'level'              => $level,
            'blocks'             => array_values(array_unique($blocks)),
            'warns'              => array_values(array_unique($warns)),
            'capability_issues'  => array_values(array_unique($capabilityIssues)),
            'gateway_issues'     => $gatewayIssues,
            'gateway_total'      => $gatewayTotal,
            'stats'              => $stats,
            'sha256'             => $sha256,
            'identifier'         => $identifier,
        ];
    }

    /**
     * @param list<string> $blocks
     * @param list<string> $warns
     */
    private function scanPhpBody(string $entry, string $body, array &$blocks, array &$warns, bool $skipGateway = false): void
    {
        foreach (self::BLOCK_PATTERNS as $pattern) {
            if (preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE) === 1) {
                $line = $this->offsetToLine($body, (int) ($m[0][1] ?? 0));
                $blocks[] = '高危模式 @ ' . $entry . ':' . $line . '（插件规范审包，≠病毒扫描）';
            }
        }
        foreach (self::WARN_PATTERNS as $pattern) {
            if (preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE) === 1) {
                $line = $this->offsetToLine($body, (int) ($m[0][1] ?? 0));
                $msg = '可疑模式 @ ' . $entry . ':' . $line . '（插件规范审包，≠病毒扫描）';
                if ((bool) config('plugin.security.audit_warn_obfuscation_block', false)) {
                    $blocks[] = $msg;
                } else {
                    $warns[] = $msg;
                }
            }
        }
        if ($skipGateway) {
            return;
        }
        foreach ($this->pluginGatewayAuditService->scanPhpContent($entry, $body) as $msg) {
            if ((bool) config('plugin.security.gateway_direct_service_block', false)) {
                $blocks[] = '插件接口：' . $msg;
            } else {
                $warns[] = '插件接口：' . $msg;
            }
        }
    }

    private function offsetToLine(string $body, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        return substr_count(substr($body, 0, $offset), "\n") + 1;
    }

    /**
     * @param list<string> $blocks
     */
    private function scanSqlBody(string $entry, string $body, array &$blocks): void
    {
        $patterns = [
            '/\bDROP\s+DATABASE\b/i'       => 'DROP DATABASE',
            '/\bINTO\s+OUTFILE\b/i'        => 'INTO OUTFILE',
            '/\bLOAD_FILE\s*\(/i'          => 'LOAD_FILE',
            '/\bCREATE\s+DATABASE\b/i'     => 'CREATE DATABASE',
        ];
        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $body) === 1) {
                $blocks[] = 'install SQL 含禁止语句 ' . $label . ' @ ' . $entry;
            }
        }
    }
}
