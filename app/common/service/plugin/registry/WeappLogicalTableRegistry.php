<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\registry;

/** 插件 boot 登记逻辑表映射（覆盖/扩展 config/kernel/logical_tables.php） */
final class WeappLogicalTableRegistry
{
    /** @var array<string, array<string, string>> */
    private static array $byIdentifier = [];

    public function reset(): void
    {
        self::$byIdentifier = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier !== '') {
            unset(self::$byIdentifier[$identifier]);
        }
    }

    /** @param array<string, string> $logicalToPhysical */
    public function register(string $identifier, array $logicalToPhysical): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $logicalToPhysical === []) {
            return;
        }
        self::$byIdentifier[$identifier] = array_merge(
            self::$byIdentifier[$identifier] ?? [],
            $logicalToPhysical,
        );
    }

    /** @return array<string, string> */
    public function tablesFor(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }
        $cfg = config('kernel.logical_tables');
        $fromCfg = is_array($cfg[$identifier] ?? null) ? $cfg[$identifier] : [];

        return array_merge($fromCfg, self::$byIdentifier[$identifier] ?? []);
    }
}
