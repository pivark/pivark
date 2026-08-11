<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai\extract;

use app\common\support\ServiceResult;
use app\common\support\OpsLog;
use app\common\support\ShellExec;
use app\common\support\TrustedShellRunner;

use app\common\service\ai\DocumentPlainTextService;
use app\common\support\LocalFile;

/** 内置 PHP 解析：txt / md / html / 有文字层 pdf（pdftotext 或简易 Tj 提取） */
class PhpBuiltinExtractDriver implements DocumentExtractDriverInterface
{
    public function extract(string $absolutePath, string $mime, string $originalName): ServiceResult
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return ServiceResult::fail('文件不可读');
        }

        $mime = strtolower($mime);
        $ext  = strtolower(pathinfo($originalName !== '' ? $originalName : $absolutePath, PATHINFO_EXTENSION));

        if ($mime === '' && $ext !== '') {
            $mime = match ($ext) {
                'txt' => 'text/plain',
                'md', 'markdown' => 'text/markdown',
                'html', 'htm' => 'text/html',
                'pdf' => 'application/pdf',
                default => 'application/octet-stream',
            };
        }

        try {
            if (str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'md', 'markdown', 'html', 'htm', 'csv'], true)) {
                $raw = file_get_contents($absolutePath);
                if ($raw === false) {
                    return ServiceResult::fail('读取失败');
                }
                $text = str_starts_with($mime, 'text/html') || in_array($ext, ['html', 'htm'], true)
                    ? app(DocumentPlainTextService::class)->fromHtml($raw)
                    : trim($raw);

                return ServiceResult::ok(['text' => $text, 'meta' => ['driver' => 'php', 'format' => $ext ?: $mime]], 'ok');
            }

            if ($mime === 'application/pdf' || $ext === 'pdf') {
                $text = self::extractPdf($absolutePath);
                if ($text === '') {
                    if (!ShellExec::isAvailable()) {
                        return ServiceResult::fail(ShellExec::unavailableMessage('PDF 文字层提取（pdftotext）'));
                    }

                    return ServiceResult::fail('PDF 未提取到文字（扫描版需 OCR，Phase 2.4）');
                }

                return ServiceResult::ok(['text' => $text, 'meta' => ['driver' => 'php', 'format' => 'pdf']], 'ok');
            }


            if (in_array($ext, ['docx'], true) || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                $text = self::extractDocx($absolutePath);
                if ($text === '') {
                    if (!class_exists(\ZipArchive::class) && !ShellExec::isAvailable()) {
                        return ServiceResult::fail('docx 解析需要 PHP zip 扩展或 Shell 子进程（当前均已不可用）');
                    }
                    $hint = class_exists(\ZipArchive::class)
                        ? 'docx 解析失败，请确认文件未损坏'
                        : 'docx 解析失败（建议启用 PHP zip 扩展 extension=zip）';

                    return ServiceResult::fail($hint);
                }

                return ServiceResult::ok(['text' => $text, 'meta' => ['driver' => 'php', 'format' => 'docx']], 'ok');
            }

            if (in_array($ext, ['xlsx'], true) || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
                $text = self::extractXlsx($absolutePath);
                if ($text === '') {
                    if (!class_exists(\ZipArchive::class) && !ShellExec::isAvailable()) {
                        return ServiceResult::fail('xlsx 解析需要 PHP zip 扩展或 Shell 子进程（当前均已不可用）');
                    }

                    return ServiceResult::fail('xlsx 解析失败');
                }

                return ServiceResult::ok(['text' => $text, 'meta' => ['driver' => 'php', 'format' => 'xlsx']], 'ok');
            }

            if (in_array($ext, ['doc'], true)) {
                return ServiceResult::fail('老版 .doc 请另存为 docx 或启用 Python 解析服务');
            }

            if (in_array($ext, ['xls'], true)) {
                return ServiceResult::fail('老版 .xls 请另存为 xlsx');
            }
            return ServiceResult::fail('暂不支持该格式：' . ($mime ?: $ext));
        } catch (\Throwable $e) {
            return ServiceResult::fail($e->getMessage());
        }
    }

    private static function extractPdf(string $path): string
    {
        $pdftotext = self::findPdftotext();
        if ($pdftotext !== null) {
            $out = tempnam(sys_get_temp_dir(), 'pv_pdf_');
            if ($out !== false) {
                $cmd = escapeshellarg($pdftotext) . ' -enc UTF-8 -nopgbrk '
                    . escapeshellarg($path) . ' ' . escapeshellarg($out) . ' 2>&1';
                $result = TrustedShellRunner::execCaptured($cmd);
                if ($result['exit_code'] === 0 && is_file($out)) {
                    $text = trim((string) file_get_contents($out));
                    LocalFile::unlinkIfExists($out);
                    if ($text !== '') {
                        return $text;
                    }
                }
                LocalFile::unlinkIfExists($out);
            }
        }

        return self::extractPdfSimple($path);
    }

    private static function findPdftotext(): ?string
    {
        if (!ShellExec::isAvailable()) {
            return null;
        }

        foreach (['pdftotext', 'C:\\Program Files\\poppler\\Library\\bin\\pdftotext.exe'] as $bin) {
            if ($bin !== 'pdftotext' && !is_file($bin)) {
                continue;
            }
            $test = $bin === 'pdftotext' ? 'where pdftotext 2>nul' : null;
            if ($test !== null) {
                $result = TrustedShellRunner::execCaptured($test);
                if ($result['exit_code'] === 0 && $result['output'] !== '') {
                    $out = explode("\n", $result['output']);
                    if (isset($out[0]) && is_file(trim($out[0]))) {
                        return trim($out[0]);
                    }
                }
                continue;
            }
            return $bin;
        }

        return null;
    }

    private static function extractPdfSimple(string $path): string
    {
        $data = LocalFile::getContents($path);
        if ($data === null || $data === '') {
            return '';
        }
        $parts = [];
        if (preg_match_all('/\((?:\\\\.|[^\\\\])*?\)\s*Tj/s', $data, $m)) {
            foreach ($m[0] as $chunk) {
                if (preg_match('/\(((?:\\\\.|[^\\\\])*?)\)\s*Tj/s', $chunk, $inner)) {
                    $parts[] = stripcslashes($inner[1]);
                }
            }
        }
        $text = trim(implode(' ', $parts));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private static function extractDocx(string $path): string
    {
        if (class_exists('PhpOffice\\PhpWord\\IOFactory')) {
            try {
                $phpWord = \call_user_func(['PhpOffice\\PhpWord\\IOFactory', 'load'], $path);
                $text    = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) {
                            $text .= $element->getText() . "\n";
                        }
                    }
                }

                $text = trim($text);
                if ($text !== '') {
                    return $text;
                }
            } catch (\Throwable $e) { OpsLog::businessWarning('php_builtin_extract_optional_failed', ['msg' => $e->getMessage()]); }
        }

        $xml = app(OfficeZipReader::class)->readEntry($path, 'word/document.xml');
        if ($xml === null || $xml === '') {
            return '';
        }

        return app(WordDocumentXmlParser::class)->toPlainText($xml);
    }

    private static function extractXlsx(string $path): string
    {
        if (class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            try {
                $spreadsheet = \call_user_func(['PhpOffice\\PhpSpreadsheet\\IOFactory', 'load'], $path);
                $sheet       = $spreadsheet->getActiveSheet();
                $rows  = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];
                    foreach ($row->getCellIterator() as $cell) {
                        $cells[] = (string) $cell->getValue();
                    }
                    $line = trim(implode("\t", $cells));
                    if ($line !== '') {
                        $rows[] = $line;
                    }
                }

                return trim(implode("\n", $rows));
            } catch (\Throwable $e) { OpsLog::businessWarning('php_builtin_extract_optional_failed', ['msg' => $e->getMessage()]); }
        }

        $shared = app(OfficeZipReader::class)->readEntry($path, 'xl/sharedStrings.xml');
        if ($shared === null || $shared === '') {
            return '';
        }
        $shared = preg_replace('/<\/si>/', "\n", $shared) ?? $shared;
        $plain  = strip_tags($shared);

        return trim(html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function extractOfficeXml(string $zipPath, string $entry): string
    {
        $xml = app(OfficeZipReader::class)->readEntry($zipPath, $entry);
        if ($xml === null || $xml === '') {
            return '';
        }

        if (str_contains($entry, 'document.xml')) {
            return app(WordDocumentXmlParser::class)->toPlainText($xml);
        }

        $xml = preg_replace('/<w:p[^>]*>/', "\n", $xml) ?? $xml;
        $plain = strip_tags($xml);

        return trim(html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
