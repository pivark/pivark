<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\channel;


/** 小程序端能力声明（插件/内核 boot 注册 · 禁止内核硬编码 MAP） */
final class MiniprogramFeatureRegistry
{

    /** @var array<string, array{label:string, needs_login:bool, enabled_checker:?callable}> */
    private static array $entries = [];

    public function reset(): void
    {
        self::$entries = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        unset(self::$entries[$identifier]);
    }

    /**
     * @param callable(): bool|null $enabledChecker null 时由 MiniprogramFeatureService 用插件启用+授权判断
     */
    public function register(
        string $identifier,
        string $label,
        bool $needsLogin = false,
        ?callable $enabledChecker = null,
    ): void {
        $identifier = strtolower(trim($identifier));
        $label      = trim($label);
        if ($identifier === '' || $label === '') {
            return;
        }
        self::$entries[$identifier] = [
            'label'            => $label,
            'needs_login'      => $needsLogin,
            'enabled_checker'  => $enabledChecker,
        ];
    }

    /**
     * @return array<string, array{label:string, needs_login:bool, enabled_checker:?callable}>
     */
    public function entries(): array
    {
        return self::$entries;
    }
}
