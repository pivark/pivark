<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\site\AdminEntryAliasService;
use think\Response;

/** Vue 主后台（PivArk Admin）静态入口 */
class AdminSpa
{
    public static function indexPath(): string
    {
        return root_path() . 'public/static/admin/dist/index.html';
    }

    public static function respond(): Response
    {
        // 客户站自愈：无伪静态时 materialize admin/index.php（dig Vue 仓跳过）
        try {
            app(\app\common\service\site\AdminPhysicalEntryService::class)->ensure();
        } catch (\Throwable) {
            // ignore
        }

        $viteOrigin = self::viteDevOrigin();
        if ($viteOrigin !== null) {
            return self::respondViteDev($viteOrigin);
        }

        $path = self::indexPath();
        if (!is_file($path)) {
            $hint = 'Vue 后台尚未构建。开发模式请在 .env 设置 ADMIN_VITE_DEV=http://127.0.0.1:5777 并运行 npm run dev:admin；'
                . '或执行：cd admin && pnpm build:app';

            return Response::create(
                '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PivArk Admin</title></head>'
                . '<body style="font-family:sans-serif;padding:2rem;max-width:640px">'
                . '<h1>后台前端未构建</h1><p>' . htmlspecialchars($hint) . '</p></body></html>',
                'html',
                503
            );
        }

        $html = (string) file_get_contents($path);
        $html = self::injectHeadScripts($html);
        $html = self::injectDistDevFallbackBar($html);

        return Response::create($html, 'html', 200)->header(self::noCacheHeaders());
    }

    /**
     * 开发：本地 Vite HMR（需 APP_DEBUG + ADMIN_VITE_DEV + 本地 admin 开发服务）
     */
    private static function respondViteDev(string $viteOrigin): Response
    {
        $hashScript = self::headScriptsInner();
        $client = htmlspecialchars($viteOrigin . '/@vite/client', ENT_QUOTES, 'UTF-8');
        $main = htmlspecialchars($viteOrigin . '/src/main.ts', ENT_QUOTES, 'UTF-8');

        $html = '<!doctype html><html lang="zh"><head>'
            . $hashScript
            . '<meta charset="UTF-8" />'
            . '<meta name="viewport" content="width=device-width,initial-scale=1.0" />'
            . '<title>PivArk Admin (Vite Dev)</title>'
            . '<link rel="icon" href="/favicon.ico" />'
            . '<style>#pv-vite-dev-bar{position:fixed;bottom:8px;right:8px;z-index:99999;'
            . 'padding:4px 10px;border-radius:4px;font:12px/1.4 sans-serif;'
            . 'color:#fff;background:#16a34a;box-shadow:0 2px 8px rgba(0,0,0,.25);pointer-events:none}</style>'
            . '</head><body>'
            . '<div id="app"></div>'
            . '<div id="pv-vite-dev-bar">Vite Dev · ' . htmlspecialchars($viteOrigin, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<script type="module" src="' . $client . '"></script>'
            . '<script type="module" src="' . $main . '"></script>'
            . '</body></html>';

        return Response::create($html, 'html', 200)->header(self::noCacheHeaders());
    }

    /** @return array<string, string> */
    private static function noCacheHeaders(): array
    {
        return [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma'        => 'no-cache',
        ];
    }

    /**
     * 仅 debug 环境允许；值为 Vite 根地址，如 http://127.0.0.1:5777。
     * 若未启动 dev server 则自动回退到 public/static/admin/dist，避免后台白屏。
     */
    private static function viteDevOrigin(): ?string
    {
        if (!(bool) env('APP_DEBUG')) {
            return null;
        }

        $raw = trim((string) env('ADMIN_VITE_DEV', ''));
        if ($raw === '' || $raw === '0' || strcasecmp($raw, 'false') === 0) {
            return null;
        }

        if (!preg_match('#^https?://[\w.\-]+(?::\d+)?$#', $raw)) {
            return null;
        }

        $origin = rtrim($raw, '/');
        if (!self::isViteDevReachable($origin)) {
            return null;
        }

        return $origin;
    }

    /** 快速探测 Vite 端口是否可连（未启动时勿走 HMR 壳） */
    private static function isViteDevReachable(string $origin): bool
    {
        if (!preg_match('#^https?://([^:/]+)(?::(\d+))?#', $origin, $m)) {
            return false;
        }
        $host = $m[1];
        $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : (str_starts_with($origin, 'https') ? 443 : 80);

        $errno  = 0;
        $errstr = '';
        $fp     = @fsockopen($host, $port, $errno, $errstr, 0.8);
        if ($fp !== false) {
            fclose($fp);

            return true;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 1.2,
                'ignore_errors' => true,
            ],
        ]);
        $probe = @file_get_contents(rtrim($origin, '/') . '/@vite/client', false, $ctx);

