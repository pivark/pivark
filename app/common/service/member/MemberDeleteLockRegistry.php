<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

/**
 * 会员删除锁（插件 boot 注册 id/用户名；内核不写宿主魔法数）
 */
final class MemberDeleteLockRegistry
{
    /** @var array<int, true> */
    private static array $ids = [];

    /** @var array<string, true> */
    private static array $usernames = [];

    public function reset(): void
    {
        self::$ids = [];
        self::$usernames = [];
    }

    public function registerId(int $memberId): void
    {
        if ($memberId > 0) {
            self::$ids[$memberId] = true;
        }
    }

    public function registerUsername(string $username): void
    {
        $u = strtolower(trim($username));
        if ($u !== '') {
            self::$usernames[$u] = true;
        }
    }

    public function isLocked(int $id, string $username = ''): bool
    {
        if ($id > 0 && isset(self::$ids[$id])) {
            return true;
        }
        $u = strtolower(trim($username));

        return $u !== '' && isset(self::$usernames[$u]);
    }
}
