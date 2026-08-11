<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\service\menu\MenuService;
use app\common\support\PivarkVueRoute;

/** Vben 动态菜单路由（从 Spa 控制器 batch 8 下沉） */
class AdminSpaMenuRouteService
{

    /** @return list<array<string, mixed>> */
    public function vbenRoutes(): array
    {
        $cached = app(AdminSpaMenuRouteCacheService::class)->get();
        if ($cached !== null) {
            return $cached;
        }

        $routes = $this->buildVbenRoutes();
        app(AdminSpaMenuRouteCacheService::class)->put($routes);

        return $routes;
    }

    /** @return list<array<string, mixed>> */
    private function buildVbenRoutes(): array
    {
        $home = app(MenuService::class)->defaultHomeInfo();
        $routes = [
            [
                'name'      => 'PivarkWelcome',
                'path'      => '/dashboard/welcome',
                'component' => '/dashboard/welcome/index',
                'meta'      => [
                    'affixTab' => true,
                    'icon'     => 'lucide:layout-dashboard',
                    'order'    => -2,
                    'title'    => (string) ($home['title'] ?? '管理首页'),
                ],
            ],
        ];
        $routes = array_merge($routes, $this->buildVbenMenuRoutes(app(MenuService::class)->getTree()));
        $routes = PivarkVueRoute::appendMissingExplicitRoutes($routes);
        $routes = array_merge($routes, PivarkVueRoute::hiddenRoutes());

        return PivarkVueRoute::dedupeRoutesByName($routes);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function buildVbenMenuRoutes(array $nodes): array
    {
        $routes = [];
        foreach ($nodes as $node) {
            $title = (string) ($node['title'] ?? '');
            $href  = trim((string) ($node['route'] ?? ''));
            $kids  = !empty($node['children']) && is_array($node['children'])
                ? $this->buildVbenMenuRoutes($node['children'])
                : [];

            if ($kids !== []) {
                $routes[] = [
                    'name'      => 'MenuGroup' . (int) ($node['id'] ?? 0),
                    'path'      => '/group/' . (int) ($node['id'] ?? 0),
                    'component' => 'ParentView',
                    'meta'      => [
                        'icon'        => $this->resolveMenuIcon((string) ($node['icon'] ?? ''), '', $title),
                        'title'       => $title,
                        'parentView'  => true,
                    ],
                    'children' => $kids,
                ];
                continue;
            }
            if ($href === '') {
                continue;
            }
            $native = PivarkVueRoute::resolve($href, $title);
            if ($native === null) {
                continue;
            }
            $routeName = (string) ($native['name'] ?? '');
            $routePath = (string) ($native['path'] ?? '');
            if ($routeName === '' || $routePath === '') {
                continue;
            }
            $meta = $native['meta'] ?? [];
            $meta['icon'] = $this->resolveMenuIcon((string) ($node['icon'] ?? ''), $href, $title);
            if ($title !== '') {
                $meta['title'] = $title;
            }
            $entry = [
                'name' => $routeName,
                'path' => $routePath,
                'meta' => $meta,
            ];
            if (isset($native['redirect'])) {
                $entry['redirect'] = $native['redirect'];
            } else {
                $entry['component'] = (string) ($native['component'] ?? '');
            }
            $routes[] = $entry;
        }

        return $routes;
    }

    /** Layui/FontAwesome 菜单 icon → Vben lucide（按具体程度从高到低匹配） */
    private function mapIcon(string $icon): string
    {
        $icon = strtolower(trim($icon));
        if ($icon === '') {
            return 'lucide:circle';
        }
        if (str_starts_with($icon, 'lucide:') || str_starts_with($icon, 'mdi:')) {
            return $icon;
        }

        foreach (AdminSpaMenuRouteIconRegistry::layuiIconRules() as $needle => $lucide) {
            if (str_contains($icon, $needle)) {
                return $lucide;
            }
        }

        return 'lucide:circle';
    }

    /** 数据库 icon 为空或 fa-circle-o 时，按 route 补语义化 lucide 图标 */
    private function mapRouteIcon(string $route): ?string
    {
        $route = strtolower(trim($route));
        if ($route === '') {
            return null;
        }

        $map = AdminSpaMenuRouteIconRegistry::routeExactIcons();
        if (isset($map[$route])) {
            return $map[$route];
        }
        foreach (app(AdminSpaExplicitRouteRegistry::class)->routeIcons() as $href => $icon) {
            if (strtolower(trim($href)) === $route) {
                return $icon;
            }
        }

        return $this->inferRouteIconFromPath($route);
    }

    /** route 未在精确表命中时，按路径片段推断图标 */
    private function inferRouteIconFromPath(string $route): ?string
    {
        foreach (AdminSpaMenuRouteIconRegistry::routePatternIcons() as $pattern => $icon) {
            if (preg_match($pattern, $route)) {
                return $icon;
            }
        }

        return null;
    }

    /**
     * 叶菜单：路由精确/推断优先（避免库内旧 FA 盖掉语义图）。
     * 分组（无 route）：标题映射优先，再 FA。
     */
    private function resolveMenuIcon(string $icon, string $route, string $title = ''): string
    {
        if ($route !== '') {
            $byRoute = $this->mapRouteIcon($route);
            if ($byRoute !== null) {
                return $byRoute;
            }
        }

        $byTitle = AdminSpaMenuRouteIconRegistry::groupTitleIcon($title);
        if ($byTitle !== null) {
            return $byTitle;
        }

        $mapped = $this->mapIcon($icon);
        if ($mapped !== 'lucide:circle') {
            return $mapped;
        }

        return 'lucide:file-text';
    }
}
