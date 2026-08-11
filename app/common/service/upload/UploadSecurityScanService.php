<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * 上传安全扫描：病毒木马 + 注入特征；包类报 path/line；每文件 AV 由配置开关控制。
 */
declare(strict_types=1);

namespace app\common\service\upload;

use app\common\service\config\ConfigService;
use app\common\support\OpsLog;
use app\common\support\TrustedShellRunner;

/**
 * 内核上传安全真源（admin / 前台 / 包入口共用）。
 * 禁止在 weapp 平行实现。
 */
final class UploadSecurityScanService
{
    public const CFG_EVERY_FILE = 'upload_scan_every_file';

    public const CONTEXT_FILE = 'file';

    public const CONTEXT_PACKAGE = 'package';

    /** @var list<string> */
    private const INJECT_BLOCK = [
        '/\beval\s*\(/i',
        '/\bassert\s*\(\s*[\'"]/i',
        '/\bcreate_function\s*\(/i',
        '/\b(shell_exec|passthru|proc_open|popen|system|exec)\s*\(/i',
        '/\bpcntl_(exec|fork)\s*\(/i',
        '/\bpreg_replace\s*\([^)]*\/e[\'"]/i',
        '/\b(include|require)(_once)?\s*\(\s*[\'"]https?:\/\//i',
    ];

    /** @var list<string> */
    private const INJECT_WARN = [
        '/\bbase64_decode\s*\(/i',
        '/\b(gzinflate|gzuncompress|str_rot13)\s*\(/i',
        '/\bfile_get_contents\s*\(\s*[\'"]https?:\/\//i',
        '/\bcurl_exec\s*\(/i',
        '/\bmove_uploaded_file\s*\(/i',
    ];

    /** @var list<string> */
    private const TEXT_EXTS = [
        'php', 'phtml', 'php3', 'php5', 'phar', 'inc',
        'js', 'html', 'htm', 'shtml', 'asp', 'aspx', 'jsp',
        'txt', 'css', 'svg',
    ];

    public function isEveryFileScanEnabled(): bool
    {
        return (string) app(ConfigService::class)->get(self::CFG_EVERY_FILE, '0') === '1';
    }

    /**
     * @return array{available:bool,engine:string,hint:string,every_file_scan:bool}
     */
    public function engineStatus(): array
    {
        $engine = $this->detectAvEngine();

        return [
            'available'       => $engine['bin'] !== '',
            'engine'          => $engine['name'],
            'hint'            => $engine['bin'] !== ''
                ? '已检测到 ' . $engine['name'] . '，可用于病毒木马扫描'
                : '未检测到 ClamAV（clamdscan/clamscan）。开关打开后仍做注入特征检查；安装 ClamAV 后自动启用木马扫描。',
            'every_file_scan' => $this->isEveryFileScanEnabled(),
        ];
    }

    /**
     * 扫描单个已落盘/临时文件。
     *
     * @return array{
     *   level:string,
     *   findings:list<array{code:string,severity:string,path:string,line:?int,message:string}>
     * }
     */
    public function scanPath(string $absPath, string $displayPath = '', string $context = self::CONTEXT_FILE): array
    {
        $findings = [];
        $pathLabel = $displayPath !== '' ? $displayPath : basename($absPath);

        if (!is_readable($absPath)) {
            return $this->result('block', [[
                'code'     => 'unreadable',
                'severity' => 'block',
                'path'     => $pathLabel,
                'line'     => null,
                'message'  => '无法读取文件',
            ]]);
        }

        $runAv = $context === self::CONTEXT_PACKAGE || $this->isEveryFileScanEnabled();
        if ($runAv) {
            foreach ($this->scanAv($absPath, $pathLabel) as $f) {
                $findings[] = $f;
            }
        }

        $ext = strtolower((string) pathinfo($pathLabel !== '' ? $pathLabel : $absPath, PATHINFO_EXTENSION));
        $scanInject = $context === self::CONTEXT_PACKAGE
            || ($this->isEveryFileScanEnabled() && in_array($ext, self::TEXT_EXTS, true));
        if ($scanInject && $this->looksLikeText($absPath, $ext)) {
            foreach ($this->scanInjectionInFile($absPath, $pathLabel) as $f) {
                $findings[] = $f;
            }
        }

        return $this->resultFromFindings($findings);
    }

