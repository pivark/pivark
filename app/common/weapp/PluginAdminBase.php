<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\weapp;

use app\common\service\product\ProductL1Access;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\WeappContext;
use app\common\support\PivarkVueRoute;
use app\common\support\SiteUrl;
use think\facade\Request;
use think\facade\Session;
use think\Response;

trait PluginAdminBase
{
    protected string $pluginIdentifier = '';

    /**
     * 插件后台 HTML 页统一 302 到 Vue SPA（保留 JSON/AJAX 接口）。
     *
     * @param array<string, mixed> $vars
     */
    protected function renderPluginView(string $view, array $vars = []): Response
    {
        $query   = Request::get();
        $href    = $this->pluginAdminHref($view);
        $spaPath = PivarkVueRoute::spaPathFromLegacy($href, is_array($query) ? $query : [], $vars);

        return redirect(
            $spaPath !== null ? SiteUrl::adminSpa($spaPath) : SiteUrl::adminSpa()
        );
    }

    protected function pluginAdminHref(string $view): string
    {
        $view = trim($view, '/');
        $id   = $this->pluginIdentifier;
        if ($view === '' || $view === 'index') {
            return '/admin/' . $id . '/index';
        }

        return '/admin/' . $id . '/' . $view;
    }

    protected function entitled(): bool
    {
        if (ProductL1Access::isKernel($this->pluginIdentifier)) {
            return ProductL1Access::allowsAdminApi();
        }

        return app(EntitlementService::class)->can($this->pluginIdentifier);
    }

    protected function renderDisabled(): Response
    {
        return $this->renderPluginView('index', []);
    }

    /** @return array<string, mixed> */
    protected function pluginContext(array $manifest, string $navKey = 'settings'): array
    {
        $visual = $this->pluginVisual($manifest);

        $ctx = [
            'plugin' => [
                'name'        => (string) ($manifest['name'] ?? $this->pluginIdentifier),
                'version'     => (string) ($manifest['version'] ?? '1.0.0'),
                'description' => (string) ($manifest['description'] ?? ''),
                'author'      => (string) ($manifest['author'] ?? ''),
                'icon_image'  => $visual['image'],
                'icon_color'  => $visual['color'],
                'icon'        => $visual['icon'],
                'package'     => (string) ($manifest['package'] ?? app(WeappContext::class)->packageForIdentifier($this->pluginIdentifier)),
                'instance_id' => app(WeappContext::class)->instanceId($this->pluginIdentifier),
            ],
            'weapp_admin_base' => '/admin/weapp/' . $this->pluginIdentifier,
        ];
        if ($navKey !== '') {
            $ctx['navKey'] = $navKey;
        }

        return $ctx;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{icon:string,color:string,image:string}
     */
    protected function pluginVisual(array $manifest): array
    {
        $iconRaw = trim((string) ($manifest['icon'] ?? ''));
        $color   = trim((string) ($manifest['color'] ?? '#5fb878'));
        if ($iconRaw !== '' && (str_starts_with($iconRaw, '/') || str_starts_with($iconRaw, 'http'))) {
            return ['icon' => '', 'color' => $color, 'image' => $iconRaw];
        }

        return ['icon' => '', 'color' => $color, 'image' => ''];
    }

    protected function assignAdminSession(): void
    {
        Session::get('admin_user', []);
    }
}
