<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

/** 后台管理门户（AD-021） */
class AdminPortalService
{

    public const PORTAL_EXTERNAL = 'external';
    public const PORTAL_INTERNAL = 'internal';
    public const PORTAL_UNIFIED   = 'unified';

    /** @var list<string> */
    private const ALLOWED = [
        self::PORTAL_EXTERNAL,
        self::PORTAL_INTERNAL,
        self::PORTAL_UNIFIED,
    ];

    public function current(): string
    {
        $raw = strtolower(trim((string) env('PIVARK_ADMIN_PORTAL', self::PORTAL_UNIFIED)));
        if (!in_array($raw, self::ALLOWED, true)) {
            return self::PORTAL_UNIFIED;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $menu
     */
    public function menuVisible(array $menu): bool
    {
        $portal = $this->current();
        if ($portal === self::PORTAL_UNIFIED) {
            return true;
        }

        $raw = trim((string) ($menu['portals'] ?? ''));
        if ($raw === '') {
            return true;
        }

        $allowed = array_filter(array_map('trim', explode(',', strtolower($raw))));

        return in_array($portal, $allowed, true);
    }

    /**
     * @param array<string, mixed>|null $manifest
     */
    public function pluginAdminVisible(?array $manifest): bool
    {
        $portal = $this->current();
        if ($portal === self::PORTAL_UNIFIED || !is_array($manifest)) {
            return true;
        }

        $admin = $manifest['admin'] ?? null;
        if (!is_array($admin)) {
            return true;
        }

        $portals = $admin['portals'] ?? null;
        if (!is_array($portals) || $portals === []) {
            return true;
        }

        $normalized = array_map(static fn ($p): string => strtolower(trim((string) $p)), $portals);

        return in_array($portal, $normalized, true);
    }
}