        return is_string($probe) && $probe !== '';
    }

    /** debug + 已配 ADMIN_VITE_DEV 但 Vite 未起：dist 模式右下角提示，避免「改了没生效」 */
    private static function injectDistDevFallbackBar(string $html): string
    {
        if (!(bool) env('APP_DEBUG')) {
            return $html;
        }

        $raw = trim((string) env('ADMIN_VITE_DEV', ''));
        if ($raw === '' || $raw === '0' || strcasecmp($raw, 'false') === 0) {
            return $html;
        }

        $vite = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
        $bar  = '<div id="pv-vite-dev-bar" style="position:fixed;bottom:8px;right:8px;z-index:99999;'
            . 'padding:6px 12px;border-radius:4px;font:12px/1.4 sans-serif;color:#fff;'
            . 'background:#ea580c;box-shadow:0 2px 8px rgba(0,0,0,.25);max-width:min(420px,92vw)">'
            . 'Dist 产物 · Vite 未连（' . $vite . '）· 请 <code>npm run dev:admin</code> 或 <code>pnpm build:app</code>'
            . '</div>';

        if (stripos($html, '</body>') !== false) {
            return (string) preg_replace('/<\/body>/i', $bar . '</body>', $html, 1);
        }

        return $html . $bar;
    }

    /** 注入别名路由/API 基址 + 旧 hash 入口兼容（须在 head 最前，早于 Vue 模块） */
    private static function injectHeadScripts(string $html): string
    {
        $script = self::headScriptsInner();
        if (preg_match('/<head[^>]*>/i', $html, $m)) {
            return (string) preg_replace('/<head[^>]*>/i', $m[0] . $script, $html, 1);
        }

        return $script . $html;
    }

    private static function headScriptsInner(): string
    {
        $routerBase = json_encode(app(AdminEntryAliasService::class)->routerBase(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $apiBase    = json_encode(app(AdminEntryAliasService::class)->apiBasePath(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $buildStamp = (string) self::distBuildStamp();

        // 书签纠偏：webapp→weapp；旧 /weapp/{id}/{page}→/weapp/host/{id}/{page}（product 除外）
        return '<script>(function(){'
            . 'window.__PIVARK_ADMIN_ROUTER_BASE__=' . $routerBase . ';'
            . 'window.__PIVARK_ADMIN_API_BASE__=' . $apiBase . ';'
            . 'var base=(window.__PIVARK_ADMIN_ROUTER_BASE__||"/admin/").replace(/\\/+$/, "");'
            . 'var h=location.hash||"";'
            . 'if(h.indexOf("#/")===0){location.replace(base+h.slice(1));return;}'
            . 'var p=location.pathname.replace(/\\/+$/, "");'
            . 'if(p===base+"/index/index"||p===base+"/index"){location.replace(base+"/dashboard/welcome");return;}'
            . 'if(p.indexOf(base+"/webapp/")===0){'
            . 'location.replace(p.replace(base+"/webapp/",base+"/weapp/")+location.search+location.hash);return;}'
            . 'var rel=p.slice(base.length);'
            . 'var m=rel.match(/^\\/weapp\\/(?!host\\/)([a-z0-9_-]+)\\/(.+)$/);'
            . 'if(m&&m[1]!=="product"){'
            . 'location.replace(base+"/weapp/host/"+m[1]+"/"+m[2]+location.search+location.hash);return;}'
            . '})();</script>'
            . self::chunkLoadRecoveryScript($buildStamp);
    }

    /** dist/index.html 变更时间，用于识别 rebuild 后浏览器仍缓存旧 chunk */
    private static function distBuildStamp(): int
    {
        $path = self::indexPath();
        if (!is_file($path)) {
            return 0;
        }

        return (int) filemtime($path);
    }

    private static function chunkLoadRecoveryScript(string $buildStamp): string
    {
        $build = json_encode($buildStamp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<script>(function(){'
            . 'var BUILD=' . $build . ','
            . 'BUILD_KEY="pv-admin-build",'
            . 'RELOAD_KEY="pv-admin-chunk-reload",'
            . 'SYNC_KEY="pv-admin-build-sync";'
            . 'var last=sessionStorage.getItem(BUILD_KEY);'
            . 'if(BUILD>0&&last&&last!==String(BUILD)&&!sessionStorage.getItem(SYNC_KEY)){'
            . 'sessionStorage.setItem(SYNC_KEY,"1");'
            . 'sessionStorage.removeItem(RELOAD_KEY);'
            . 'var u=new URL(location.href);u.searchParams.set("_pv",Date.now());location.replace(u.toString());return;'
            . '}'
            . 'sessionStorage.removeItem(SYNC_KEY);'
            . 'if(BUILD>0){sessionStorage.setItem(BUILD_KEY,String(BUILD));}'
            . 'function isChunkErr(m){'
            . 'm=String(m||"");'
            . 'return m.indexOf("Failed to fetch dynamically imported module")>=0'
            . '||m.indexOf("Failed to load module script")>=0'
            . '||m.indexOf("Importing a module script failed")>=0'
            . '||m.indexOf("Loading chunk")>=0'
            . '||m.indexOf("Loading CSS chunk")>=0;'
            . '}'
            . 'function reloadOnce(){'
            . 'var n=Number(sessionStorage.getItem(RELOAD_KEY)||"0");'
            . 'if(n>=3){return;}'
            . 'sessionStorage.setItem(RELOAD_KEY,String(n+1));'
            . 'var u=new URL(location.href);u.searchParams.set("_pv",Date.now());location.replace(u.toString());'
            . '}'
            . 'window.addEventListener("error",function(e){'
            . 'var t=e.target;'
            . 'if(t&&t.tagName==="SCRIPT"&&t.src&&t.src.indexOf("/static/admin/dist/")>=0){reloadOnce();return;}'
            . 'if(isChunkErr(e.message)){reloadOnce();}'
            . '},true);'
            . 'window.addEventListener("unhandledrejection",function(e){'
            . 'var r=e.reason,m=r&&r.message?r.message:String(r||"");'
            . 'if(isChunkErr(m)){e.preventDefault();reloadOnce();}'
            . '});'
            . 'window.addEventListener("vite:preloadError",function(e){e.preventDefault();reloadOnce();});'
            . '})();</script>';
    }
}
