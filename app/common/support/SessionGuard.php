<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** Session 安全（登录后会话 ID 轮换，防会话固定） */
class SessionGuard
{
    /**
     * 登录成功后调用，丢弃旧 Session ID。
     */
    public static function regenerateAfterLogin(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        session_regenerate_id(true);
    }
}
