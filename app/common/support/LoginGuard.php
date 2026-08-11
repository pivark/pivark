<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\model\User;

/** 登录校验（恒定时间密码比对，防用户名枚举） */
class LoginGuard
{
    /** 用于用户不存在时的 bcrypt 比对，消除时间差 */
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9lC/.og/at2.uheWG/igi';

    /**
     * @return User|null 仅密码正确时返回用户
     */
    public static function verifyCredentials(string $username, string $password): ?User
    {
        $user  = User::findByUsername($username);
        $hash  = ($user && (string) $user->password !== '') ? (string) $user->password : self::DUMMY_HASH;
        $valid = password_verify($password, $hash);

        if (!$user || !$valid) {
            return null;
        }
        return $user;
    }
}
