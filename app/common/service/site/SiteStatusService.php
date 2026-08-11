<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\support\AppTime;

use app\common\model\FloatContactItem;

use app\common\service\config\ConfigService;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;

/** 前台关站：后台 /admin、/api 仍可访问 */
final class SiteStatusService
{

    public function __construct(
        private readonly ConfigService $config,
        private readonly AdminEntryAliasService $adminEntry,
    ) {
    }

    public const KEY = 'site_status';

    /** public/.site_closed 存在时 .htaccess 将 .html 改走 index.php，避免静态页绕过关站 */
    public const CLOSED_FLAG_BASENAME = '.site_closed';

    /** 前台是否对外开放 */
    public function isFrontOpen(): bool
    {
        return (string) $this->config->get(self::KEY, '1') !== '0';
    }

    /** 当前 URI 是否应跳过关站页（后台、API、安装） */
    public function shouldBypassClose(string $uri): bool
    {
        if ($uri === '' || $uri === '/') {
            return false;
        }
        if (str_starts_with($uri, '/admin')) {
            return true;
        }
        $adminBase = $this->adminEntry->publicBasePath();
        if ($adminBase !== '/admin' && str_starts_with($uri, $adminBase)) {
            return true;
        }
        if (str_starts_with($uri, '/api')) {
            return true;
        }
        if (str_starts_with($uri, '/install')) {
            return true;
        }

        return false;
    }

    public function closedFlagPath(): string
    {
        return ProjectPaths::publicDir() . DIRECTORY_SEPARATOR . self::CLOSED_FLAG_BASENAME;
    }

    /** 与 configs.site_status 同步关站哨兵（供 Apache 在直出 public/*.html 前拦截） */
    public function syncFrontClosedFlag(?bool $open = null): void
    {
        $open ??= $this->isFrontOpen();
        $path = $this->closedFlagPath();
        if ($open) {
            LocalFile::unlinkQuiet($path, 'site_closed_flag');

            return;
        }
        if (is_file($path)) {
            return;
        }
        LocalFile::putContents($path, 'closed');
    }

    public function renderClosedResponse(): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $year = AppTime::format('Y');
        echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="utf-8"><title>站点维护中 - 元舟 PivArk</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{display:flex;justify-content:center;align-items:center;min-height:100vh;background:linear-gradient(135deg,#0f2027,#203a43,#2c5364);color:#fff;font-family:"Helvetica Neue","PingFang SC","Microsoft YaHei",sans-serif;text-align:center;padding:20px}
.wrap h1{font-size:48px;font-weight:300;margin-bottom:16px}
.wrap p{font-size:16px;color:rgba(255,255,255,0.7);line-height:1.8;max-width:400px;margin:0 auto}
.wrap .icon{font-size:64px;margin-bottom:20px}
</style>
</head>
<body>
<div class="wrap">
<div class="icon">🔧</div>
<h1>站点维护中</h1>
<p>网站正在维护升级，请稍后再来访问。<br>给您带来不便，敬请谅解。</p>
<p style="margin-top:30px;font-size:13px;color:rgba(255,255,255,0.4);">&copy; {$year} 元舟 PivArk</p>
</div>
</body>
</html>
HTML;
        exit;
    }
}
