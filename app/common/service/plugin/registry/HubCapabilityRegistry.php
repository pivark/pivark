<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\registry;

/** 社区/问答 hub 能力提供者（插件 boot 注册，内核不写 comment/ask 字面量；全 lane 同步） */
final class HubCapabilityRegistry
{
    public const SLOT_COMMUNITY_HUB = 'www.community_hub';

    public const SLOT_ASK_HUB = 'www.ask_hub';

    public const SLOT_TALENT_HUB = 'www.talent_hub';

    /** @var array<string, string> slot => identifier */
    private static array $providers = [];

    public function reset(): void
    {
        self::$providers = [];
    }

    public function register(string $slot, string $identifier): void
    {
        $slot = trim($slot);
        $identifier = strtolower(trim($identifier));
        if ($slot === '' || $identifier === '') {
            return;
        }
        self::$providers[$slot] = $identifier;
    }

    public function resolve(string $slot): ?string
    {
        $slot = trim($slot);
        if ($slot === '') {
            return null;
        }
        $id = self::$providers[$slot] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        foreach (self::$providers as $slot => $id) {
            if ($id === $identifier) {
                unset(self::$providers[$slot]);
            }
        }
    }
}
