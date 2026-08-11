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
use think\facade\Session;

/** /spa/menus Vben 路由树缓存（按用户 + persona + 全局 revision） */
final class AdminSpaMenuRouteCacheService
{

    private const REVISION_KEY = 'admin_spa_menu_route_revision';
    private const ROUTES_PREFIX = 'admin_spa_vben_routes:v1:';
    private const TTL = 43200;

    /** @return list<array<string, mixed>>|null */
    public function get(): ?array
    {
        $key = $this->sessionCacheKey();
        if ($key === null) {
            return null;
        }
        $cached = Cache::get($key);
        if (!is_array($cached) || $cached === []) {
            return null;
        }

        return $cached;
    }

    /** @param list<array<string, mixed>> $routes */
    public function put(array $routes): void
    {
        $key = $this->sessionCacheKey();
        if ($key === null || $routes === []) {
            return;
        }
        Cache::set($key, $routes, self::TTL);
    }

    public function bustAll(): void
    {
        $revision = $this->globalRevision();
        Cache::set(self::REVISION_KEY, $revision + 1, 0);
    }

    public function globalRevision(): int
    {
        $revision = Cache::get(self::REVISION_KEY);

        return is_numeric($revision) ? max(1, (int) $revision) : 1;
    }

    private function sessionCacheKey(): ?string
    {
        $admin = Session::get('admin_user', []);
        if (!is_array($admin)) {
            return null;
        }
        $userId = (int) ($admin['id'] ?? 0);
        if ($userId < 1) {
            return null;
        }

        $persona = app(AdminNavPersonaService::class)->resolve($userId);
        $roles   = $admin['role_codes'] ?? [];
        if (!is_array($roles)) {
            $roles = [];
        }
        $roles = array_values(array_unique(array_map('strval', $roles)));
        sort($roles);
        $roleSig = md5(json_encode($roles, JSON_UNESCAPED_UNICODE) ?: '[]');

        return self::ROUTES_PREFIX
            . $userId
            . ':'
            . $persona
            . ':'
            . $roleSig
            . ':'
            . $this->globalRevision();
    }
}