    /**
     * 扫描 zip 包（模板/插件等）。始终做结构注入特征；AV 引擎可用则扫整包。
     *
     * @return array{
     *   level:string,
     *   findings:list<array{code:string,severity:string,path:string,line:?int,message:string}>
     * }
     */
    public function scanZip(string $zipPath): array
    {
        $findings = [];
        if (!is_readable($zipPath) || !class_exists(\ZipArchive::class)) {
            return $this->result('block', [[
                'code'     => 'zip_unreadable',
                'severity' => 'block',
                'path'     => basename($zipPath),
                'line'     => null,
                'message'  => '无法读取 zip 或 ZipArchive 未启用',
            ]]);
        }

        foreach ($this->scanAv($zipPath, basename($zipPath)) as $f) {
            $findings[] = $f;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return $this->result('block', [[
                'code'     => 'zip_open',
                'severity' => 'block',
                'path'     => basename($zipPath),
                'line'     => null,
                'message'  => '无法打开 zip',
            ]]);
        }

        try {
            $maxEntries = 800;
            if ($zip->numFiles > $maxEntries) {
                $findings[] = [
                    'code'     => 'zip_bomb',
                    'severity' => 'block',
                    'path'     => basename($zipPath),
                    'line'     => null,
                    'message'  => '压缩包文件数超过上限（' . $zip->numFiles . '>' . $maxEntries . '）',
                ];
            }
            $limit = min($zip->numFiles, $maxEntries);
            for ($i = 0; $i < $limit; $i++) {
                $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if ($entry === '' || str_ends_with($entry, '/')) {
                    continue;
                }
                if (str_contains($entry, '..')) {
                    $findings[] = [
                        'code'     => 'zip_slip',
                        'severity' => 'block',
                        'path'     => $entry,
                        'line'     => null,
                        'message'  => '非法路径（疑似 zip-slip）',
                    ];
                    continue;
                }
                $ext = strtolower((string) pathinfo($entry, PATHINFO_EXTENSION));
                if (!in_array($ext, self::TEXT_EXTS, true)) {
                    continue;
                }
                $body = $zip->getFromIndex($i);
                if (!is_string($body) || $body === '') {
                    continue;
                }
                if (strlen($body) > 524288) {
                    $body = substr($body, 0, 524288);
                }
                foreach ($this->scanInjectionBody($entry, $body) as $f) {
                    $findings[] = $f;
                }
            }
        } finally {
            $zip->close();
        }

        return $this->resultFromFindings($findings);
    }

    /**
     * @param list<array{code:string,severity:string,path:string,line:?int,message:string}> $findings
     * @return array{level:string,findings:list<array{code:string,severity:string,path:string,line:?int,message:string}>}
     */
    private function resultFromFindings(array $findings): array
    {
        $level = 'pass';
        foreach ($findings as $f) {
            if (($f['severity'] ?? '') === 'block') {
                $level = 'block';
                break;
            }
            if (($f['severity'] ?? '') === 'warn') {
                $level = 'warn';
            }
        }

        return $this->result($level, $findings);
    }

    /**
     * @param list<array{code:string,severity:string,path:string,line:?int,message:string}> $findings
     * @return array{level:string,findings:list<array{code:string,severity:string,path:string,line:?int,message:string}>}
     */
    private function result(string $level, array $findings): array
    {
        return ['level' => $level, 'findings' => array_values($findings)];
    }

