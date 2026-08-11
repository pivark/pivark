<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\auth;

use app\common\support\ServiceResult;

use think\facade\Cache;

/** 登录失败计数与临时锁定（后台 / 前台共用） */
class LoginAttemptService
{

    private const MAX_FAILURES   = 10;
    private const LOCK_SECONDS   = 900;
    private const WINDOW_SECONDS = 900;

    /**
     * @param string $username 登录名
     * @param string $ip       客户端 IP
     * @return ServiceResult|null 被锁定时返回错误数组
     */
    public function guard(string $username, string $ip): ?ServiceResult
    {
        $key = $this->lockKey($username, $ip);
        if (Cache::get($key)) {
            return ServiceResult::fail('登录尝试过多，请 15 分钟后再试');
        }
        return null;
    }

    /**
     * @return mixed
     * @param mixed $username
     * @param mixed $ip
     */
    public function recordFailure(string $username, string $ip): void
    {
        $failKey = $this->failKey($username, $ip);
        $count   = (int) Cache::get($failKey, 0) + 1;
        Cache::set($failKey, $count, self::WINDOW_SECONDS);
        if ($count >= self::MAX_FAILURES) {
            Cache::set($this->lockKey($username, $ip), 1, self::LOCK_SECONDS);
        }
    }

    /**
     * @return mixed
     * @param mixed $username
     * @param mixed $ip
     */
    public function clear(string $username, string $ip): void
    {
        Cache::delete($this->failKey($username, $ip));
        Cache::delete($this->lockKey($username, $ip));
    }

    private function failKey(string $username, string $ip): string
    {
        return 'login_fail:' . md5(strtolower(trim($username)) . '|' . $ip);
    }

    private function lockKey(string $username, string $ip): string
    {
        return 'login_lock:' . md5(strtolower(trim($username)) . '|' . $ip);
    }
}
