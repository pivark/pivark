<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

use app\common\support\LocalFile;
use app\common\support\ProjectPaths;

/** dev 扩展点 dispatch 追踪（data/runtime/extension_trace.log） */
final class ExtensionTraceService
{
    private const LOG_FILE = 'extension_trace.log';

    /** @param array<string, mixed> $meta */
    public function log(string $extensionId, string $pluginId, array $meta = []): void
    {
        if (!(bool) config('app.app_debug', false)) {
            return;
        }
        $extensionId = trim($extensionId);
        $pluginId    = trim($pluginId);
        if ($extensionId === '') {
            return;
        }

        $line = json_encode([
            'ts'          => date('c'),
            'extension'   => $extensionId,
            'plugin'      => $pluginId,
            'meta'        => $meta,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }

        LocalFile::mkdirIfMissing(ProjectPaths::runtimeDir());
        file_put_contents(ProjectPaths::runtimeDir() . self::LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
