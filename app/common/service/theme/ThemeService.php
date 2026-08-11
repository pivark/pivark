<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\theme;

use think\facade\Request;
use app\common\service\config\ConfigService;
use app\common\support\ClientViewport;
/** 前台主题扫描与路径 */
class ThemeService
{

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    private const THEME_DIR = 'template';

    private const MEMBER_THEME_DIR = 'template/member';

    private static ?string $currentThemeCache = null;

    /** @var array<string, array{mtime:int, meta:array<string, mixed>}> */
    private static array $readMetaCache = [];

    /** @return list<array{id:string,title:string,version:string,description:string,author:string,preview:string}> */
    public function listThemes(): array
    {
        $root = ROOT_PATH . self::THEME_DIR . DIRECTORY_SEPARATOR;
        if (!is_dir($root)) {
            return [];
        }

        $themes = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_dir($root . $name)) {
                continue;
            }
            if (!$this->isValidThemeId($name)) {
                continue;
            }
            if (!$this->themeHasTemplates($name)) {
                continue;
            }
            $meta = $this->readMeta($name);
            $themes[] = [
                'id'          => $name,
                'title'       => (string) ($meta['title'] ?? $name),
                'version'     => (string) ($meta['version'] ?? '1.0.0'),
                'description' => (string) ($meta['description'] ?? ''),
                'author'      => (string) ($meta['author'] ?? 'PivArk'),
                'preview'     => $this->resolveThemePreviewUrl($name, (string) ($meta['preview'] ?? '')),
            ];
        }

        usort($themes, static fn ($a, $b) => strcmp($a['id'], $b['id']));
        return $themes;
    }

    /**
     * @return string 当前有效主题 ID
     */
    public function getCurrentTheme(): string
    {
        if (self::$currentThemeCache !== null) {
            return self::$currentThemeCache;
        }
        $theme = (string) $this->config->get('site_theme', 'default');
        self::$currentThemeCache = $this->validateTheme($theme);
        return self::$currentThemeCache;
    }

    /**
     * @return mixed
     */
    public static function forgetCurrentThemeCache(): void
    {
        self::$currentThemeCache = null;
    }

    /**
     * @param string $theme 主题 ID
     * @return string 合法主题 ID
     */
    public function validateTheme(string $theme): string
    {
        $theme = trim($theme);
        if ($theme === '' || !$this->isValidThemeId($theme) || !$this->themeHasTemplates($theme)) {
            return $this->defaultThemeId();
        }
        return $theme;
    }

    /**
     * @return string 默认主题 ID
     */
    public function defaultThemeId(): string
    {
        if ($this->themeHasTemplates('default')) {
            return 'default';
        }
        $root = ROOT_PATH . self::THEME_DIR . DIRECTORY_SEPARATOR;
        if (!is_dir($root)) {
            return 'default';
        }
        $candidates = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_dir($root . $name)) {
                continue;
            }
            if (!$this->isValidThemeId($name) || !$this->themeHasTemplates($name)) {
                continue;
            }
            $candidates[] = $name;
        }
        sort($candidates, SORT_STRING);

        return $candidates[0] ?? 'default';
    }

    /** 门户/会员主题目录名（与 .htaccess、ThemeStaticAsset 静态路由一致） */
    public const THEME_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,49}$/';

    /**
     * @param string $id 主题 ID
     * @return bool
     */
    public function isValidThemeId(string $id): bool
    {
        return (bool) preg_match(self::THEME_ID_PATTERN, $id);
    }

    /**
     * template/ 下存在但未出现在前台模板列表的目录（标识非法或缺少 PHP 骨架）。
     *
     * @return list<array{dir:string, reason:string}>
     */
    public function listThemeScanWarnings(): array
    {
        $root = ROOT_PATH . self::THEME_DIR . DIRECTORY_SEPARATOR;
        if (!is_dir($root)) {
            return [];
        }

        $listed = array_column($this->listThemes(), 'id');
        $warnings = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_dir($root . $name)) {
                continue;
            }
            if (in_array($name, $listed, true)) {
                continue;
            }
            if (!$this->isValidThemeId($name)) {
                $warnings[] = [
                    'dir'    => $name,
                    'reason' => '目录名不符合主题标识规则（须小写字母或数字开头，仅含 a-z、0-9、_、-，最长 50 字符）',
                ];
                continue;
            }
            if (!$this->themeHasTemplates($name)) {
                $warnings[] = [
                    'dir'    => $name,
                    'reason' => '目录内缺少可用 PHP 模板（至少需在主题根或 pc/ 下有 home.php、document_list.php 等）',
                ];
            }
        }

        usort($warnings, static fn (array $a, array $b): int => strcmp($a['dir'], $b['dir']));

        return $warnings;
    }

    /**
     * @param string $theme 主题 ID
     * @return bool
     */
    public function themeHasTemplates(string $theme): bool
    {
        if (!$this->isValidThemeId($theme)) {
            return false;
        }
        $dir = ROOT_PATH . self::THEME_DIR . '/' . $theme;
        if (!is_dir($dir)) {
            return false;
        }
        foreach ([ClientViewport::PC, ClientViewport::M, ''] as $sub) {
            $scan = $sub === '' ? $dir : $dir . '/' . $sub;
            if (!is_dir($scan)) {
                continue;
            }
            foreach (glob($scan . '/*.php') ?: [] as $file) {
                if (is_file($file) && !str_starts_with(basename($file), '_')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param string $theme 主题 ID
     * @return array<string, mixed>
     */
    public function readMeta(string $theme): array
    {
        $file = ROOT_PATH . self::THEME_DIR . '/' . $theme . '/theme.json';
        if (!is_file($file)) {
            return [];
        }
        $mtime = (int) filemtime($file);
        if (isset(self::$readMetaCache[$theme]) && self::$readMetaCache[$theme]['mtime'] === $mtime) {
            return self::$readMetaCache[$theme]['meta'];
        }
        $json = json_decode((string) file_get_contents($file), true);
        $meta = is_array($json) ? $json : [];
        self::$readMetaCache[$theme] = ['mtime' => $mtime, 'meta' => $meta];

        return $meta;
    }

    /**
     * theme.json skin 块（class_prefix 等）
     *
     * @return array<string, mixed>
     */
    public function themeSkin(?string $theme = null): array
    {
        $meta = $this->readMeta($theme ?? $this->getCurrentTheme());
        $skin = $meta['skin'] ?? [];

        return is_array($skin) ? $skin : [];
    }

    /** 主题 CSS 类前缀，如 st-；无 skin 时返回空字符串 */
    public function themeClassPrefix(?string $theme = null): string
    {
        $raw = trim((string) ($this->themeSkin($theme)['class_prefix'] ?? ''));
        if ($raw === '') {
            return '';
        }

        return str_ends_with($raw, '-') ? $raw : $raw . '-';
    }

    /**
     * 模板目录元数据（theme.json templates · 优先于文件头 pv:template 注释）
     *
     * @return array{label:string, hint:string, scope:string}
     */
    public function readTemplateCatalogMeta(string $theme, string $filename): array
    {
        $meta    = $this->readMeta($theme);
        $catalog = $meta['templates'] ?? null;
        if (!is_array($catalog)) {
            return ['label' => '', 'hint' => '', 'scope' => ''];
        }

        $basename = basename(str_replace('\\', '/', $filename));
        $entry    = $catalog[$basename] ?? null;
        if (!is_array($entry)) {
            return ['label' => '', 'hint' => '', 'scope' => ''];
        }

        return [
            'label' => trim((string) ($entry['label'] ?? '')),
            'hint'  => trim((string) ($entry['hint'] ?? '')),
            'scope' => trim((string) ($entry['scope'] ?? '')),
        ];
    }

    /**
     * @param string $theme    主题 ID
     * @param string $relative 相对路径
     * @return string 绝对路径
     */
    public function themePath(string $theme, string $relative = ''): string
    {
        $theme = $this->validateTheme($theme);
        $path  = ROOT_PATH . self::THEME_DIR . '/' . $theme;
        if ($relative !== '') {
            $norm = $this->normalizeAssetRelative(str_replace('\\', '/', $relative));
            if ($norm === '') {
                return $path . DIRECTORY_SEPARATOR . '.invalid-relative';
            }
            $path .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $norm);
        }
        return $path;
    }

    /** 站点模板扫描目录（优先 pc/m 视口子目录） */
    public function siteTemplateScanDir(string $theme): string
    {
        $theme = $this->validateTheme($theme);
        foreach ($this->viewportSubdirs() as $sub) {
            $dir = $this->themePath($theme, $sub);
            if (is_dir($dir) && $this->dirHasPhpTemplates($dir)) {
                return $dir;
            }
        }

        return $this->themePath($theme);
    }

    public function resolveSiteTemplatePath(string $theme, string $relative): string
    {
        if (!$this->isValidThemeId($theme)) {
            return '';
        }
        $norm = $this->normalizeAssetRelative(str_replace('\\', '/', $relative));
        if ($norm === '') {
            return '';
        }

        $base = ROOT_PATH . self::THEME_DIR . '/' . $theme;
        foreach ($this->viewportRelativeCandidates($norm) as $candidate) {
            $path = $base . '/' . $candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    public function siteTemplateExists(string $theme, string $relative): bool
    {
        return $this->resolveSiteTemplatePath($theme, $relative) !== '';
    }

    /** 当前主题链内是否「原生」存在模板（不含仅 www 回退的www 主题专页） */
    public function siteTemplateOwnedByTheme(string $relative, ?string $theme = null): bool
    {
        $theme = $this->validateTheme($theme ?? $this->getCurrentTheme());
        if ($this->resolveSiteTemplatePath($theme, $relative) !== '') {
            return true;
        }

        return $theme !== 'default'
            && $this->resolveSiteTemplatePath('default', $relative) !== '';
    }

    /**
     * 模板解析链：优先读 theme.json `template_search`，否则 current → default → www。
     *
     * @return list<string>
     */
    public function siteTemplateSearchThemes(?string $theme = null): array
    {
        $current = $this->validateTheme($theme ?? $this->getCurrentTheme());
        $chain   = $this->readMeta($current)['template_search'] ?? null;
        if (is_array($chain) && $chain !== []) {
            $out = [];
            foreach ($chain as $id) {
                if (!is_string($id)) {
                    continue;
                }
                $id = trim($id);
                if ($id === '' || !$this->isValidThemeId($id)) {
                    continue;
                }
                if (!in_array($id, $out, true)) {
                    $out[] = $id;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }

        return array_values(array_unique([$current, 'default', 'www']));
    }

    public function resolveSiteTemplatePathWithFallback(string $relative, ?string $theme = null): string
    {
        foreach ($this->siteTemplateSearchThemes($theme) as $themeId) {
            $path = $this->resolveSiteTemplatePath($themeId, $relative);
            if ($path !== '') {
                return $path;
            }
        }

        return '';
    }

    public function siteTemplateExistsWithFallback(string $relative, ?string $theme = null): bool
    {
        return $this->resolveSiteTemplatePathWithFallback($relative, $theme) !== '';
    }

    public function themeAssetUrlPrefix(string $theme): string
    {
        $theme = $this->validateTheme($theme);

        // 对外 URL 固定 /static/theme/{id}/…；物理文件由 resolveAssetAbsolutePath / tryServeHttpAsset 解析
        return '/static/theme/' . $theme;
    }

    /** @return list<array{id:string,title:string,version:string,description:string,author:string}> */
    public function listMemberThemes(): array
    {
        $root = ROOT_PATH . self::MEMBER_THEME_DIR . DIRECTORY_SEPARATOR;
        if (!is_dir($root)) {
            return [];
        }

        $themes = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_dir($root . $name)) {
                continue;
            }
            if (!$this->isValidThemeId($name)) {
                continue;
            }
            $meta = $this->readMemberMeta($name);
            $themes[] = [
                'id'          => $name,
                'title'       => (string) ($meta['title'] ?? $name),
                'version'     => (string) ($meta['version'] ?? '1.0.0'),
                'description' => (string) ($meta['description'] ?? ''),
                'author'      => (string) ($meta['author'] ?? 'PivArk'),
            ];
        }

        usort($themes, static fn ($a, $b) => strcmp($a['id'], $b['id']));

        return $themes;
    }

    public function getCurrentMemberTheme(): string
    {
        return $this->validateMemberTheme((string) $this->config->get('member_theme', 'default'));
    }

    public function validateMemberTheme(string $pack): string
    {
        $pack = trim($pack);
        if ($pack === '' || !$this->isValidThemeId($pack)) {
            return 'default';
        }
        if (!is_dir(ROOT_PATH . self::MEMBER_THEME_DIR . '/' . $pack)) {
            return 'default';
        }

        return $pack;
    }

    public function memberAssetUrlPrefix(string $pack): string
    {
        $pack = $this->validateMemberTheme($pack);
        foreach ($this->viewportSubdirs() as $sub) {
            if (is_dir(ROOT_PATH . self::MEMBER_THEME_DIR . '/' . $pack . '/' . $sub . '/assets')) {
                return '/static/theme/member/' . $pack . '/' . $sub . '/assets';
            }
        }

        return '/static/theme/member/' . $pack . '/assets';
    }

    /** @return array{member_theme: string, member_asset: string, member_asset_ver: string} */
    public function memberTemplateAssetVars(?string $pack = null): array
    {
        $pack = $this->validateMemberTheme($pack ?? $this->getCurrentMemberTheme());

        return [
            'member_theme'     => $pack,
            'member_asset'     => $this->memberAssetUrlPrefix($pack),
            'member_asset_ver' => $this->memberAssetVersion($pack),
        ];
    }

    /**
     * 会员包静态资源源目录（优先 pc/m 视口 assets）
     *
     * @return list<string>
     */
    public function memberAssetSourceDirs(string $pack): array
    {
        $pack = $this->validateMemberTheme($pack);
        $dirs   = [];
        foreach ($this->viewportSubdirs() as $sub) {
            $dirs[] = ROOT_PATH . self::MEMBER_THEME_DIR . '/' . $pack . '/' . $sub . '/assets';
        }
        $dirs[] = ROOT_PATH . self::MEMBER_THEME_DIR . '/' . $pack . '/assets';

        return $dirs;
    }

    public function memberAssetVersion(string $pack): string
    {
        $pack   = $this->validateMemberTheme($pack);
        $latest = 0;
        foreach ($this->memberAssetSourceDirs($pack) as $assetsDir) {
            if (!is_dir($assetsDir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($assetsDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                    continue;
                }
                $latest = max($latest, (int) $item->getMTime());
            }
        }
        if ($latest > 0) {
            return (string) $latest;
        }

        return (string) ($this->readMemberMeta($pack)['version'] ?? '1');
    }

    public function resolveMemberAssetAbsolutePath(string $pack, string $relative): ?string
    {
        $pack     = $this->validateMemberTheme($pack);
        $relative = $this->normalizeAssetRelative($relative);
        if ($relative === '') {
            return null;
        }

        $roots = [];
        foreach ($this->viewportSubdirs() as $sub) {
            $roots[] = ROOT_PATH . self::MEMBER_THEME_DIR . '/' . $pack . '/' . $sub . '/assets';
        }
        $roots[] = ROOT_PATH . self::MEMBER_THEME_DIR . '/' . $pack . '/assets';
        $roots[] = ROOT_PATH . 'public/static/theme/member/' . $pack;

        return $this->firstExistingAsset($roots, $relative);
    }

    public function resolveMemberTemplatePath(string $logicalTemplate): string
    {
        $logical = trim(str_replace('\\', '/', $logicalTemplate), '/');
        if (str_starts_with($logical, 'member/')) {
            $logical = substr($logical, 7);
        }
        if ($logical === '') {
            return '';
        }

        return $this->resolveMemberRelativePath($this->getCurrentMemberTheme(), $logical . '.php');
    }

    public function resolveMemberIncludePath(string $file): string
    {
        $file = trim(str_replace('\\', '/', $file), '/');
        if (str_starts_with($file, 'member/')) {
            $file = substr($file, 7);
        }
        if ($file === '') {
            return '';
        }

        return $this->resolveMemberRelativePath($this->getCurrentMemberTheme(), $file . '.php');
    }

    /**
     * 主题静态资源相对路径（URL 中 /static/theme/{id}/ 之后部分）
     */
    public function normalizeAssetRelative(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        $relative = ltrim($relative, '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return '';
        }

        return $relative;
    }

    /**
     * 公开 URL `/static/theme/{主题}/相对路径`：路径里主题下文件不存在、当前主题有同相对路径时，改写到当前主题。
     * 换模板不改库内路径时避免裂图（上传路径 /uploads 等不匹配则原样返回）。
     */
    public function remapPublicThemeStaticUrl(string $path): string
    {
        $path = trim($path);
        if (preg_match('#^/static/theme/([^/]+)/(.+)$#', $path, $matches) !== 1) {
            return $path;
        }

        $themeInUrl = (string) $matches[1];
        $relative   = (string) $matches[2];
        if ($this->resolveAssetAbsolutePath($themeInUrl, $relative) !== null) {
            return $path;
        }

        $current = $this->getCurrentTheme();
        if ($current !== $themeInUrl && $this->resolveAssetAbsolutePath($current, $relative) !== null) {
            return '/static/theme/' . $current . '/' . $relative;
        }

        return $path;
    }

    /**
     * 解析主题静态文件绝对路径：优先 template/{theme}/assets/，回退 public/static/theme/{theme}/。
     * 未命中时按模板链回退（www / default），与www 主题专页模板回退同口径。
     */
    public function resolveAssetAbsolutePath(string $theme, string $relative): ?string
    {
        if (!$this->isValidThemeId($theme)) {
            return null;
        }
        $relative = $this->normalizeAssetRelative($relative);
        if ($relative === '') {
            return null;
        }

        foreach ($this->siteTemplateSearchThemes($theme) as $themeId) {
            $file = $this->resolveAssetAbsolutePathOwned($themeId, $relative);
            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    /** 仅当前主题目录内解析（不含跨主题回退） */
    private function resolveAssetAbsolutePathOwned(string $theme, string $relative): ?string
    {
        $roots = [
            $this->themePath($theme, ClientViewport::current() . '/assets'),
            $this->themePath($theme, ClientViewport::PC . '/assets'),
            $this->themePath($theme, 'assets'),
            ROOT_PATH . 'public/static/theme/' . $theme,
        ];
        foreach ($roots as $root) {
            $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($candidate)) {
                continue;
            }
            $realFile = realpath($candidate);
            $realRoot = realpath($root);
            if ($realFile === false || $realRoot === false) {
                continue;
            }
            $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($realFile, $prefix)) {
                return $realFile;
            }
        }

        return null;
    }

    /**
     * 静态资源 cache bust：取 assets 目录最新 mtime；无 assets 时用 theme.json version
     */
    public function assetVersion(string $theme): string
    {
        $theme = $this->validateTheme($theme);
        $latest  = 0;
        foreach ($this->themeAssetSourceDirs($theme) as $assetsDir) {
            if (!is_dir($assetsDir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($assetsDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                    continue;
                }
                $latest = max($latest, (int) $item->getMTime());
            }
        }
        // 一并计已发布静态（public/static/theme/{id}/css），避免源码已改但页缓存/?v= 仍停在旧号
        $publishedCssDir = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR
            . 'theme' . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR . 'css';
        if (is_dir($publishedCssDir)) {
            foreach (['style.css', 'home.css', 'inner.css', 'catalog.css', 'demo.css'] as $cssName) {
                $publishedCss = $publishedCssDir . DIRECTORY_SEPARATOR . $cssName;
                if (is_file($publishedCss)) {
                    $latest = max($latest, (int) filemtime($publishedCss));
                }
            }
        }
        if ($latest > 0) {
            return (string) $latest;
        }

        return (string) ($this->readMeta($theme)['version'] ?? '1');
    }

    /**
     * 主题静态资源源目录（优先 pc/m 视口 assets）
     *
     * @return list<string> 绝对路径，按优先级
     */
    public function themeAssetSourceDirs(string $theme): array
    {
        $theme = $this->validateTheme($theme);
        $dirs  = [];
        foreach ($this->viewportSubdirs() as $sub) {
            $dirs[] = $this->themePath($theme, $sub . '/assets');
        }
        $dirs[] = $this->themePath($theme, 'assets');

        return $dirs;
    }

    /**
     * 直出 /static/theme/{theme}/…（Nginx 未配置 alias 时由 index.php 兜底）
     */
    public function tryServeHttpAsset(string $uri): bool
    {
        return \app\common\support\ThemeStaticAsset::tryServeHttpAsset($uri);
    }

    private function guessMimeType(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $map = [
            'css'  => 'text/css; charset=utf-8',
            'js'   => 'application/javascript; charset=utf-8',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'ico'  => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
            'ttf'  => 'font/ttf',
            'map'  => 'application/json; charset=utf-8',
        ];

        return $map[$ext] ?? 'application/octet-stream';
    }

    private function emitStaticFile(string $file): void
    {
        $size  = filesize($file) ?: 0;
        $mtime = filemtime($file) ?: time();
        $etag  = '"' . dechex($mtime) . '-' . dechex((int) $size) . '"';
        $last  = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        header('Content-Type: ' . $this->guessMimeType($file));
        header('Content-Length: ' . (string) $size);
        header('Last-Modified: ' . $last);
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=31536000, immutable');

        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            http_response_code(304);
            return;
        }

        $ifModifiedSince = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if ($ifModifiedSince !== '' && strtotime($ifModifiedSince) !== false && strtotime($ifModifiedSince) >= $mtime) {
            http_response_code(304);
            return;
        }

        readfile($file);
    }

    /**
     * 可选：将 template/{theme}/assets/ 复制到 public/static/theme/{theme}/（兼容旧部署/Nginx 直出 public）。
     * 会员资源真源在 template/member/{pack}/…，禁止写入站点 theme 副本。
     *
     * @return list<string> 已写入的相对路径
     */
    public function publishAssets(string $theme): array
    {
        $theme = $this->validateTheme($theme);
        $src   = '';
        foreach ($this->themeAssetSourceDirs($theme) as $dir) {
            if (is_dir($dir)) {
                $src = $dir;
                break;
            }
        }
        $dst = ROOT_PATH . 'public/static/theme/' . $theme;
        if ($src === '') {
            $this->purgeMemberOwnedSiteThemeCopies($theme, $dst);

            return [];
        }

        $written = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $rel = substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $src)) + 1);
            if ($rel === false || $rel === '') {
                continue;
            }
            $target = $dst . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new \RuntimeException('无法创建目录：' . $target);
                }
                continue;
            }
            if (preg_match('#^js/(doc-|shop-mall|pv-vod-player|hls\.min)#', $rel) === 1) {
                continue;
            }
            if (preg_match('#^css/pv-vod\.css$#', $rel) === 1) {
                continue;
            }
            // 会员中心 assets 只留 template/member；不写入站点 public/static/theme/{site}/
            if ($this->isMemberOwnedSiteThemeRel($rel) || $this->isStaleSiteThemeRel($theme, $rel)) {
                continue;
            }
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('无法创建目录：' . $dir);
            }
            if (!copy($item->getPathname(), $target)) {
                throw new \RuntimeException('无法复制：' . $item->getPathname());
            }
            $written[] = $rel;
        }

        $this->purgeMemberOwnedSiteThemeCopies($theme, $dst);

        return $written;
    }

    /** 站点 theme 副本里禁止残留的会员资源相对路径 */
    private function isMemberOwnedSiteThemeRel(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');

        return (bool) preg_match(
            '#^(css/member-center\.css|js/member\.js|js/member-center\.js)$#',
            $rel
        );
    }

    /**
     * default/demo 已收口到 demo.css；www 仍用 style.css。
     * 插件/已退役脚本禁止再落站点 theme 副本。
     */
    private function isStaleSiteThemeRel(string $theme, string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (preg_match('#^(css/pv-vod\.css|js/main\.js|js/hls\.min\.js|js/doc-(download|favorite|shop)-.*\.js|js/shop-mall\.js)$#', $rel) === 1) {
            return true;
        }
        // default/demo：style.css 为历史脏副本（真源 demo.css）
        if (($theme === 'default' || $theme === 'demo') && $rel === 'css/style.css') {
            return true;
        }

        return false;
    }

    private function purgeMemberOwnedSiteThemeCopies(string $theme, string $dstThemeDir): void
    {
        if ($dstThemeDir === '' || !is_dir($dstThemeDir)) {
            return;
        }
        $rels = [
            'css/member-center.css',
            'js/member.js',
            'js/member-center.js',
            'css/pv-vod.css',
            'js/main.js',
            'js/hls.min.js',
            'js/doc-download-core.js',
            'js/doc-download-paid.js',
            'js/doc-download-points.js',
            'js/doc-favorite.js',
            'js/doc-shop-checkout.js',
            'js/shop-mall.js',
        ];
        if ($theme === 'default' || $theme === 'demo') {
            $rels[] = 'css/style.css';
        }
        foreach ($rels as $rel) {
            $path = $dstThemeDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function resolveThemePreviewUrl(string $theme, string $preview): string
    {
        $preview = trim($preview);
        if ($preview === '') {
            return '';
        }
        if (preg_match('#^/static/theme/([^/]+)/(.+)$#', $preview, $matches) === 1) {
            return $this->resolveAssetAbsolutePath($matches[1], $matches[2]) !== null ? $preview : '';
        }
        if (!str_starts_with($preview, '/') && !preg_match('#^https?://#i', $preview)) {
            if ($this->resolveAssetAbsolutePath($theme, $preview) === null) {
                return '';
            }

            return $this->themeAssetUrlPrefix($theme) . '/' . ltrim($preview, '/');
        }
        if (str_starts_with($preview, '/static/') || str_starts_with($preview, '/uploads/')) {
            $file = ROOT_PATH . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $preview);

            return is_file($file) ? $preview : '';
        }

        return $preview;
    }

    /** @return list<string> */
    private function viewportSubdirs(): array
    {
        $current = ClientViewport::current();
        $out     = [$current];
        if ($current !== ClientViewport::PC) {
            $out[] = ClientViewport::PC;
        }

        return $out;
    }

    /** @return list<string> */
    private function viewportRelativeCandidates(string $relative): array
    {
        $out = [];
        foreach ($this->viewportSubdirs() as $sub) {
            $out[] = $sub . '/' . $relative;
        }
        $out[] = $relative;

        return array_values(array_unique($out));
    }

    private function dirHasPhpTemplates(string $dir): bool
    {
        foreach (glob(rtrim($dir, '/\\') . '/*.php') ?: [] as $file) {
            if (is_file($file) && !str_starts_with(basename($file), '_')) {
                return true;
            }
        }

        return false;
    }

    private function resolveMemberRelativePath(string $pack, string $relative): string
    {
        $pack     = $this->validateMemberTheme($pack);
        $relative = ltrim(str_replace(['..', '\\'], '', $relative), '/');
        if ($relative === '') {
            return '';
        }

        $base = ROOT_PATH . self::MEMBER_THEME_DIR . '/' . $pack;
        foreach ($this->viewportRelativeCandidates($relative) as $candidate) {
            $path = $base . '/' . $candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    private function readMemberMeta(string $pack): array
    {
        $file = ROOT_PATH . self::MEMBER_THEME_DIR . '/' . $pack . '/theme.json';
        if (!is_file($file)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($file), true);

        return is_array($json) ? $json : [];
    }

    /**
     * /static/theme/member/{pack}/pc/assets/… → template/member/{pack}/pc/assets/…
     */
    private function resolveMemberHttpAssetFile(string $pack, string $tail): ?string
    {
        $pack = $this->validateMemberTheme($pack);
        $tail = str_replace('\\', '/', $tail);
        $tail = ltrim($tail, '/');
        if ($tail === '' || str_contains($tail, '..')) {
            return null;
        }

        return $this->firstExistingAsset([
            ROOT_PATH . self::MEMBER_THEME_DIR . '/' . $pack,
            ROOT_PATH . 'public/static/theme/member/' . $pack,
        ], $tail);
    }

    /** @param list<string> $roots */
    private function firstExistingAsset(array $roots, string $relative): ?string
    {
        foreach ($roots as $root) {
            $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($candidate)) {
                continue;
            }
            $realFile = realpath($candidate);
            $realRoot = realpath($root);
            if ($realFile === false || $realRoot === false) {
                continue;
            }
            $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($realFile, $prefix)) {
                return $realFile;
            }
        }

        return null;
    }
}
