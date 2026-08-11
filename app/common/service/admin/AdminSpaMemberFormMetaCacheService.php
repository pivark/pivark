<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use think\facade\Cache;

/** /spa/member-form-meta 短 TTL 缓存（等级/字段/积分开关变更时 bust） */
final class AdminSpaMemberFormMetaCacheService
{

    private const REVISION_KEY = 'admin_spa_member_form_meta_revision';
    private const PAYLOAD_PREFIX = 'admin_spa_member_form_meta:v1:';
    private const TTL = 60;

    /** @param callable(): array<string, mixed> $factory */
    public function remember(callable $factory): array
    {
        $key = self::PAYLOAD_PREFIX . $this->revision();
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $payload = $factory();
        if ($payload !== []) {
            Cache::set($key, $payload, self::TTL);
        }

        return $payload;
    }

    public function bust(): void
    {
        $revision = $this->revision();
        Cache::set(self::REVISION_KEY, $revision + 1, 0);
    }

    private function revision(): int
    {
        $revision = Cache::get(self::REVISION_KEY);

        return is_numeric($revision) ? max(1, (int) $revision) : 1;
    }
}
