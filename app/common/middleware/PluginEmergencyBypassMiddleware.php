<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\middleware;

use app\common\support\AppTime;
use app\common\support\InstallGate;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;
use think\Request;
use think\Response;

/**
 * 插件紧急 Safe Mode：任意 URL 加 ?_pv_emergency={token} 即写入 runtime 锁并跳过插件 boot。
 * token 留空时本中间件不生效（须配置 PIVARK_PLUGIN_EMERGENCY_TOKEN）。
 */
final class PluginEmergencyBypassMiddleware
{
    private const QUERY_KEY = '_pv_emergency';

    private const NOTICE_HTML = '<div id="pv-plugin-emergency-notice" style="position:fixed;z-index:99999;left:0;right:0;top:0;padding:12px 16px;background:#7c2d12;color:#fff;font:14px/1.5 sans-serif;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.25)">'
        . '已启用插件紧急安全模式，所有插件已暂停。请进入后台「我的插件」停用问题插件，确认无误后再关闭 Safe Mode。'
        . '</div>';

    public function handle(Request $request, \Closure $next): Response
    {
        if (!InstallGate::isInstalled()) {
            return $next($request);
        }

        $configured = trim((string) config('plugin.security.emergency_bypass_token', ''));
        if ($configured === '') {
            return $next($request);
        }

        $provided = trim((string) $request->get(self::QUERY_KEY, ''));
        if ($provided === '' || !hash_equals($configured, $provided)) {
            return $next($request);
        }

        $this->activateRuntimeSafeModeLock();

        $response = $next($request);
        $response->header(['X-Pivark-Plugin-Safe-Mode' => 'emergency']);

        return $this->maybeInjectNotice($response);
    }

    private function activateRuntimeSafeModeLock(): void
    {
        $dir = ProjectPaths::runtimeDir();
        LocalFile::mkdirIfMissing($dir);
        $lock = $dir . 'plugin_safe_mode.lock';
        if (!is_file($lock)) {
            file_put_contents($lock, AppTime::format('c') . ' emergency_bypass' . "\n");
        }
    }

    private function maybeInjectNotice(Response $response): Response
    {
        $contentType = strtolower((string) $response->getHeader('Content-Type'));
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getContent();
        if ($body === '' || !str_contains($body, '<body')) {
            return $response;
        }

        if (str_contains($body, 'pv-plugin-emergency-notice')) {
            return $response;
        }

        $patched = preg_replace('/<body(\s[^>]*)?>/i', '$0' . self::NOTICE_HTML, $body, 1);
        if (is_string($patched) && $patched !== $body) {
            $response->setContent($patched);
        }

        return $response;
    }
}
