<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\service\weapp\WeappAdminGateway;

/**
 * 插件后台 SPA 通用 Host 路由（零外放 · SSOT component=/weapp/host/index）
 *
 * 规范路径：/weapp/host/{pluginId}/{page}
 */
final class WeappAdminSpaHostRoutes
{
    public const HOST_COMPONENT = '/weapp/host/index';

    public static function hostPath(string $pluginId, string $page): string
    {
        $pluginId = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($pluginId))) ?? '';
        $page     = trim(str_replace('\\', '/', $page), '/');

        return '/weapp/host/' . $pluginId . ($page !== '' ? '/' . $page : '');
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function pluginPageDef(string $pluginId, string $page, array $meta = []): array
    {
        $page = trim(str_replace('\\', '/', $page), '/');

        return [
            'path'      => self::hostPath($pluginId, $page),
            'component' => self::HOST_COMPONENT,
            'meta'      => array_merge($meta, [
                'pivarkWeapp' => $pluginId,
                'weappPage'   => $page,
            ]),
        ];
    }

    /**
     * 注册插件业务页：canonical path 默认 /weapp/host/{id}/{page}，注册键 = SPA path。
     *
     * @param array<string, mixed> $meta
     */
    public static function registerPluginPage(
        WeappAdminGateway $gw,
        string $pluginId,
        string $page,
        string $routeName,
        array $meta,
        ?string $spaPath = null,
        ?string $component = null,
    ): void {
        $hostDef = array_merge(
            ['name' => $routeName],
            self::pluginPageDef($pluginId, $page, $meta),
        );
        if ($spaPath !== null && $spaPath !== '') {
            $hostDef['path'] = $spaPath;
        }
        if ($component !== null && $component !== '') {
            $hostDef['component'] = $component;
        }

        $routeKey = (string) ($hostDef['path'] ?? '');
        if ($routeKey === '') {
            return;
        }

        $gw->adminSpaExplicitRouteRegister($routeKey, $hostDef);
    }
}
