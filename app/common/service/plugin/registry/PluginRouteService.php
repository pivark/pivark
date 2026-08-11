<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/** 插件动态路由注册（boot 时收集，middleware 统一 apply） */
declare(strict_types=1);

namespace app\common\service\plugin\registry;

use app\common\service\plugin\gateway\PluginGatewayCallerContext;
use think\facade\Route;

class PluginRouteService
{
    /** @var list<array{method:string,path:string,handler:string,pattern?:array<string,string>,middleware?:list<class-string>|null,identifier:?string,complete_match?:bool}> */
    private static array $routes = [];

    private static bool $applied = false;

    /** api/v1 插件 POST 默认中间件（与 app/route/api.php 组一致） */
    private const API_V1_MIDDLEWARE = [
        \app\api\middleware\ApiCorsMiddleware::class,
        \app\api\middleware\ApiRateLimit::class,
        \app\api\middleware\ApiMemberWriteGuard::class,
        \app\common\middleware\FrontPostGuard::class,
    ];

    /**
     * @param array<string, string>   $pattern
     * @param list<class-string>|null $middleware 额外中间件；null 时 api/v1 路由自动挂载默认 API 中间件
     */
    public function register(
        string $method,
        string $path,
        string $handler,
        array $pattern = [],
        ?array $middleware = null,
        bool $completeMatch = false,
        ?string $identifier = null,
    ): void {
        if ($identifier === null || trim($identifier) === '') {
            $identifier = PluginGatewayCallerContext::currentIdentifier();
        }
        $identifier = $identifier !== null ? strtolower(trim($identifier)) : null;
        if ($identifier === '') {
            $identifier = null;
        }

        self::$routes[] = [
            'method'         => strtolower($method),
            'path'           => ltrim($path, '/'),
            'handler'        => $handler,
            'pattern'        => $pattern,
            'middleware'     => $middleware,
            'complete_match' => $completeMatch,
            'identifier'     => $identifier,
        ];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        $norm = str_replace('_', '-', $identifier);
        $dir  = str_replace('-', '_', $identifier);
        self::$routes = array_values(array_filter(
            self::$routes,
            static function (array $route) use ($identifier, $norm, $dir): bool {
                $owned = (string) ($route['identifier'] ?? '');
                if ($owned !== '' && (str_replace('_', '-', $owned) === $norm || $owned === $identifier)) {
                    return false;
                }
                $path = strtolower((string) ($route['path'] ?? ''));
                if (str_contains($path, $identifier) || str_contains($path, $norm) || str_contains($path, $dir)) {
                    return false;
                }
                $handler = strtolower((string) ($route['handler'] ?? ''));
                if (str_contains($handler, 'weapp\\' . $dir . '\\')
                    || str_contains($handler, 'weapp\\\\' . $dir . '\\\\')
                    || str_contains($handler, $identifier)
                    || str_contains($handler, $dir)) {
                    return false;
                }

                return true;
            },
        ));
        self::$applied = false;
    }

    public function reset(): void
    {
        self::$routes  = [];
        self::$applied = false;
    }

    public function applyOnce(): void
    {
        if (self::$applied) {
            return;
        }
        self::$applied = true;

        $routes = self::$routes;
        usort($routes, static function (array $a, array $b): int {
            return strlen($b['path']) <=> strlen($a['path']);
        });

        foreach ($routes as $route) {
            $rule = Route::rule(
                $route['path'],
                $route['handler'],
                $route['method']
            );
            if (!empty($route['complete_match'])) {
                $rule->completeMatch(true);
            }
            if (isset($route['pattern']) && $route['pattern'] !== []) {
                $rule->pattern($route['pattern']);
            }
            $middleware = $this->resolveMiddleware($route);
            if ($middleware !== []) {
                $rule->middleware($middleware);
            }
        }
    }

    /**
     * @param array{method:string,path:string,handler:string,pattern?:array<string,string>,middleware?:list<class-string>|null} $route
     * @return list<class-string>
     */
    private function resolveMiddleware(array $route): array
    {
        if (array_key_exists('middleware', $route) && $route['middleware'] !== null) {
            return $route['middleware'];
        }
        $path = $route['path'];
        if (!str_starts_with($path, 'api/v1/')) {
            return [];
        }

        return self::API_V1_MIDDLEWARE;
    }
}
