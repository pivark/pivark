<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 前台 URL · Web 服务器重写说明（与 SiteUrlModeService / FrontUrlRuleService 对称）
 * 宝塔 Nginx 片段与装机 `_pv_baota_rewrite.conf` 同源；客户站可删 install/，故真源在本类。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\service\front\FrontUrlRuleService;
use app\common\service\tag\TagService;

class SiteUrlRewriteGuideService
{
    public function __construct(
        private readonly SiteUrlModeService $siteUrlModeService,
        private readonly FrontUrlRuleService $frontUrlRuleService,
        private readonly TagService $tagService,
    ) {
    }

    private const SAMPLE_TAG = 'xinwen';

    /**
     * @return array{
     *   enabled:int,
     *   notes:list<string>,
     *   examples:list<array{label:string,path:string}>,
     *   nginx:string,
     *   apache:string,
     *   iis:string,
     *   setupByServer:array<string, array{title:string,hint:string,steps:list<string>}>
     * }
     */
    public function build(?string $sampleTag = null): array
    {
        $tag = $this->resolveSampleTag($sampleTag);
        $examples = $this->examplesForTag($tag);
        $docRoot = defined('ROOT_PATH') ? (string) ROOT_PATH : '';
        $setupByServer = [
            'nginx'  => self::setupGuideZh('nginx'),
            'apache' => self::setupGuideZh('apache'),
            'iis'    => self::setupGuideZh('iis'),
        ];
        $nginx = self::baotaNginxSnippet($docRoot);
        $apache = self::apacheRootHtaccessBody();
        $iis = self::iisWebConfigBody();

        if (!$this->siteUrlModeService->usesPrettyUrl()) {
            return [
                'enabled'       => 0,
                'notes'         => [
                    '当前为动态 URL：链接形态为 /index.php/栏目、分页 ?page=（ThinkPHP PATHINFO）。',
                    '不依赖 Web 服务器伪静态；只要能访问根目录 index.php 即可打开。',
                    '要干净短链又不生成物理 HTML 时，改选「伪静态化」并按页内规则配置服务器；要实体文件则选「静态页面」并到 HTML 生成。',
                ],
                'examples'      => $examples,
                'nginx'         => $nginx,
                'apache'        => $apache,
                'iis'           => $iis,
                'setupByServer' => $setupByServer,
            ];
        }

        $tagRule = $this->siteUrlModeService->tagPageRule();
        $notes   = [
            '先在本页保存伪静态 / 静态规则，再按所选服务器把下方规则粘贴或落盘。',
            'Web 服务器须把「不存在的物理文件」转给 index.php，并保留 ?page= 等查询参数（QSA）。',
            '栏目第 2 页等路径由 PHP 路由解析（app/route/app.php 已注册 :path/:page）。',
        ];
        if ($tagRule === SiteUrlModeService::TAG_PAGE_PATH) {
            $notes[] = '栏目分页为路径式：须能访问「栏目目录/页码」两级路径，例如 ' . ($examples[1]['path'] ?? '');
        } elseif ($tagRule === SiteUrlModeService::TAG_PAGE_LIST) {
            $notes[] = '栏目分页为列表式：路径中含 list_页码，例如 ' . ($examples[1]['path'] ?? '');
        } else {
            $notes[] = '栏目分页为参数式：第 1 页为栏目首页，第 2 页起在 URL 后加 ?page=N（须开启 QSA）。';
        }

        return [
            'enabled'       => 1,
            'notes'         => $notes,
            'examples'      => $examples,
            'nginx'         => $nginx,
            'apache'        => $apache,
            'iis'           => $iis,
            'setupByServer' => $setupByServer,
        ];
    }

