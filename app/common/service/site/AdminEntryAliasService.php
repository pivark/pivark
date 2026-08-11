<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 后台入口别名：/manage → 内部仍走 admin 路由，浏览器 URL 保持别名
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\model\FloatContactItem;

use app\common\service\config\ConfigService;

final class AdminEntryAliasService
{

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    public const CONFIG_KEY = 'admin_entry_alias';

    /** 不可作为别名的路径（与前台/静态冲突） */
    private const RESERVED = [
        'admin',
        'api',
        'install',
        'static',
        'public',
        'docs',
        'member',
        'member-publish',
        'uploads',
        'template',
    ];

    public function normalizeInput(string $raw): string
    {
        $alias = strtolower(trim($raw));
        if ($alias === '' || in_array($alias, self::RESERVED, true)) {
            return '';
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $alias)) {
            return '';
        }

        return $alias;
    }

    public function activeAlias(): string
    {
        // 路由改写须与 configs 表一致，不可走 getAll 全表缓存（CLI/HTTP 缓存滞后会导致别名 404）
        $alias = $this->normalizeInput((string) $this->config->getDirect(self::CONFIG_KEY, ''));

        return $alias;
    }

    public function isActive(): bool
    {
        return $this->activeAlias() !== '';
    }

    /** 浏览器可见的后台根路径，如 /manage 或 /admin（短链；须伪静态或物理入口） */
    public function publicBasePath(): string
    {
        $alias = $this->activeAlias();

        return $alias !== '' ? '/' . $alias : '/admin';
    }

    /**
     * 无 Nginx 伪静态时的物理脚本入口（DirectoryIndex / PATH_INFO）。
     * 例：/admin/index.php ；别名启用时为 /manage/index.php（须已 materialize）。
     */
    public function scriptEntryPath(): string
    {
        return rtrim($this->publicBasePath(), '/') . '/index.php';
    }

    /**
     * Vue Router base（带尾斜杠）。
     * 固定物理入口 /admin/index.php/：装完即可进后台，不依赖 Nginx 伪静态。
     * 用户自行粘贴伪静态后，短链 /admin/… 仍可进（与本 base 并存）。
     */
    public function routerBase(): string
    {
        return $this->scriptEntryPath() . '/';
    }

    /**
     * 后台 JSON API 前缀。
     * 走 /index.php/api/v1/admin，避免 Nginx 无伪静态时 /api/v1/admin 404。
     */
    public function apiBasePath(): string
    {
        return '/index.php/api/v1/admin';
    }

    /**
     * 将别名路径改写成 admin/* 供 ThinkPHP 路由匹配
     *
     * @return string|null 改写后的 pathinfo（如 admin/spa/bootstrap），无需改写则 null
     */
    public function rewritePathinfo(string $pathinfo): ?string
    {
        $alias = $this->activeAlias();
        if ($alias === '') {
            return null;
        }

        $path = trim($pathinfo, '/');
        if ($path === $alias || str_starts_with($path, $alias . '/')) {
            $suffix = $path === $alias ? '' : substr($path, strlen($alias));

            return 'admin' . $suffix;
        }

        return null;
    }

    public function isBlockedAdminPath(string $pathinfo): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $path = trim($pathinfo, '/');

        return $path === 'admin' || str_starts_with($path, 'admin/');
    }
}
