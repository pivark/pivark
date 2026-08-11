<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;


/** 纯数据/块标签请求内去重：同参同次请求只执行一次 */
final class TemplateTagInvokeCacheService
{

    /** @var array<string, string> */
    private static array $memo = [];

    public function reset(): void
    {
        self::$memo = [];
    }

    public function remember(string $key, callable $loader): string
    {
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        return self::$memo[$key] = $loader();
    }

    /**
     * @param array<string, mixed> $parts
     */
    public function key(string $tag, array $parts): string
    {
        ksort($parts);

        return $tag . ':' . hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE));
    }
}
