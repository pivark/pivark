<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\model\FloatContactItem;

use think\facade\Request;

use app\common\service\audit\AuditLogService;
use app\common\service\front\FrontAuthService;
use app\common\service\config\ConfigService;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\theme\ThemeService;
use app\common\support\LocalFile;
use app\common\service\infra\CacheConfigService;
use think\App;
use think\facade\Cache;

/**
 * 系统运行模式：后台 configs.site_mode ↔ ThinkPHP app_debug / 前台页面缓存
 */
class SiteModeService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly AuditLogService $auditLogService,
        private readonly ThemeService $themeService,
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly FrontAuthService $frontAuthService,
    ) {
    }

    public const DEV = 'dev';
    public const OPS = 'mode';

    private static ?string $mode = null;

    /** bootstrap 时是否因无法读库而使用 dev 回退 */
    private static bool $bootstrapDbFallback = false;

    /**
     * 入口 bootstrap：从 DB 读取 configs.site_mode 并应用到 App（index.php 调用）
     *
     * 读库失败（未安装、连接失败、无表）→ 固定回退 **dev**（app_debug 开、前台不页缓存），
     * 便于安装向导排错；风险可控：生产勿长期断库，且可用环境变量 APP_DEBUG=1 强制输出错误。
     *
     * @param App $app ThinkPHP 应用实例
     * @return void
     */
    public function bootstrapFromApp(App $app): void
    {
        $dbValue = $this->readSiteModeFromDatabase($app);
        self::$bootstrapDbFallback = $dbValue === null;
        self::$mode                = $this->resolveBootstrapMode($dbValue);
        $this->applyToApp($app, self::$mode);
        $this->applyPhpIni(self::$mode);
    }

    /**
     * 是否在本次 bootstrap 中因数据库不可用而回退到 dev
     *
     * @return bool
     */
    public function bootstrapUsedDbFallback(): bool
    {
        return self::$bootstrapDbFallback;
    }

    /**
     * 从数据库读取 site_mode；不可用或空值时返回 null（由 resolveBootstrapMode 回退 dev）
     *
     * @param App $app ThinkPHP 应用实例
     * @return string|null
     */
    public function readSiteModeFromDatabase(App $app): ?string
    {
        try {
            $key = (string) config('pivark.site_mode_key', 'site_mode');
            if ($key === '') {
                $key = 'site_mode';
            }
            // 与 Config 模型同源，避免 bootstrap 阶段 db->name() 与 ORM 前缀不一致
            $val = \app\common\model\Config::where('key', $key)->value('value');
            if ($val === null || $val === '') {
                return null;
            }
            return (string) $val;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param string|null $dbValue 库中 site_mode，null 表示未读到 → dev
     * @return string
     */
    public function resolveBootstrapMode(?string $dbValue): string
    {
        if ($dbValue === null || trim($dbValue) === '') {
            return self::DEV;
        }
        return $this->normalize($dbValue);
    }

    /**
     * @param string $raw dev | mode
     * @return string self::DEV 或 self::OPS
     */
    public function normalize(string $raw): string
    {
        $raw = strtolower(trim($raw));
        return $raw === self::OPS ? self::OPS : self::DEV;
    }

    /**
     * @return mixed
     */
    public function current(): string
    {
        if (self::$bootstrapDbFallback || self::$mode === null) {
            self::$mode = $this->normalize((string) $this->configService->get('site_mode', self::DEV));
        }
        return self::$mode;
    }

    /**
     * 后台读库后与 configs.site_mode 对齐（避免 bootstrap 缓存与页面展示不一致）
     * @return mixed
     * @param mixed $mode
     */
    public function setRuntimeMode(string $mode): void
    {
        self::$mode                = $this->normalize($mode);
        self::$bootstrapDbFallback = false;
    }

    /**
     * @return mixed
     */
    public function isDev(): bool
    {
        return $this->current() === self::DEV;
    }

    /**
     * @return mixed
     */
    public function isProduction(): bool
    {
        return $this->current() === self::OPS;
    }

    /**
     * 保存配置后更新内存态并清理缓存
     *
     * @param string      $newMode 新模式
     * @param string|null $oldMode 旧模式
     * @return void
     */
    public function onModeChanged(string $newMode, ?string $oldMode = null): void
    {
        $newMode = $this->normalize($newMode);
        $oldMode = $oldMode !== null ? $this->normalize($oldMode) : null;
        self::$mode = $newMode;

        $this->clearRuntimeCaches();

        if ($oldMode !== null && $oldMode !== $newMode) {
            $this->auditLogService->operate(
                '切换系统运行模式',
                'admin.config',
                ['from' => $oldMode, 'to' => $newMode],
                true
            );
        }
    }

    /**
     * @param App    $app
     * @param string $mode dev | mode
     * @return void
     */
    public function applyToApp(App $app, string $mode): void
    {
        $dev = $this->normalize($mode) === self::DEV;
        // ThinkPHP 8：Config::set(array $config, ?string $name = null)
        $app->config->set([
            'app_debug'      => $dev,
            'app_trace'      => $dev,
            'show_error_msg' => $dev,
        ], 'app');
        $existing = $app->config->get('pivark');
        if (!\is_array($existing)) {
            $existing = [];
        }
        $app->config->set(array_merge($existing, [
            'runtime_site_mode' => $this->normalize($mode),
        ]), 'pivark');
    }

    /**
     * PHP 错误是否输出到页面
     *
     * 优先级：环境变量 APP_DEBUG=1（强制开） > 当前运行模式 dev/mode
     *
     * @param string|null $mode dev | mode，null 时用 current()
     * @return void
     */
    public function applyPhpIni(?string $mode = null): void
    {
        if ($this->envAppDebugEnabled()) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
            return;
        }
        $dev = $this->normalize($mode ?? $this->current()) === self::DEV;
        ini_set('display_errors', $dev ? '1' : '0');
        if ($dev) {
            error_reporting(E_ALL);
        }
    }

    /**
     * 环境变量 APP_DEBUG=1|true|yes 时强制显示 PHP 错误（与 DB site_mode 无关）
     *
     * @return bool
     */
    public function envAppDebugEnabled(): bool
    {
        return filter_var(getenv('APP_DEBUG') ?: '', FILTER_VALIDATE_BOOLEAN);
    }

    /** APP_ENV 是否为 production / prod（与 DB site_mode 独立） */
    public function isProductionAppEnv(): bool
    {
        $env = strtolower(trim((string) env('APP_ENV', '')));

        return in_array($env, ['production', 'prod'], true);
    }

    /**
     * 开发自动化：是否允许 peek 验证码明文（非生产 APP_ENV）。
     * dig/test 常以 site_mode=mode 验页缓存：用 CAPTCHA_DEBUG_PEEK=1（site.env）开启，禁止把 lane 主机名写进 Community 源码。
     */
    public function allowsDebugCaptchaPeek(): bool
    {
        if ($this->isProductionAppEnv()) {
            return false;
        }
        if ($this->isDev()) {
            return true;
        }
        $flag = strtolower(trim((string) env('CAPTCHA_DEBUG_PEEK', getenv('CAPTCHA_DEBUG_PEEK') ?: '')));

        return in_array($flag, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * 运营模式：尝试读取整页缓存
     *
     * @param string               $template 模板名
     * @param array<string, mixed> $vars     页面变量
     * @return string|null 命中缓存的 HTML
     */
    public function getPageCache(string $template, array $vars): ?string
    {
        if (!$this->isProduction()) {
            return null;
        }
        $ttl = (int) config('pivark.page_cache_ttl', 300);
        if ($ttl < 1) {
            return null;
        }
        $staleTtl = max(0, (int) config('pivark.page_cache_stale_ttl', 120));
        $key      = $this->pageCacheKey($template, $vars);

        if ($this->pageCacheUsesRedis()) {
            try {
                $hit = Cache::store('redis')->get('pv_page_html:' . $key);
                if (is_string($hit) && $hit !== '') {
                    return $hit;
                }
            } catch (\Throwable) {
                // 回退文件
            }
        }

        $file = $this->pageCacheFileFromKey($key);
        if (!is_file($file)) {
            return null;
        }
        $age = time() - (int) filemtime($file);
        if ($age > $ttl + $staleTtl) {
            LocalFile::unlinkIfExists($file);

            return null;
        }
        $html = (string) file_get_contents($file);

        return $html !== '' ? $html : null;
    }

    /**
     * @param string               $template
     * @param array<string, mixed> $vars
     * @param string               $html
     * @return void
     */
    public function setPageCache(string $template, array $vars, string $html): void
    {
        if (!$this->isProduction() || $html === '') {
            return;
        }
        $ttl = (int) config('pivark.page_cache_ttl', 300);
        if ($ttl < 1) {
            return;
        }
        $staleTtl = max(0, (int) config('pivark.page_cache_stale_ttl', 120));
        $key      = $this->pageCacheKey($template, $vars);

        if ($this->pageCacheUsesRedis()) {
            try {
                Cache::store('redis')->set('pv_page_html:' . $key, $html, $ttl + $staleTtl);

                return;
            } catch (\Throwable) {
                // 回退文件
            }
        }

        $file = $this->pageCacheFileFromKey($key);
        $dir  = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        file_put_contents($file, $html, LOCK_EX);
    }

    /**
     * @return mixed
     */
    public function clearPageCache(): void
    {
        // 页缓存键含 FrontCacheInvalidator 代际（pageCacheKey）；调用方须先 bump。
        // 禁止 Redis KEYS 扫删：大站会把「保存配置」拖到 10s+ 超时。
        LocalFile::removeDirRecursive($this->runtimeRoot() . 'page_cache', 'site_mode_page_cache');
        LocalFile::removeDirRecursive($this->runtimeRoot() . 'search_cache', 'site_mode_search_cache');
    }

    /** 页缓存 + 搜索 JSON 缓存（FrontCacheInvalidator 调用） */
    public function clearPageAndSearchFiles(): void
    {
        $this->clearPageCache();
    }

    /**
     * 运营模式：搜索结果 JSON 缓存（减轻重复 LIKE 查询）
     *
     * @return array<string, mixed>|null
     */
    public function getSearchCache(string $keyword, int $page, int $limit): ?array
    {
        if (!$this->isProduction()) {
            return null;
        }
        $ttl = (int) config('pivark.page_cache_ttl', 300);
        if ($ttl < 1) {
            return null;
        }
        $file = $this->searchCachePath($keyword, $page, $limit);
        if (!is_file($file)) {
            return null;
        }
        $age = time() - (int) filemtime($file);
        $staleTtl = max(0, (int) config('pivark.page_cache_stale_ttl', 120));
        if ($age > $ttl + $staleTtl) {
            LocalFile::unlinkIfExists($file);

            return null;
        }
        $raw = (string) file_get_contents($file);
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setSearchCache(string $keyword, int $page, int $limit, array $payload): void
    {
        if (!$this->isProduction() || $keyword === '') {
            return;
        }
        $ttl = (int) config('pivark.page_cache_ttl', 300);
        if ($ttl < 1) {
            return;
        }
        $file = $this->searchCachePath($keyword, $page, $limit);
        $dir  = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * @return mixed
     */
    public function clearRuntimeCaches(): void
    {
        $this->clearPageCache();
        foreach (['cache', 'temp'] as $sub) {
            LocalFile::removeDirRecursive($this->runtimeRoot() . $sub, 'site_mode_runtime_' . $sub);
        }
    }

    /** ThinkPHP file cache 目录（切换 cache_driver / onConfigSaved 时清 file store） */
    public function purgeThinkCacheDirectory(): void
    {
        LocalFile::removeDirRecursive($this->runtimeRoot() . 'cache', 'site_mode_think_cache');
    }

    /**
     * 整页缓存路径：身份 = 代际 + 主题 + URI + 受众。
     * 禁止把 $vars 编进键：详情等会把 click/正文塞进 vars，每次请求键都变，缓存永远不命中。
     * $template / $vars 保留入参以兼容调用方，不参与指纹。
     *
     * @param string               $template
     * @param array<string, mixed> $vars
     */
    private function pageCachePath(string $template, array $vars): string
    {
        return $this->pageCacheFileFromKey($this->pageCacheKey($template, $vars));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function pageCacheKey(string $template, array $vars): string
    {
        unset($template, $vars);
        $theme    = $this->themeService->getCurrentTheme();
        $uri      = (string) Request::server('REQUEST_URI', '/');
        $audience = $this->pageCacheAudienceKey();
        $gen      = $this->frontCacheInvalidator->generation();

        return hash('sha256', $gen . '|' . $theme . '|' . $uri . '|' . $audience);
    }

    private function pageCacheFileFromKey(string $key): string
    {
        return $this->runtimeRoot() . 'page_cache/' . substr($key, 0, 2) . '/' . $key . '.html';
    }

    private function pageCacheUsesRedis(): bool
    {
        try {
            return app(CacheConfigService::class)->effectiveDriver() === CacheConfigService::DRIVER_REDIS;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 页面缓存隔离：游客 / 会员等级 / 后台预览。
     * 仅 preview_token 或 preview=1 时隔离 admin；后台登录态点开前台链接不得另开键，
     * 否则与游客缓存失联，冷渲染常 3～4s。
     */
    private function pageCacheAudienceKey(): string
    {
        if ($this->frontAuthService->allowAdminPreview()) {
            return 'admin:preview';
        }

        if ($this->frontAuthService->isLoggedIn()) {
            $member = $this->frontAuthService->current();
            $level  = is_array($member) ? (int) ($member['member_level_id'] ?? 0) : 0;

            return 'member:' . $level;
        }

        return 'guest';
    }

    private function searchCachePath(string $keyword, int $page, int $limit): string
    {
        $gen = $this->frontCacheInvalidator->generation();
        $key = hash('sha256', $gen . '|' . $this->themeService->getCurrentTheme() . '|search|' . $keyword . '|' . $page . '|' . $limit);

        return $this->runtimeRoot() . 'search_cache/' . substr($key, 0, 2) . '/' . $key . '.json';
    }

    /** 页缓存代际 SSOT（与 FrontCacheInvalidator 一致） */
    public function pageCacheGeneration(): int
    {
        return $this->frontCacheInvalidator->generation();
    }

    /** 运营模式是否启用整页 HTML 缓存 */
    public function isPageCacheEnabled(): bool
    {
        if (!$this->isProduction()) {
            return false;
        }

        return (int) config('pivark.page_cache_ttl', 300) > 0;
    }

    private function runtimeRoot(): string
    {
        if (defined('RUNTIME_PATH')) {
            return \RUNTIME_PATH;
        }
        if (defined('ROOT_PATH')) {
            return \ROOT_PATH . 'data/runtime/';
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR;
    }
}
