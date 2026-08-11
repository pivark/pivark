<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

/** `{pv:include}` 嵌套深度计数（防 A↔B 互引栈溢出） */
final class TemplateIncludeDepthService
{

    public const MAX_DEPTH = 8;

    private static int $depth = 0;

    public function reset(): void
    {
        self::$depth = 0;
    }

    public function current(): int
    {
        return self::$depth;
    }

    /** @return bool false 表示已达 MAX_DEPTH，不得再 include */
    public function enter(): bool
    {
        if (self::$depth >= self::MAX_DEPTH) {
            return false;
        }
        ++self::$depth;

        return true;
    }

    public function leave(): void
    {
        if (self::$depth > 0) {
            --self::$depth;
        }
    }
}
