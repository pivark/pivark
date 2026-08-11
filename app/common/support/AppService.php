<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/**
 * Phase 5 渐进式 DI 门面：新代码优先 app() 解析，旧静态 Service 逐步迁移。
 */
final class AppService
{
    /** @template T of object */
    public static function make(string $class): object
    {
        return app($class);
    }
}