    /**
     * 装机向导 / 后台 URL 配置共用：按服务器类型的手工设置说明。
     *
     * @return array{title:string,hint:string,steps:list<string>}
     */
    public static function setupGuideZh(string $type): array
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'apache' => [
                'title' => 'Apache：.htaccess（一般不用手粘）',
                'hint'  => '文档根须指向发行包根（含 index.php，不是只指 public/）。装完 finish 会自动写入站点根 .htaccess。后台用 /admin/index.php 即可进。',
                'steps' => [
                    '确认站点文档根 = 解压后的发行包根目录（能看到 index.php、admin/、public/）',
                    '确认已启用 mod_rewrite，且该目录 AllowOverride 含 All（宝塔 Apache 默认通常已开）',
                    '装完后一般不必再粘贴；若根 .htaccess 被面板覆盖，复制下方全文覆盖站点根 .htaccess',
                    '后台入口：/admin/index.php/auth/login（物理入口，不依赖伪静态美化）',
                ],
            ],
            'iis' => [
                'title' => 'IIS：web.config（须 URL Rewrite）',
                'hint'  => '文档根须指向发行包根。先安装「IIS URL Rewrite」模块，再把下方 web.config 放到站点根。后台用 /admin/index.php 即可进。',
                'steps' => [
                    '确认站点物理路径 = 解压后的发行包根目录（含 index.php）',
                    '在服务器安装 Microsoft IIS URL Rewrite（未装则规则不生效）',
                    '复制下方全文保存为站点根 web.config（与 index.php 同级）',
                    '在 IIS 管理器对站点执行「重新启动」；后台打开 /admin/index.php/auth/login',
                ],
            ],
            default => [
                'title' => 'Nginx / 宝塔：伪静态（手工粘贴）',
                'hint'  => '文档根须指向发行包根（含 index.php）。下方是宝塔「伪静态」短片段，不是完整 server{}。后台走 /admin/index.php；粘贴后前台短链与 /uploads 映射更完整。',
                'steps' => [
                    '本页若改选了伪静态/静态，先点「确认提交」保存 URL 规则',
                    '打开宝塔：网站 → 对应站点 → 设置 → 左侧「伪静态」',
                    '清空编辑框 → 复制下方全文粘贴 → 保存（下拉没有 PivArk 预设属正常）',
                    '也可对照站点根 _pv_baota_rewrite.conf（装完会生成，与这里同源）',
                ],
            ],
        };
    }

    /** 宝塔伪静态短片段（与装机 `_pv_baota_rewrite.conf` 同源） */
    public static function baotaNginxSnippet(string $docRoot = ''): string
    {
        $root = str_replace('\\', '/', rtrim($docRoot, '/\\'));
        if ($root !== '') {
            $root .= '/';
        }
        $uploadsAlias = $root !== '' ? $root . 'public/uploads/' : 'public/uploads/';
        $staticAlias  = $root !== '' ? $root . 'public/static/' : 'public/static/';
        $memberAlias  = $root !== '' ? $root . 'template/member/' : 'template/member/';
        $htmlAlias    = $root !== '' ? $root . 'public/html/' : 'public/html/';

        return <<<NGINX
# PivArk — 宝塔伪静态（站点根 = 发行包根，含 index.php 与 public/）
# 后台：不粘贴也能进 /admin/index.php；本段可选（前台短链/uploads）。粘贴后短链 /admin 也通
# 怎么用：网站 → 设置 → 伪静态 → 粘贴保存；装完站点根 `_pv_baota_rewrite.conf` 同源
# 勿在站点根建 member/ 目录或软链（会抢 /member 路由）；会员 CSS 走下面 /static/theme/member/

# 让 PHP 自己的 404 页原样出来（关掉面板默认「用 nginx 壳页盖业务 404」）
fastcgi_intercept_errors off;

location ~ ^/(runtime|data|devtools|bootstrap)(/|\$) { deny all; }
location ~ ^/(composer\\.(json|lock)|package(-lock)?\\.json)\$ { deny all; }
# 禁根目录发行 zip；禁 _pv_*（含探针脚本与 baota conf，防公网下配置）
location ~* ^/(pivark-.*\\.zip|_pv_.*)\$ { deny all; }
location ~* ^/uploads/.*\\.(php|phtml|php3|php4|php5|php7|php8|phps|pht|phar|shtml|inc|jsp|asp|aspx|cgi|sh|htaccess)\$ { deny all; }

location ^~ /uploads/ {
    alias {$uploadsAlias};
    access_log off;
}

location ^~ /static/theme/member/ {
    alias {$memberAlias};
    access_log off;
}

location ^~ /static/ {
    alias {$staticAlias};
    expires 30d;
    access_log off;
    # 预压缩：build 产物 .gz/.br；弱主机避免每次即时 gzip 拖死后台首屏
    gzip_static on;
    # 若面板已装 brotli 模块则生效；未装时 nginx 忽略未知指令会拒载，故用独立可选段时再开
    # brotli_static on;
}

# 静态 HTML（seo_static_subdir=html）：落盘 public/html/。优先站点根软链 html→public/html（装机/生成自愈）；本 alias 为软链失败时的面板兜底
location ^~ /html/ {
    alias {$htmlAlias};
    access_log off;
}

# 后台/安装：目录存在也进 PHP（对齐根 .htaccess）。否则 admin/ 挡 location / 的 !-e，
# 再叠加面板「去尾斜杠」会与 DirectoryIndex 301 互跳。
location = /admin {
    rewrite ^ /admin/index.php last;
}
location = /admin/ {
    rewrite ^ /admin/index.php last;
}
location ~ ^/admin/(?!index\\.php(?:/|\$))(.*)$ {
    rewrite ^/admin/(.*)\$ /admin/index.php/\$1 last;
}
location = /install {
    rewrite ^ /install/index.php last;
}
location = /install/ {
    rewrite ^ /install/index.php last;
}

# 动态栏目统一无尾斜杠（真实目录如 /install/ 不剥，对齐 Apache RewriteCond !-d）
# 若无 !-d：/install/↔/install 会 301 互跳，装向导打不开
if (!-d \$request_filename) {
    rewrite ^/(.+)/$ /$1 permanent;
}

location / {
    if (!-e \$request_filename) {
        rewrite ^(.*)\$ /index.php\$1 last;
    }
}
NGINX;
    }

    /** Apache 根 .htaccess（装完 finish 写入同源） */
    public static function apacheRootHtaccessBody(): string
    {
        return <<<'HTACCESS'
# PivArk — 安装后动态入口（默认）
# 作用：把不存在的路径交给 index.php（/admin、前台动态 URL）
# 这不是伪静态美化规则；美化后缀 / 静态 HTML 请在后台「站点 URL」切换后按说明配置服务器
# 库连接在 data/site.env，勿依赖根目录 .env。
# Apache 轨：本文件由装机 finish 自动写入，一般不必再在面板粘贴。

<IfModule mod_authz_core.c>
    <FilesMatch "^\.env">
        Require all denied
    </FilesMatch>
</IfModule>

<IfModule mod_rewrite.c>
    Options +FollowSymlinks -Multiviews
    RewriteEngine On

    # 动态栏目统一无尾斜杠（与宝塔片段一致）
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.+)/$ /$1 [R=301,L]

    # 后台 / 安装向导（目录存在时须进 ThinkPHP）
    RewriteCond %{REQUEST_URI} ^/admin(/|$) [NC]
    RewriteRule ^ index.php [L,QSA,PT]
    RewriteCond %{REQUEST_URI} ^/install(/|$) [NC]
    RewriteRule ^ index.php [L,QSA,PT]

    # 禁止 Web 访问敏感路径
    RewriteRule ^\.git(/|$) - [F,L,NC]
    RewriteRule ^(runtime|data)(/|$) - [F,L,NC]
    RewriteRule ^app/database(/|$) - [F,L,NC]
    RewriteRule ^(composer\.(json|lock)|package(-lock)?\.json)$ - [F,L,NC]
    # 与 Nginx 宝塔片段对称：禁根目录发行 zip / _pv_*（探针与 baota conf）
    RewriteRule ^pivark-.*\.zip$ - [F,L,NC]
    RewriteRule ^_pv_ - [F,L,NC]

    # 上传目录禁止脚本
    RewriteRule ^uploads/.+\.(php|phtml|php3|php4|php5|php7|php8|phps|pht|phar|shtml|inc|jsp|asp|aspx|cgi|sh|htaccess)$ - [F,L,NC]

    # 静态 HTML：首页 index.html（仅当已生成时）
    RewriteCond %{DOCUMENT_ROOT}/public/index.html -f
    RewriteRule ^$ public/index.html [L]

    # 会员主题静态：/static/theme/member/{pack}/… → template/member/{pack}/…
    RewriteCond %{REQUEST_URI} ^/static/theme/member/([a-z0-9][a-z0-9_-]{0,49})/(.+)$ [NC]
    RewriteCond %{DOCUMENT_ROOT}/template/member/%1/%2 -f
    RewriteRule ^static/theme/member/ template/member/%1/%2 [L]

    # 站点主题静态：优先 template/{id}/pc|mobile/assets，再 assets
    RewriteCond %{REQUEST_URI} ^/static/theme/([a-z0-9][a-z0-9_-]{0,49})/(.+)$ [NC]
    RewriteCond %{REQUEST_URI} !^/static/theme/member/ [NC]
    RewriteCond %{DOCUMENT_ROOT}/template/%1/pc/assets/%2 -f
    RewriteRule ^static/theme/ template/%1/pc/assets/%2 [L]
    RewriteCond %{REQUEST_URI} ^/static/theme/([a-z0-9][a-z0-9_-]{0,49})/(.+)$ [NC]
    RewriteCond %{REQUEST_URI} !^/static/theme/member/ [NC]
    RewriteCond %{DOCUMENT_ROOT}/template/%1/mobile/assets/%2 -f
    RewriteRule ^static/theme/ template/%1/mobile/assets/%2 [L]
    RewriteCond %{REQUEST_URI} ^/static/theme/([a-z0-9][a-z0-9_-]{0,49})/(.+)$ [NC]
    RewriteCond %{REQUEST_URI} !^/static/theme/member/ [NC]
    RewriteCond %{DOCUMENT_ROOT}/template/%1/assets/%2 -f
    RewriteRule ^static/theme/ template/%1/assets/%2 [L]

    # 关站时静态 .html 改走 index.php
    RewriteCond %{DOCUMENT_ROOT}/public/.site_closed -f
    RewriteCond %{DOCUMENT_ROOT}/public%{REQUEST_URI} -f
    RewriteCond %{REQUEST_URI} \.html$ [NC]
    RewriteRule ^ index.php [L,QSA,PT]

    # public 目录默认 index.html
    RewriteCond %{REQUEST_URI} !\.[a-zA-Z0-9]{2,5}$ [NC]
    RewriteCond %{REQUEST_URI} !/index\.html$ [NC]
    RewriteCond %{DOCUMENT_ROOT}/public/$1/index.html -f
    RewriteRule ^(.+?)/?$ public/$1/index.html [L]

    # 静态文件在 public/ 优先
    RewriteCond %{DOCUMENT_ROOT}/public%{REQUEST_URI} -f
    RewriteRule ^(.*)$ public/$1 [L]

    # ThinkPHP 动态路由（须带 QSA）
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^(.*)$ index.php?/$1 [QSA,PT,L]
</IfModule>
HTACCESS;
    }

    public static function iisWebConfigBody(): string
    {
        return <<<'IIS'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <security>
            <requestFiltering>
                <hiddenSegments>
                    <add segment=".git" />
                    <add segment="runtime" />
                    <add segment="data" />
                </hiddenSegments>
                <fileExtensions allowUnlisted="true">
                    <add fileExtension=".env" allowed="false" />
                </fileExtensions>
            </requestFiltering>
        </security>
        <rewrite>
            <rules>
                <rule name="PivArk ThinkPHP" stopProcessing="true">
                    <match url="^(.*)$" ignoreCase="false" />
                    <conditions>
                        <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
                    </conditions>
                    <action type="Rewrite" url="index.php?/{R:1}" />
                </rule>
            </rules>
        </rewrite>
        <defaultDocument>
            <files>
                <add value="index.php" />
                <add value="index.html" />
            </files>
        </defaultDocument>
    </system.webServer>
</configuration>
IIS;
    }

    /**
     * @return list<array{label:string,path:string}>
     */
    private function examplesForTag(string $tag): array
    {
        $home = $this->frontUrlRuleService->buildChannelHome($tag, 1);
        $p2   = $this->frontUrlRuleService->buildTagListPage($tag, 2);

        return [
            ['label' => '栏目首页', 'path' => $home],
            ['label' => '栏目第 2 页', 'path' => $p2],
            ['label' => '路由入口', 'path' => 'index.php → Front@path / Front@pathPaged'],
        ];
    }

    private function resolveSampleTag(?string $sampleTag): string
    {
        $sampleTag = trim((string) $sampleTag);
        if ($sampleTag !== '') {
            return $sampleTag;
        }
        foreach ($this->tagService->listAllActive() as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug !== '') {
                return $this->tagService->publicPath($row);
            }
        }

        return self::SAMPLE_TAG;
    }
}
