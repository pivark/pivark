<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai\extract;

use app\common\support\LocalFile;
use app\common\support\ShellExec;
use app\common\support\TrustedShellRunner;

/**
 * 从 Office Open XML（docx/xlsx）压缩包读取单个条目。
 * 优先 ZipArchive；无 ext-zip 时用内置 EOCD 解析（stored + deflate）。
 */
class OfficeZipReader
{

    public function readEntry(string $zipPath, string $entryName): ?string
    {
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            return null;
        }

        $entryName = str_replace('\\', '/', ltrim($entryName, '/'));

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) === true) {
                $content = $zip->getFromName($entryName);
                $zip->close();
                if ($content !== false && $content !== '') {
                    return $content;
                }
            }
        }

        $pure = $this->readEntryPure($zipPath, $entryName);
        if ($pure !== null && $pure !== '') {
            return $pure;
        }

        if (function_exists('exec') && ShellExec::isAvailable()) {
            return $this->readEntryWindows($zipPath, $entryName);
        }

        return null;
    }

    private function readEntryPure(string $zipPath, string $entryName): ?string
    {
        $data = LocalFile::getContents($zipPath);
        if ($data === null || $data === '') {
            return null;
        }

        $eocdPos = strrpos($data, "PK\x05\x06");
        if ($eocdPos === false) {
            return null;
        }

        $cdEntries = unpack('v', substr($data, $eocdPos + 10, 2))[1] ?? 0;
        $cdOffset  = unpack('V', substr($data, $eocdPos + 16, 4))[1] ?? 0;
        if ($cdEntries < 1 || $cdOffset < 1) {
            return null;
        }

        $pos = $cdOffset;
        for ($i = 0; $i < $cdEntries; ++$i) {
            if (strlen($data) < $pos + 46 || substr($data, $pos, 4) !== "PK\x01\x02") {
                break;
            }

            $compMethod  = unpack('v', substr($data, $pos + 10, 2))[1] ?? 0;
            $compSize    = unpack('V', substr($data, $pos + 20, 4))[1] ?? 0;
            $nameLen     = unpack('v', substr($data, $pos + 28, 2))[1] ?? 0;
            $extraLen    = unpack('v', substr($data, $pos + 30, 2))[1] ?? 0;
            $commentLen  = unpack('v', substr($data, $pos + 32, 2))[1] ?? 0;
            $localOffset = unpack('V', substr($data, $pos + 42, 4))[1] ?? 0;
            $currentName = substr($data, $pos + 46, $nameLen);
            $pos += 46 + $nameLen + $extraLen + $commentLen;

            if (strcasecmp(str_replace('\\', '/', $currentName), $entryName) !== 0) {
                continue;
            }

            if ($localOffset < 1 || strlen($data) < $localOffset + 30) {
                return null;
            }
            if (substr($data, $localOffset, 4) !== "PK\x03\x04") {
                return null;
            }

            $localNameLen  = unpack('v', substr($data, $localOffset + 26, 2))[1] ?? 0;
            $localExtraLen = unpack('v', substr($data, $localOffset + 28, 2))[1] ?? 0;
            $contentStart  = $localOffset + 30 + $localNameLen + $localExtraLen;
            $binary        = substr($data, $contentStart, $compSize);

            return $this->inflateZipPayload($compMethod, $binary);
        }

        return null;
    }

    private function inflateZipPayload(int $method, string $binary): ?string
    {
        if ($binary === '') {
            return '';
        }

        if ($method === 0) {
            return $binary;
        }

        if ($method !== 8) {
            return null;
        }

        $out = @gzinflate(substr($binary, 2, -4));
        if ($out !== false) {
            return $out;
        }

        $out = @gzinflate($binary);
        if ($out !== false) {
            return $out;
        }

        if (function_exists('zlib_decode')) {
            $out = @zlib_decode($binary);
            if ($out !== false) {
                return $out;
            }
        }

        return null;
    }

    private function readEntryWindows(string $zipPath, string $entryName): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }

        $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pv_office_' . uniqid('', true);
        $zipCopy = $tmpBase . '.zip';
        $outDir  = $tmpBase . '_out';

        try {
            if (!is_file($zipPath) || !copy($zipPath, $zipCopy)) {
                return null;
            }
            if (!LocalFile::mkdirIfMissing($outDir)) {
                return null;
            }

            $cmd = 'powershell -NoProfile -Command '
                . escapeshellarg(
                    'Expand-Archive -LiteralPath ' . $this->psQuote($zipCopy)
                    . ' -DestinationPath ' . $this->psQuote($outDir)
                    . ' -Force'
                );
            if (TrustedShellRunner::execCaptured($cmd)['exit_code'] !== 0) {
                return null;
            }

            $target = $outDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryName);
            if (!is_file($target)) {
                return null;
            }

            $content = file_get_contents($target);

            return $content === false ? null : $content;
        } finally {
            $this->rmTree($outDir);
            LocalFile::unlinkIfExists($zipCopy);
        }
    }

    private function psQuote(string $path): string
    {
        return "'" . str_replace("'", "''", $path) . "'";
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                LocalFile::unlinkIfExists($path);
            }
        }
        LocalFile::rmdirIfExists($dir);
    }
}
