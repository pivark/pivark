<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\service\plugin\extension\HostRuntimeProbe;
use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\service\plugin\registry\PluginRouteService;
use think\Response;

/** 会员中心插件页扩展（member.center.page）+ 宿主软调用（自 HostRuntimeProbe 收口） */
final class MemberCenterPageRegistry
{

    public function __construct(
        private readonly PluginRouteService $pluginRoute,
    ) {
    }

    /**
     * host_only 会员首页子路径（如 activate）；空则内核 /member/center。
     */
    public function hostHomePath(): string
    {
        foreach (HostRuntimeProbe::activeHostRuntimeHandlers() as $handler) {
            if (!method_exists($handler, 'memberCenterHomePath')) {
                continue;
            }
            $path = trim((string) $handler->memberCenterHomePath(), '/');
            if ($path !== '' && $path !== 'center') {
                return $path;
            }
        }

        return '';
    }

    public function hostHideDocumentPublish(): bool
    {
        foreach (HostRuntimeProbe::activeHostRuntimeHandlers() as $handler) {
            if (method_exists($handler, 'memberCenterHideDocumentPublish')
                && $handler->memberCenterHideDocumentPublish()) {
                return true;
            }
        }

        return false;
    }

    public function hostPluginNavGroupLabel(): string
    {
        foreach (HostRuntimeProbe::activeHostRuntimeHandlers() as $handler) {
            if (!method_exists($handler, 'memberCenterPluginNavGroupLabel')) {
                continue;
            }
            $label = trim((string) $handler->memberCenterPluginNavGroupLabel());
            if ($label !== '') {
                return $label;
            }
        }

        return '';
    }

    public function dispatchHostMemberAccountRoute(string $method, string $path): ?Response
    {
        foreach (PluginDistributionPolicy::identifiers() as $identifier) {
            $result = HostRuntimeProbe::hostRuntimeInvoke($identifier, 'dispatchMemberAccountRoute', [$method, $path]);
            if ($result instanceof Response) {
                return $result;
            }
        }

        return null;
    }

    private const MEMBER_PAGE_HANDLER = 'app\\home\\controller\\Member@pluginMemberPage';

    private const MEMBER_PAGE_POST_HANDLER = 'app\\home\\controller\\Member@pluginMemberPagePost';

    /** @var array<string, array<string, callable>> */
    private static array $handlers = [];

    /** @var array<string, array{identifier:string, action:string}> */
    private static array $httpRoutes = [];

    /** @var array<string, string> member 相对路径 → 页面标题（面包屑/未传 pageTitle 时用） */
    private static array $pageTitles = [];

