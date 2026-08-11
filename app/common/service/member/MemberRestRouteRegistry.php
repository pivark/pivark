<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

/** /api/v1/member REST 路由表（config/member_rest_dispatch.php） */
final class MemberRestRouteRegistry
{

    /** @var list<array{method:string,path:string,handler:array{0:class-string,1:string},pattern?:array<string,string>,handler_params?:list<string>}>|null */
    private static ?array $routes = null;

    public function reset(): void
    {
        self::$routes = null;
    }

    /** @return list<array{method:string,path:string,handler:array{0:class-string,1:string},pattern?:array<string,string>,handler_params?:list<string>}> */
    public function routes(): array
    {
        if (self::$routes !== null) {
            return self::$routes;
        }
        $config = config('member_rest_dispatch.routes');
        self::$routes = is_array($config) ? $config : [];

        return self::$routes;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function match(string $method, string $relativePath): ?array
    {
        $method = strtoupper(trim($method));
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        foreach ($this->routes() as $route) {
            if (strtoupper((string) ($route['method'] ?? '')) !== $method) {
                continue;
            }
            if ((string) ($route['path'] ?? '') === $relativePath) {
                return $route;
            }
        }

        foreach ($this->routes() as $route) {
            if (strtoupper((string) ($route['method'] ?? '')) !== $method) {
                continue;
            }
            $params = $this->matchPathParams((string) ($route['path'] ?? ''), $relativePath, $route['pattern'] ?? null);
            if ($params === null) {
                continue;
            }
            $matched = $route;
            if ($params !== []) {
                $matched['_params'] = $params;
            }

            return $matched;
        }

        return null;
    }

    /**
     * @param array<string, string>|null $pattern
     * @return array<string, string>|null
     */
    private function matchPathParams(string $routePath, string $relativePath, ?array $pattern): ?array
    {
        if (!str_contains($routePath, ':')) {
            return null;
        }
        $regex = '#^' . $this->compilePathPattern($routePath, $pattern) . '$#';
        if (!preg_match($regex, $relativePath, $matches)) {
            return null;
        }
        $params = [];
        foreach ($matches as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $params[$key] = (string) $value;
        }

        return $params;
    }

    /** @param array<string, string>|null $pattern */
    private function compilePathPattern(string $routePath, ?array $pattern): string
    {
        $segments = explode('/', trim($routePath, '/'));
        $compiled = [];
        foreach ($segments as $segment) {
            if (str_starts_with($segment, ':')) {
                $key = substr($segment, 1);
                $sub = is_array($pattern) && isset($pattern[$key]) ? (string) $pattern[$key] : '[^/]+';

                $compiled[] = '(?P<' . $key . '>' . $sub . ')';
                continue;
            }
            $compiled[] = preg_quote($segment, '#');
        }

        return implode('/', $compiled);
    }

    /** @param array<string, mixed> $route
     * @return list<string>
     */
    public function handlerArguments(array $route): array
    {
        $paramNames = $route['handler_params'] ?? [];
        if (!is_array($paramNames) || $paramNames === []) {
            return [];
        }
        $params = $route['_params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }
        $args = [];
        foreach ($paramNames as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $args[] = (string) ($params[$name] ?? '');
        }

        return $args;
    }

    /** 从 Request pathinfo 提取 member REST 相对路径 */
    public function relativePathFromRequest(string $pathinfo): string
    {
        $path = trim(str_replace('\\', '/', $pathinfo), '/');
        $prefix = 'api/v1/member';
        if (str_starts_with($path, $prefix . '/')) {
            return substr($path, strlen($prefix) + 1);
        }
        if ($path === $prefix) {
            return '';
        }

        return $path;
    }
}
