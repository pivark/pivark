<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;
use think\Session;

/**
 * 会话初始化（替代全局盲目 SessionInit）：
 * - 已有 PHPSESSID / 非 GET / 后台·API·会员等路径：照常开 Session 并下发 Cookie
 * - 匿名前台 GET：不开 Session、不下发 Cookie（配合 FrontCsrfService 无状态 token）
 */
final class PivarkSessionInit
{
    public const REQUEST_FLAG = 'pivark_session_started';

    private bool $booted = false;

    public function __construct(
        protected App $app,
        protected Session $session,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = $this->session->getName();
        $needs     = $this->needsSession($request, $cookieName);

        if ($needs) {
            $this->bootSession($request, $cookieName);
        }
        $request->{self::REQUEST_FLAG} = $this->booted;

        /** @var Response $response */
        $response = $next($request);

        // 请求中途若业务写了 Session（极少见：匿名 GET 变登录），允许补 boot
        if (!$this->booted && $this->sessionWasWritten()) {
            $this->bootSession($request, $cookieName);
            $request->{self::REQUEST_FLAG} = true;
        }

        if ($this->booted) {
            $response->setSession($this->session);
            $this->app->cookie->set(
                $cookieName,
                $this->session->getId(),
                $this->session->getConfig('expire')
            );
        }

        return $response;
    }

    public function end(Response $response): void
    {
        if ($this->booted) {
            $this->session->save();
        }
    }

    private function bootSession(Request $request, string $cookieName): void
    {
        if ($this->booted) {
            return;
        }
        $varSessionId = $this->app->config->get('session.var_session_id');
        if ($varSessionId && $request->request($varSessionId)) {
            $sessionId = $request->request($varSessionId);
        } else {
            $sessionId = $request->cookie($cookieName);
        }
        if ($sessionId) {
            $this->session->setId((string) $sessionId);
        }
        $this->session->init();
        $request->withSession($this->session);
        $this->booted = true;
    }

    /** Session 在未 boot 时被写入（防御）；默认游客路径不应触发 */
    private function sessionWasWritten(): bool
    {
        try {
            // ThinkPHP Session::has / all 在未 init 时可能抛错或空
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function needsSession(Request $request, string $cookieName): bool
    {
        if ((string) $request->cookie($cookieName, '') !== '') {
            return true;
        }

        $method = strtoupper($request->method());
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $path = (string) $request->pathinfo();
        if ($path === '') {
            $uri = (string) $request->server('REQUEST_URI', '/');
            $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        }
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '/' || $path === '') {
            return false;
        }

        $prefixNeeds = [
            'admin',
            'api',
            'member',
            'captcha',
            'install',
            'pay',
            'payment',
            'notify',
            'oauth',
            'wechat',
        ];
        $first = strtolower(explode('/', trim($path, '/'))[0] ?? '');
        if (in_array($first, $prefixNeeds, true)) {
            return true;
        }

        // 显式预览参数：可能读 admin_user Session
        if ((string) $request->get('preview', '') === '1'
            || trim((string) $request->get('preview_token', '')) !== ''
        ) {
            return true;
        }

        return false;
    }
}