    public function reset(): void
    {
        self::$handlers   = [];
        self::$httpRoutes = [];
        self::$pageTitles = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return;
        }
        unset(self::$handlers[$identifier]);
        foreach (array_keys(self::$httpRoutes) as $key) {
            $meta = self::$httpRoutes[$key];
            if (($meta['identifier'] ?? '') !== $identifier) {
                continue;
            }
            unset(self::$httpRoutes[$key]);
            if (str_starts_with($key, 'get member/')) {
                $path  = substr($key, 4);
                $scene = $this->memberSceneKey(substr($path, 7));
                unset(self::$pageTitles[$scene]);
            }
        }
    }

    /**
     * @param callable(array<string,mixed>): mixed $handler
     * @param string|null $getMemberPath  相对 member/ 的路径，如 orders、merchant/apply
     * @param string|null $postMemberPath POST 相对 member/ 的路径
     * @param string|null $pageTitle      面包屑/默认页标题
     */
    public function register(
        string $identifier,
        string $action,
        callable $handler,
        ?string $getMemberPath = null,
        ?string $postMemberPath = null,
        ?string $pageTitle = null,
    ): void {
        $identifier = trim($identifier);
        $action     = trim($action);
        if ($identifier === '' || $action === '') {
            return;
        }
        self::$handlers[$identifier] ??= [];
        self::$handlers[$identifier][$action] = $handler;

        if ($getMemberPath !== null && trim($getMemberPath) !== '') {
            $this->bindHttpRoute('get', $getMemberPath, $identifier, $action);
            $title = trim((string) $pageTitle);
            if ($title !== '') {
                self::$pageTitles[$this->memberSceneKey($getMemberPath)] = $title;
            }
        }
        if ($postMemberPath !== null && trim($postMemberPath) !== '') {
            $this->bindHttpRoute('post', $postMemberPath, $identifier, $action);
        }
    }

    /**
     * @return array{identifier:string, action:string}|null
     */
    public function resolveHttpRoute(string $method, string $path): ?array
    {
        $method = strtolower(trim($method));
        $path   = $this->normalizeMemberHttpPath($path);
        if ($method === '' || $path === '') {
            return null;
        }
        $key = $method . ' ' . $path;

        return self::$httpRoutes[$key] ?? null;
    }

    public function pageTitleForMemberScene(string $scene): ?string
    {
        $scene = $this->memberSceneKey($scene);

        return self::$pageTitles[$scene] ?? null;
    }

    /**
     * 模板变量：member_{path}_url => /member/{path}（由插件 boot 注册，L1 不写死路径）
     *
     * @return array<string, string>
     */
    public function templateMemberUrlVars(): array
    {
        $out = [];
        foreach (self::$httpRoutes as $key => $_meta) {
            if (!str_starts_with($key, 'get member/')) {
                continue;
            }
            $path   = substr($key, 4);
            $suffix = substr($path, 7);
            if ($suffix === '') {
                continue;
            }
            $out['member_' . str_replace('/', '_', $suffix) . '_url'] = '/' . $path;
        }

        return $out;
    }

    /**
     * @param array{
     *   member: array<string,mixed>,
     *   render: callable(string,string,string,string,array=): Response,
     *   redirect_unavailable: callable(string): Response
     * } $host
     */
    public function dispatch(string $identifier, string $action, array $host): mixed
    {
        $identifier = trim($identifier);
        $action     = trim($action);
        if ($identifier === '' || $action === '' || !isset(self::$handlers[$identifier][$action])) {
            return null;
        }

        return (self::$handlers[$identifier][$action])($host);
    }

    private function bindHttpRoute(string $method, string $memberRelativePath, string $identifier, string $action): void
    {
        $method = strtolower(trim($method));
        $path   = $this->normalizeMemberHttpPath('member/' . ltrim(trim($memberRelativePath, '/'), '/'));
        if ($method === '' || $path === '') {
            return;
        }
        $key = $method . ' ' . $path;
        if (isset(self::$httpRoutes[$key])) {
            return;
        }
        self::$httpRoutes[$key] = [
            'identifier' => $identifier,
            'action'     => $action,
        ];

        $suffix    = ltrim(substr($path, 7), '/');
        $routePath = $method === 'post'
            ? ($suffix === '' ? 'api/v1/member' : 'api/v1/member/' . $suffix)
            : $path;
        $handler   = $method === 'post' ? self::MEMBER_PAGE_POST_HANDLER : self::MEMBER_PAGE_HANDLER;
        $middleware = $method === 'post'
            ? [\app\common\middleware\FrontPostGuard::class]
            : null;
        $this->pluginRoute->register($method, $routePath, $handler, [], $middleware, true);
    }

    private function normalizeMemberHttpPath(string $path): string
    {
        $path = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, 'api/v1/member/')) {
            $path = 'member/' . substr($path, strlen('api/v1/member/'));
        } elseif ($path === 'api/v1/member') {
            $path = 'member';
        } elseif (!str_starts_with($path, 'member/') && $path !== 'member') {
            $path = 'member/' . $path;
        }

        return $path;
    }

    private function memberSceneKey(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'member/')) {
            $path = substr($path, 7);
        }

        return $path;
    }
}
