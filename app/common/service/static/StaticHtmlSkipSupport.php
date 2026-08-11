<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\static;

use app\common\service\config\ConfigService;

/** 静态页未变更跳过（仅更新有改动的页面） */
final class StaticHtmlSkipSupport
{

    public function enabled(): bool
    {
        return (string) app(ConfigService::class)->get('static_skip_unchanged', '1') === '1';
    }

    /**
     * 磁盘 HTML 的 mtime 不早于内容 updated_at 则视为仍有效。
     */
    public function isFileFreshForUpdatedAt(string $relativePath, string $updatedAt): bool
    {
        if (!$this->enabled() || $relativePath === '' || trim($updatedAt) === '') {
            return false;
        }
        $abs = app(StaticHtmlPathService::class)->absolutePath($relativePath);
        if (!is_file($abs)) {
            return false;
        }
        $docTs  = strtotime($updatedAt);
        $fileTs = @filemtime($abs);
        if ($docTs === false || $fileTs === false) {
            return false;
        }

        return $fileTs >= $docTs;
    }
}
