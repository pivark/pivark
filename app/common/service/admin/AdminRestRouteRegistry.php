<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;


/** /api/v1/admin REST 路由表（config/admin_rest/dispatch.php · 根 shim admin_rest_dispatch.php） */
final class AdminRestRouteRegistry
{

    /** @var list<array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>}>|null */
    private static ?array $routes = null;

    public function reset(): void
    {
        self::$routes = null;
    }

    /** @return list<array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>}> */
    public function routes(): array
    {
        if (self::$routes !== null) {
            return self::$routes;
        }
        $config = config('admin_rest_dispatch.routes');
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

    /** 从 Request pathinfo 提取 admin REST 相对路径 */
    public function relativePathFromRequest(string $pathinfo): string
    {
        $path = trim(str_replace('\\', '/', $pathinfo), '/');
        if (($q = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $q);
        }
        // PATHINFO 入口：/index.php/api/v1/admin/…
        if (preg_match('#^(?:index\.php/)+#i', $path) === 1) {
            $path = (string) preg_replace('#^(?:index\.php/)+#i', '', $path);
        }
        $prefix = 'api/v1/admin';
        if (str_starts_with($path, $prefix . '/')) {
            return substr($path, strlen($prefix) + 1);
        }
        // REQUEST_URI 等更长候选里夹带前缀
        $pos = stripos($path, $prefix . '/');
        if ($pos !== false) {
            return substr($path, $pos + strlen($prefix) + 1);
        }
        if (strcasecmp($path, $prefix) === 0) {
            return '';
        }

        return $path;
    }

    public function isPublic(?array $route): bool
    {
        return $route !== null && !empty($route['options']['public']);
    }

    public function allowsPasswordChange(?array $route): bool
    {
        return $route !== null && !empty($route['options']['password_change']);
    }

    /** @return array{0:string,1:string} */
    public function permissionKey(?array $route): array
    {
        if ($route === null) {
            return ['gateway', 'dispatch'];
        }
        $opts = $route['options'] ?? [];
        $controller = (string) ($opts['permission_controller'] ?? '');
        $action     = (string) ($opts['permission_action'] ?? '');
        if ($controller !== '' && $action !== '') {
            return [$controller, $action];
        }
        $handler = $route['handler'] ?? null;
        if (is_array($handler) && count($handler) === 2) {
            $class = (string) $handler[0];
            $short = strtolower(preg_replace('/^.*\\\\/', '', $class) ?: 'gateway');
            $method = strtolower((string) $handler[1]);

            return [$short, $method];
        }

        return ['gateway', 'dispatch'];
    }
}
