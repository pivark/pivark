<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\service\plugin\gateway\PluginGatewayCallerContext;

/** 插件扩展 admin_permission 映射（boot 注册） */
final class AdminPermissionExtensionRegistry
{

    /** @var array<string, array<string, array{permission:string, identifier:?string}>> controller => action => meta */
    private static array $map = [];

    public function reset(): void
    {
        self::$map = [];
    }

    public function register(string $controller, string $action, string $permissionCode, ?string $identifier = null): void
    {
        $controller = strtolower(trim($controller));
        $action     = strtolower(trim($action));
        $permissionCode = trim($permissionCode);
        if ($controller === '' || $action === '' || $permissionCode === '') {
            return;
        }
        if ($identifier === null || trim($identifier) === '') {
            $identifier = PluginGatewayCallerContext::currentIdentifier();
        }
        $identifier = $identifier !== null ? strtolower(trim($identifier)) : null;
        if ($identifier === '') {
            $identifier = null;
        }
        self::$map[$controller][$action] = [
            'permission' => $permissionCode,
            'identifier' => $identifier,
        ];
    }

    /** @param array<string, string> $actions */
    public function registerMap(string $controller, array $actions, ?string $identifier = null): void
    {
        foreach ($actions as $action => $permissionCode) {
            if (!is_string($action) || !is_string($permissionCode)) {
                continue;
            }
            $this->register($controller, $action, $permissionCode, $identifier);
        }
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        $norm = str_replace('_', '-', $identifier);
        $dir  = str_replace('-', '_', $identifier);
        foreach (self::$map as $controller => $actions) {
            foreach ($actions as $action => $meta) {
                $owned = strtolower((string) ($meta['identifier'] ?? ''));
                $ctrl  = strtolower((string) $controller);
                $drop  = false;
                if ($owned !== '' && (str_replace('_', '-', $owned) === $norm || $owned === $identifier)) {
                    $drop = true;
                } elseif ($ctrl === $identifier || $ctrl === $norm || $ctrl === $dir) {
                    $drop = true;
                }
                if ($drop) {
                    unset(self::$map[$controller][$action]);
                }
            }
            if ((self::$map[$controller] ?? []) === []) {
                unset(self::$map[$controller]);
            }
        }
    }

    public function resolve(string $controller, string $action): ?string
    {
        $controller = strtolower(trim($controller));
        $action     = strtolower(trim($action));
        $meta       = self::$map[$controller][$action] ?? null;
        if (!is_array($meta)) {
            return null;
        }

        return $meta['permission'] ?? null;
    }
}