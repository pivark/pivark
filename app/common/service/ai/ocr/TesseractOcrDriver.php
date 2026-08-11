<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai\ocr;

use app\common\support\ServiceResult;
use app\common\support\ShellExec;
use app\common\support\TrustedShellRunner;

use app\common\service\config\AiConfigService;
use app\common\support\LocalFile;

class TesseractOcrDriver implements OcrDriverInterface
{
    public function recognize(string $absolutePath, string $mime): ServiceResult
    {
        if (!ShellExec::isAvailable()) {
            return ServiceResult::fail(ShellExec::unavailableMessage('Tesseract OCR'));
        }

        $bin = app(AiConfigService::class)->tesseractPath();
        if ($bin === '' || !is_file($bin)) {
            $bin = self::findBinary();
        }
        if ($bin === '') {
            return ServiceResult::fail('未找到 tesseract，请配置 ai_tesseract_path');
        }
        $outBase = tempnam(sys_get_temp_dir(), 'pv_ocr_');
        if ($outBase === false) {
            return ServiceResult::fail('临时目录不可用');
        }
        $outFile = $outBase . '.txt';
        $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($absolutePath)
            . ' ' . escapeshellarg($outBase) . ' -l chi_sim+eng 2>&1';
        $result = TrustedShellRunner::execCaptured($cmd);
        $code   = $result['exit_code'];
        $text = '';
        if (is_file($outFile)) {
            $text = trim((string) file_get_contents($outFile));
            LocalFile::unlinkIfExists($outFile);
        }
        LocalFile::unlinkIfExists($outBase);
        if ($code !== 0 || $text === '') {
            return ServiceResult::fail('Tesseract 识别失败或未安装 chi_sim 语言包');
        }

        return ServiceResult::ok(['text' => $text], 'ok');
    }

    private static function findBinary(): string
    {
        $result = TrustedShellRunner::execCaptured('where tesseract 2>nul');
        if ($result['exit_code'] === 0 && $result['output'] !== '') {
            $out = explode("\n", $result['output']);
            if (isset($out[0]) && is_file(trim($out[0]))) {
                return trim($out[0]);
            }
        }
        foreach (['C:\\Program Files\\Tesseract-OCR\\tesseract.exe'] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }

        return '';
    }
}