    /**
     * @return array{name:string,bin:string,args:list<string>}
     */
    private function detectAvEngine(): array
    {
        foreach (
            [
                ['name' => 'ClamAV(clamdscan)', 'bin' => 'clamdscan', 'args' => ['--no-summary', '--fdpass']],
                ['name' => 'ClamAV(clamscan)', 'bin' => 'clamscan', 'args' => ['--no-summary']],
            ] as $cand
        ) {
            $resolved = TrustedShellRunner::resolveBinaryOnPath($cand['bin']);
            if ($resolved !== null && $resolved !== '') {
                return ['name' => $cand['name'], 'bin' => $resolved, 'args' => $cand['args']];
            }
        }

        return ['name' => 'none', 'bin' => '', 'args' => []];
    }

    /**
     * @return list<array{code:string,severity:string,path:string,line:?int,message:string}>
     */
    private function scanAv(string $absPath, string $pathLabel): array
    {
        $engine = $this->detectAvEngine();
        if ($engine['bin'] === '') {
            return [];
        }

        $parts = [escapeshellarg($engine['bin'])];
        foreach ($engine['args'] as $arg) {
            $parts[] = escapeshellarg($arg);
        }
        $parts[] = escapeshellarg($absPath);
        $cmd = implode(' ', $parts);
        $result = TrustedShellRunner::execCaptured($cmd);
        $code = (int) ($result['exit_code'] ?? 1);
        $out = trim((string) ($result['output'] ?? ''));

        // clam: 0 clean, 1 found, 2 error
        if ($code === 0) {
            return [];
        }
        if ($code === 1) {
            $sig = preg_replace('/\s+/', ' ', $out) ?? '';
            if (strlen($sig) > 240) {
                $sig = substr($sig, 0, 240) . '…';
            }

            return [[
                'code'     => 'malware',
                'severity' => 'block',
                'path'     => $pathLabel,
                'line'     => null,
                'message'  => '检出病毒/木马' . ($sig !== '' ? '：' . $sig : ''),
            ]];
        }

        OpsLog::businessWarning('upload_av_error', ['code' => $code, 'out' => substr($out, 0, 500)]);

        return [[
            'code'     => 'av_error',
            'severity' => 'warn',
            'path'     => $pathLabel,
            'line'     => null,
            'message'  => '病毒扫描引擎返回异常（exit=' . $code . '）',
        ]];
    }

    private function looksLikeText(string $absPath, string $ext): bool
    {
        if (in_array($ext, self::TEXT_EXTS, true)) {
            return true;
        }
        $sample = @file_get_contents($absPath, false, null, 0, 512);
        if (!is_string($sample) || $sample === '') {
            return false;
        }

        return !str_contains($sample, "\0");
    }

    /**
     * @return list<array{code:string,severity:string,path:string,line:?int,message:string}>
     */
    private function scanInjectionInFile(string $absPath, string $pathLabel): array
    {
        $body = @file_get_contents($absPath);
        if (!is_string($body) || $body === '') {
            return [];
        }
        if (strlen($body) > 1048576) {
            $body = substr($body, 0, 1048576);
        }

        return $this->scanInjectionBody($pathLabel, $body);
    }

    /**
     * @return list<array{code:string,severity:string,path:string,line:?int,message:string}>
     */
    private function scanInjectionBody(string $pathLabel, string $body): array
    {
        $out = [];
        foreach (self::INJECT_BLOCK as $pattern) {
            if (preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE) === 1) {
                $offset = (int) ($m[0][1] ?? 0);
                $out[] = [
                    'code'     => 'inject_block',
                    'severity' => 'block',
                    'path'     => $pathLabel,
                    'line'     => $this->offsetToLine($body, $offset),
                    'message'  => '高危注入特征，请自行核对',
                ];
            }
        }
        foreach (self::INJECT_WARN as $pattern) {
            if (preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE) === 1) {
                $offset = (int) ($m[0][1] ?? 0);
                $out[] = [
                    'code'     => 'inject_warn',
                    'severity' => 'warn',
                    'path'     => $pathLabel,
                    'line'     => $this->offsetToLine($body, $offset),
                    'message'  => '可疑注入/混淆特征，请自行核对',
                ];
            }
        }

        return $out;
    }

    private function offsetToLine(string $body, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }
        $slice = substr($body, 0, $offset);

        return substr_count($slice, "\n") + 1;
    }
}
