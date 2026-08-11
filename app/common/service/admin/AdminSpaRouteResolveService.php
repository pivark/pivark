<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\support\PivarkVueRoute;

/** 解析后台 href 为 Vue SPA path（委托 PivarkVueRoute + AdminSpaExplicitRouteRegistry） */
final class AdminSpaRouteResolveService
{

    public function vuePathForAdminHref(string $href): string
    {
        $href = trim(str_replace('\\', '/', $href));
        if ($href === '') {
            return '';
        }
        if (!str_starts_with($href, '/')) {
            $href = '/' . $href;
        }
        $href = rtrim($href, '/') ?: $href;

        if ($this->isCanonicalSpaPath($href)) {
            return $href;
        }

        if (!str_starts_with($href, '/admin')) {
            $href = '/admin' . $href;
        }

        $native = PivarkVueRoute::resolve($href, '');
        if ($native === null) {
            return '';
        }
        if (isset($native['redirect']) && is_string($native['redirect']) && $native['redirect'] !== '') {
            return $native['redirect'];
        }

        return trim((string) ($native['path'] ?? ''));
    }

    private function isCanonicalSpaPath(string $href): bool
    {
        foreach ([
            '/weapp/host/',
            '/product/',
            '/system/',
            '/site/',
            '/content/',
            '/member/',
            '/seo/',
            '/plugin/',
        ] as $prefix) {
            if (str_starts_with($href, $prefix)) {
                return true;
            }
        }
        foreach (app(AdminSpaExplicitRouteRegistry::class)->spaCanonicalPathPrefixes() as $prefix) {
            if (str_starts_with($href, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
