<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use think\facade\Route;

/** 后台插件 API 路由（boot 注册 · middleware 统一 apply） */
final class AdminPluginRouteRegistry
{

    /** @var list<array{method:string,path:string,handler:callable|class-string|array{0:class-string,1:string}}> */
    private static array $routes = [];

    private static bool $applied = false;

    public function reset(): void
    {
        self::$routes  = [];
        self::$applied = false;
    }

    /** @param callable|class-string|array{0:class-string,1:string} $handler */
    public function register(string $method, string $path, callable|string|array $handler, ?string $identifier = null): void
    {
        self::$routes[] = [
            'method'     => strtolower(trim($method)),
            'path'       => ltrim(trim($path), '/'),
            'handler'    => $handler,
            'identifier' => $identifier !== null ? strtolower(trim($identifier)) : null,
        ];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        self::$routes = array_values(array_filter(
            self::$routes,
            static fn (array $route): bool => ($route['identifier'] ?? null) !== $identifier
                && !str_contains((string) ($route['path'] ?? ''), $identifier)
                && !self::handlerContainsIdentifier($route['handler'], $identifier),
        ));
        self::$applied = false;
    }

    /** @param callable|class-string|array{0:class-string,1:string} $handler */
    private static function handlerContainsIdentifier(mixed $handler, string $identifier): bool
    {
        if (is_string($handler)) {
            return str_contains(strtolower($handler), $identifier);
        }
        if (is_array($handler) && isset($handler[0]) && is_string($handler[0])) {
            return str_contains(strtolower($handler[0]), str_replace('-', '_', $identifier));
        }

        return false;
    }

    /** @param bool $inline 已在 app/route/admin.php 的 admin 组内调用（须早于 SPA fallback） */
    public function applyOnce(bool $inline = false): void
    {
        if (self::$applied || self::$routes === []) {
            return;
        }
        self::$applied = true;

        $register = static function (): void {
            foreach (self::$routes as $route) {
                Route::rule($route['path'], $route['handler'], $route['method']);
            }
        };

        if ($inline) {
            $register();

            return;
        }

        Route::group('admin', static function () use ($register): void {
            $register();
        })->middleware([
            \app\admin\middleware\AuthCheck::class,
            \app\admin\middleware\CsrfCheck::class,
            \app\admin\middleware\IdempotencyCheck::class,
            \app\admin\middleware\SensitiveConfirmCheck::class,
        ]);
    }
}
