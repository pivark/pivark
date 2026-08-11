<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\AdminApiResponse;
use think\facade\Request;
use think\Response;

/** 将 /api/v1/member/* 分发到已注册 handler */
final class MemberRestDispatchService
{

    public function __construct(
        private readonly MemberRestRouteRegistry $memberRestRouteRegistry,
    ) {
    }

    public function dispatch(?string $path = null): Response
    {
        $relative = trim(str_replace('\\', '/', (string) ($path ?? '')), '/');
        if ($relative === '') {
            $relative = $this->memberRestRouteRegistry->relativePathFromRequest((string) Request::pathinfo());
        }

        $registry = $this->memberRestRouteRegistry;
        $route = $registry->match(Request::method(), $relative);
        if ($route === null) {
            return AdminApiResponse::fail('接口不存在：' . $relative, 404);
        }

        $handler = $route['handler'] ?? null;
        if (!is_array($handler) || count($handler) !== 2) {
            return AdminApiResponse::fail('路由 handler 未配置');
        }

        [$class, $method] = $handler;
        if (!is_string($class) || !class_exists($class) || !is_string($method) || $method === '') {
            return AdminApiResponse::fail('路由 handler 无效');
        }

        $controller = app($class);
        if (!method_exists($controller, $method)) {
            return AdminApiResponse::fail('动作不存在：' . $method, 404);
        }

        $args   = $registry->handlerArguments($route);
        $result = $controller->{$method}(...$args);
        if ($result instanceof Response) {
            return $result;
        }

        if ($result instanceof \app\common\support\ServiceResult) {
            return AdminApiResponse::fromResult($result);
        }

        return AdminApiResponse::fail('无效的响应类型');
    }
}
