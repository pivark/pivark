<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\front;

/** 前台 JS URL 模块表（插件 boot 注册 · 内核不写死 identifier） */
final class FrontScriptUrlRegistry
{

    /** @var array<string, array<string, string>> module => url map */
    private static array $modules = [];

    /** @var array<string, string> */
    private static array $core = [];

    public function reset(): void
    {
        self::$modules = [];
        self::$core = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier !== '') {
            unset(self::$modules[$identifier]);
        }
    }

    /** @param array<string, string> $urls */
    public function registerModule(string $identifier, array $urls): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $urls === []) {
            return;
        }
        self::$modules[$identifier] = $urls;
    }

    public function registerCore(string $key, string $url): void
    {
        $key = trim($key);
        $url = trim($url);
        if ($key !== '' && $url !== '') {
            self::$core[$key] = $url;
        }
    }

    /** @return array<string, string> */
    public function coreEntries(): array
    {
        return self::$core;
    }

    /** @return array<string, array<string, string>> */
    public function allModules(): array
    {
        return self::$modules;
    }
}
