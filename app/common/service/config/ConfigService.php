<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\config;

use app\common\support\ServiceResult;

use app\common\service\audit\AuditLogService;
use app\common\support\ConfigSensitiveKeys;
use app\common\service\infra\CacheConfigService;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\site\AdminEntryAliasService;
use app\common\service\site\SiteBrandService;
use app\common\service\site\SiteModeService;
use app\common\service\site\SiteStatusService;
use app\common\service\media\MediaUrlService;
use app\common\service\site\SiteUrlModeService;
use app\common\service\static\StaticHtmlService;
use app\common\service\theme\ThemeService;
use app\common\service\upload\UploadService;
use app\common\model\Config;
use app\common\service\content\ContentEditorService;
use app\common\support\OpsLog;
use app\common\support\InstallGate;
use think\facade\Cache;

/** 系统配置读写 */
class ConfigService
{

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public const CONFIG_ALL_CACHE_KEY = 'pivark:config:all:v1';

    private const CONFIG_ALL_CACHE_TTL = 300;

    /** @var array<string, mixed>|null 单次请求内 configs 全表缓存 */
    private static ?array $requestCache = null;

    /**
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        if (!InstallGate::isInstalled()) {
            return [];
        }
        if (self::$requestCache !== null) {
            return self::$requestCache;
        }
        $cached = Cache::get(self::CONFIG_ALL_CACHE_KEY);
        if (is_array($cached)) {
            self::$requestCache = $cached;

            return self::$requestCache;
        }
        self::$requestCache = Config::getAll();
        if (!is_array(self::$requestCache)) {
            self::$requestCache = [];
        }
        try {
            Cache::set(self::CONFIG_ALL_CACHE_KEY, self::$requestCache, self::CONFIG_ALL_CACHE_TTL);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('config_all_cache_set_failed', ['msg' => $e->getMessage()]);
        }

        return self::$requestCache;
    }

    /**
     * @param string $key     配置键
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get(string $key, $default = '')
    {
        if (ConfigSensitiveKeys::isCredentialKey($key)) {
            return app(ConfigSecretService::class)->resolve($key, $default);
        }

        return $this->getAll()[$key] ?? $default;
    }

    /**
     * 模板 {pv:config} 专用：禁止输出凭据与高敏键。
     *
     * @param mixed $default
     * @return mixed
     */
    public function getForTemplate(string $key, $default = '')
    {
        if (ConfigSensitiveKeys::isTemplateForbidden($key)) {
            return '';
        }

        return $this->get($key, $default);
    }

    /** 写库后丢弃请求内缓存（下次 get/getAll 重新查表） */
    public function forgetRequestCache(): void
    {
        self::$requestCache = null;
        // 站点可在 file↔redis 间切换；只删 default 会留下另一店的陈旧全表快照（如百度 Token 保存后读回旧空值）。
        foreach (['file', 'redis'] as $store) {
            try {
                Cache::store($store)->delete(self::CONFIG_ALL_CACHE_KEY);
            } catch (\Throwable $e) {
                OpsLog::businessWarning('config_all_cache_delete_failed', [
                    'store' => $store,
                    'msg'   => $e->getMessage(),
                ]);
            }
        }
        try {
            Cache::delete(self::CONFIG_ALL_CACHE_KEY);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('config_all_cache_delete_failed', [
                'store' => 'default',
                'msg'   => $e->getMessage(),
            ]);
        }
        ThemeService::forgetCurrentThemeCache();
    }

    /**
     * 绕过 getAll 缓存直读 configs 表（payment_*_open 等开关必须与库一致）
     *
     * @param mixed $default
     * @return mixed
     */
    public function getDirect(string $key, $default = '')
    {
        if (ConfigSensitiveKeys::isCredentialKey($key)) {
            return app(ConfigSecretService::class)->resolve($key, $default);
        }
        $row = Config::where('key', $key)->value('value');

        return ($row !== null && $row !== '') ? $row : $default;
    }

    /** 写库后强制用 DB 快照重建 Redis / 请求内 configs 缓存 */
    public function refreshAllCacheFromDatabase(): void
    {
        $this->forgetRequestCache();
        self::$requestCache = Config::getAll();
        if (!is_array(self::$requestCache)) {
            self::$requestCache = [];
        }
        try {
            Cache::set(self::CONFIG_ALL_CACHE_KEY, self::$requestCache, self::CONFIG_ALL_CACHE_TTL);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('config_all_cache_set_failed', ['msg' => $e->getMessage()]);
        }
    }

    /** 写入单个配置键（插件配置等场景）；凭据键走 config_secrets，禁止旁路明文 */
    public function set(string $key, mixed $value): void
    {
        if (ConfigSensitiveKeys::isCredentialKey($key)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
            $stored  = is_scalar($value) ? (string) $value : (is_string($encoded) ? $encoded : '');
            app(ConfigSecretService::class)->store($key, $stored);

            return;
        }
        // site_url / 强制 HTTPS 必须走 save 双键对齐 + SEO 副作用，禁止单键旁路分叉
        if ($key === 'site_url' || $key === 'site_force_https') {
            $this->save([$key => $value]);

            return;
        }
        Config::setValue($key, $value);
        $this->forgetRequestCache();
    }

    /**
     * @param array<string, mixed> $data 键值对批量保存
     * @return array{
     *   media_rewrite_recommended:int,
     *   media_rewrite_started:int,
     *   media_rewrite_finished:int,
     *   media_rewrite_job_id:string,
     *   sitemap_rebuilt:int,
     *   public_scheme:string
     * }
     */
    public function save(array $data): array
    {
        $sideEffects = [
            'media_rewrite_recommended' => 0,
            'media_rewrite_started'     => 0,
            'media_rewrite_finished'    => 0,
            'media_rewrite_job_id'      => '',
            'sitemap_rebuilt'           => 0,
            'public_scheme'             => \app\common\support\SiteUrl::publicScheme(),
        ];

        $data = $this->filterAllowedKeys($data);
        if ($data === []) {
            return $sideEffects;
        }

        // product_open：无专业版不可开启（默认关）；有授权才可拨开/关。生效面仍 publicSurfaceOpen()。
        if (array_key_exists('product_open', $data)
            && (string) $data['product_open'] === '1'
            && !\app\common\service\product\ProductCenterGateService::entitled()
        ) {
            $data['product_open'] = '0';
        }

        // URL 枚举须先归一再 schema：空串/旧别名否则 validate 直接抛 → SEO URL 保存 500
        $data = $this->normalizeSiteUrlModeKeys($data);

        $schemaErr = app(ConfigValueSchemaService::class)->validatePatch($data);
        if ($schemaErr !== null) {
            throw new \InvalidArgumentException($schemaErr->message() !== '' ? $schemaErr->message() : '配置校验失败');
        }

        $data = $this->normalizeUploadFormatKeys($data);
        // 键在 payload ≠ 协议真变：基本设置整包保存常带未改的 site_url，禁止每次写 robots/sitemap
        $schemeKeysPresent = array_key_exists('site_url', $data) || array_key_exists('site_force_https', $data);
        $oldScheme     = \app\common\support\SiteUrl::publicScheme();
        $oldPublicHome = rtrim(\app\common\support\SiteUrl::configuredPublicHome(), '/');
        $oldSiteUrl    = (string) $this->get('site_url', '');
        $oldForce      = (string) $this->get('site_force_https', '0');
        if ($schemeKeysPresent) {
            $data = \app\common\support\SiteUrl::syncForceHttpsPatch(
                $data,
                $oldSiteUrl,
                $oldForce,
            );
        }
        $pendingSiteUrl = array_key_exists('site_url', $data)
            ? trim((string) $data['site_url'])
            : trim($oldSiteUrl);
        $pendingForce = array_key_exists('site_force_https', $data)
            ? (((string) $data['site_force_https'] === '1') ? '1' : '0')
            : (((string) $oldForce === '1') ? '1' : '0');
        $schemeTouched = $schemeKeysPresent
            && ($pendingSiteUrl !== trim($oldSiteUrl) || $pendingForce !== (((string) $oldForce === '1') ? '1' : '0'));
        if (isset($data['site_copyright'])) {
            $data['site_copyright'] = app(SiteBrandService::class)->sanitizeCopyrightInput((string) $data['site_copyright']);
        }
        if (isset($data['site_name'])) {
            $data['site_name'] = app(SiteBrandService::class)->sanitizeSiteNameInput((string) $data['site_name']);
        }
        if (isset($data['content_editor'])) {
            $data['content_editor'] = app(ContentEditorService::class)->normalize((string) $data['content_editor']);
        }
        if (array_key_exists('editor_special_chars', $data)) {
            $data['editor_special_chars'] = ((string) $data['editor_special_chars'] === '1') ? '1' : '0';
            if ($data['editor_special_chars'] === '1') {
                app(\app\common\service\content\EditorSpecialCharsService::class)->ensureUtf8mb4();
            }
        }

        $clearPageCache = false;
        $themeChanged   = false;
        if ($schemeTouched) {
            $clearPageCache = true;
        }
        if (isset($data['site_theme'])) {
            $newTheme = app(ThemeService::class)->validateTheme((string) $data['site_theme']);
            $oldTheme = (string) $this->get('site_theme', '');
            $data['site_theme'] = $newTheme;
            if ($newTheme !== $oldTheme) {
                $themeChanged   = true;
                $clearPageCache = true;
            }
        }

        $oldMode     = null;
        $newMode     = null;
        $modeChanged = false;
        if (array_key_exists('site_mode', $data)) {
            $oldMode           = (string) $this->get('site_mode', SiteModeService::DEV);
            $newMode           = app(SiteModeService::class)->normalize((string) $data['site_mode']);
            $data['site_mode'] = $newMode;
            $modeChanged       = $newMode !== $oldMode;
        }

        $urlConfigChanged = false;
        foreach (app(SiteUrlModeService::class)->configKeys() as $urlKey) {
            if (!array_key_exists($urlKey, $data)) {
                continue;
            }
            $newUrlVal = trim((string) $data[$urlKey]);
            $oldUrlVal = trim((string) $this->get($urlKey, ''));
            if ($newUrlVal !== $oldUrlVal) {
                $clearPageCache   = true;
                $urlConfigChanged = true;
                break;
            }
        }
        foreach (\app\common\service\front\FrontPageMinifyService::CONFIG_KEYS as $minifyKey) {
            if (!array_key_exists($minifyKey, $data)) {
                continue;
            }
            $newMin = ((string) $data[$minifyKey] === '1') ? '1' : '0';
            $oldMin = ((string) $this->get($minifyKey, '0') === '1') ? '1' : '0';
            if ($newMin !== $oldMin) {
                $clearPageCache = true;
                break;
            }
        }
        if (array_key_exists(MediaUrlService::CONFIG_KEY, $data)) {
            $data[MediaUrlService::CONFIG_KEY] = app(MediaUrlService::class)->normalizeMode(
                (string) $data[MediaUrlService::CONFIG_KEY]
            );
        }
        if (array_key_exists(AdminEntryAliasService::CONFIG_KEY, $data)) {
            $data[AdminEntryAliasService::CONFIG_KEY] = app(AdminEntryAliasService::class)->normalizeInput(
                (string) $data[AdminEntryAliasService::CONFIG_KEY]
            );
        }
        if (array_key_exists('cache_driver', $data)) {
            $data['cache_driver'] = app(CacheConfigService::class)->normalizeDriverInput((string) $data['cache_driver']);
        }

        $data = $this->normalizeBinaryConfigValues($data);

        $cacheConfigChanged = false;
        foreach (array_keys($data) as $saveKey) {
            if (app(CacheConfigService::class)->isCacheConfigKey($saveKey)) {
                $cacheConfigChanged = true;
                break;
            }
        }

        foreach ($data as $key => $value) {
            if (ConfigSensitiveKeys::isCredentialKey($key)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
                $stored  = is_scalar($value) ? (string) $value : (is_string($encoded) ? $encoded : '');
                app(ConfigSecretService::class)->store($key, $stored);
                continue;
            }
            Config::setValue($key, $value);
        }
        app(ConfigGroupService::class)->syncGroupsForFlatPatch($data);
        $this->forgetRequestCache();

        if (array_key_exists('member_center_open', $data)) {
            app(\app\common\service\admin\AdminSpaMenuRouteCacheService::class)->bustAll();
        }

        if (array_key_exists(SiteStatusService::KEY, $data)) {
            app(SiteStatusService::class)->syncFrontClosedFlag((string) $data[SiteStatusService::KEY] !== '0');
        }

        if ($newMode !== null && $modeChanged) {
            app(SiteModeService::class)->onModeChanged($newMode, $oldMode);
            $clearPageCache = true;
            $app = app();
            if ($app instanceof \think\App) {
                app(SiteModeService::class)->applyToApp($app, $newMode);
                app(SiteModeService::class)->applyPhpIni($newMode);
            }
        }

        if ($clearPageCache) {
            // Redis/文件页缓存键含代际；先 bump 再清文件，避免无 KEYS 时仍命中旧 Redis HTML
            app(FrontCacheInvalidator::class)->bumpGeneration();
            app(SiteModeService::class)->clearPageCache();
        }

        if ($urlConfigChanged || $themeChanged) {
            app(StaticHtmlService::class)->syncAfterConfigChange();
        }

        foreach (['site_name', 'site_title', 'site_logo', 'site_footer', 'site_icp', 'site_copyright', 'site_keywords', 'site_description'] as $metaKey) {
            if (!array_key_exists($metaKey, $data)) {
                continue;
            }
            $newMeta = trim((string) $data[$metaKey]);
            $oldMeta = trim((string) $this->get($metaKey, ''));
            if ($newMeta !== $oldMeta) {
                app(FrontCacheInvalidator::class)->invalidateMeta();
                break;
            }
        }
        if ($schemeTouched) {
            app(FrontCacheInvalidator::class)->invalidateMeta();
        }

        if ($cacheConfigChanged) {
            app(CacheConfigService::class)->onConfigSaved();
        }

        // site_url / HTTPS：robots + sitemap 静态文件（Apache 可能直出）必须同批对齐协议
        if ($schemeTouched) {
            $robots = app(\app\common\service\seo\RobotsService::class);
            $robots->writePublicFile($robots->content());
            try {
                $sitemap = app(\app\common\service\seo\SitemapService::class);
                if ($sitemap->isEnabled()) {
                    $sitemap->rebuildPublicFiles();
                    $sideEffects['sitemap_rebuilt'] = 1;
                }
            } catch (\Throwable $e) {
                OpsLog::businessWarning('sitemap_rebuild_after_site_url_failed', [
                    'msg' => $e->getMessage(),
                ]);
            }

            $newScheme     = \app\common\support\SiteUrl::publicScheme();
            $newPublicHome = rtrim(\app\common\support\SiteUrl::configuredPublicHome(), '/');
            $sideEffects['public_scheme'] = $newScheme;
            // 公网站址/协议变了：相对模式把库内残留绝对链收成路径；绝对模式按当前 http/https 重写
            $publicBaseChanged = ($oldScheme !== $newScheme) || ($oldPublicHome !== $newPublicHome);
            if ($publicBaseChanged) {
                $this->startMediaRewriteAfterPublicBaseChange($sideEffects);
            }
        }

        $this->auditLogService->operate('保存系统配置', 'admin.config', [
            'keys'                        => array_keys($data),
            'media_rewrite_recommended'   => $sideEffects['media_rewrite_recommended'],
            'media_rewrite_started'       => $sideEffects['media_rewrite_started'],
            'media_rewrite_job_id'        => $sideEffects['media_rewrite_job_id'],
            'sitemap_rebuilt'             => $sideEffects['sitemap_rebuilt'],
        ]);

        return $sideEffects;
    }

    /**
     * 公网站址/协议变更后：自动启动并尽量同步跑完库内站内地址重写（与「附件与水印」同管道）。
     * 相对=收残留绝对链；绝对=按当前协议落绝对链。大站未跑完返回 job_id 续拍。
     *
     * @param array<string, mixed> $sideEffects
     */
    private function startMediaRewriteAfterPublicBaseChange(array &$sideEffects): void
    {
        try {
            $media = app(MediaUrlService::class);
            $start = $media->startRewriteJob();
            if (!$start->isOk()) {
                $sideEffects['media_rewrite_recommended'] = 1;
                OpsLog::businessWarning('media_rewrite_auto_start_failed', [
                    'msg' => $start->message(),
                ]);

                return;
            }
            $view   = $start->dataArray();
            $jobId  = trim((string) ($view['job_id'] ?? ''));
            $status = (string) ($view['status'] ?? '');
            if ($jobId === '' && $status === 'finished') {
                return;
            }
            if ($jobId === '') {
                $sideEffects['media_rewrite_recommended'] = 1;

                return;
            }

            $sideEffects['media_rewrite_started'] = 1;
            $sideEffects['media_rewrite_job_id']  = $jobId;

            // 同请求内尽量跑完；超时/拍数上限则留下 job 供「附件与水印」或前端续拍
            @set_time_limit(120);
            $cursor = (int) ($view['cursor'] ?? 0);
            $guard  = 0;
            while ($guard < 500) {
                $guard++;
                $tick = $media->tickRewriteJob($jobId, $cursor);
                if (!$tick->isOk()) {
                    OpsLog::businessWarning('media_rewrite_auto_tick_failed', [
                        'job_id' => $jobId,
                        'msg'    => $tick->message(),
                    ]);
                    $sideEffects['media_rewrite_recommended'] = 1;

                    return;
                }
                $view   = $tick->dataArray();
                $status = (string) ($view['status'] ?? '');
                if ($status === 'finished') {
                    $sideEffects['media_rewrite_finished'] = 1;
                    $sideEffects['media_rewrite_recommended'] = 0;

                    return;
                }
                if ($status === 'failed' || $status === 'cancelled') {
                    $sideEffects['media_rewrite_recommended'] = 1;
                    OpsLog::businessWarning('media_rewrite_auto_tick_status', [
                        'job_id' => $jobId,
                        'status' => $status,
                        'error'  => (string) ($view['error'] ?? ''),
                    ]);

                    return;
                }
                $cursor = (int) ($view['cursor'] ?? $cursor);
            }
            // 未跑完：job 仍在，前端/附件页可续
            $sideEffects['media_rewrite_recommended'] = 0;
        } catch (\Throwable $e) {
            $sideEffects['media_rewrite_recommended'] = 1;
            OpsLog::businessWarning('media_rewrite_auto_start_exception', [
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 站点联系方式：自定义变量标识 → 同步写入的系统配置键
     * 模板继续用 {$site_phone} 等；后台在「自定义变量」中维护。
     *
     * @return array<string, array{title: string, type: string, site_key: string}>
     */
    public function builtinContactCustomVars(): array
    {
        return [
            'phone' => [
                'title'    => '站点电话',
                'type'     => 'text',
                'site_key' => 'site_phone',
            ],
            'email' => [
                'title'    => '站点邮箱',
                'type'     => 'text',
                'site_key' => 'site_email',
            ],
            'address' => [
                'title'    => '站点地址',
                'type'     => 'textarea',
                'site_key' => 'site_address',
            ],
            'wechat_qr' => [
                'title'    => '微信二维码',
                'type'     => 'image',
                'site_key' => 'site_wechat_qr',
            ],
            'wechat_mp_qr' => [
                'title'    => '公众号二维码',
                'type'     => 'image',
                'site_key' => 'site_wechat_mp_qr',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>> 自定义变量列表
     */
    public function getCustomVars(): array
    {
        $this->ensureBuiltinContactCustomVars();

        $all  = $this->getAll();
        $vars = [];
        $builtin = $this->builtinContactCustomVars();

        foreach ($all as $key => $value) {
            if (!str_starts_with($key, 'cv_')) {
                continue;
            }
            if (!preg_match('/^cv_(.+)_(title|type|value)$/', $key, $m)) {
                continue;
            }
            $name = $m[1];
            $attr = $m[2];

            if (!isset($vars[$name])) {
                $vars[$name] = ['name' => $name];
            }

            if ($attr === 'value') {
                $vars[$name]['default_value'] = $value;
            } else {
                $vars[$name][$attr] = $value;
            }
        }

        foreach ($vars as $name => &$row) {
            if (isset($builtin[$name])) {
                $row['builtin'] = 1;
                $row['template_alias'] = '{$' . $builtin[$name]['site_key'] . '}';
            } else {
                $row['builtin'] = 0;
                $row['template_alias'] = '';
            }
        }
        unset($row);

        $list = array_values($vars);
        usort($list, static function ($a, $b): int {
            $ab = (int) ($a['builtin'] ?? 0);
            $bb = (int) ($b['builtin'] ?? 0);
            if ($ab !== $bb) {
                return $bb <=> $ab;
            }

            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return $list;
    }

    /**
     * 若缺少内置联系方式变量，从现有 site_* 配置初始化（幂等）。
     */
    public function ensureBuiltinContactCustomVars(): void
    {
        $all = $this->getAll();
        $changed = false;
        foreach ($this->builtinContactCustomVars() as $name => $meta) {
            $titleKey = 'cv_' . $name . '_title';
            if (isset($all[$titleKey]) && trim((string) $all[$titleKey]) !== '') {
                continue;
            }
            $siteKey = $meta['site_key'];
            $value = trim((string) ($all[$siteKey] ?? ''));
            Config::setValue($titleKey, $meta['title']);
            Config::setValue('cv_' . $name . '_type', $meta['type']);
            Config::setValue('cv_' . $name . '_value', $value);
            $changed = true;
        }
        if ($changed) {
            $this->forgetRequestCache();
        }
    }

    /**
     * @param string $prefix 键前缀
     * @return int 删除行数
     */
    public function deleteByPrefix(string $prefix): int
    {
        $n = Config::deleteByPrefix($prefix);
        if ($n > 0) {
            $this->forgetRequestCache();
        }
        return $n;
    }

    /**
     * 按精确键名删除配置行。
     *
     * @param list<string> $keys
     * @return int 删除行数
     */
    public function deleteKeys(array $keys): int
    {
        $n = Config::deleteByKeys($keys);
        if ($n > 0) {
            $this->forgetRequestCache();
        }

        return $n;
    }

    /**
     * 保存自定义变量（cv_{name}_*）
     *
     * @param string $name         变量标识
     * @param string $title        显示名
     * @param string $type         类型
     * @param string $defaultValue 默认值
     * @return ServiceResult
     */
    public function saveCustomVar(string $name, string $title, string $type, string $defaultValue): ServiceResult
    {
        $err = $this->validateCustomVarName($name);
        if ($err !== null) {
            return $err;
        }
        if (!in_array($type, ['text', 'textarea', 'image', 'switch', 'media'], true)) {
            return ServiceResult::fail('变量类型不支持');
        }
        if ($title === '') {
            return ServiceResult::fail('变量名称不能为空');
        }

        $payload = [
            'cv_' . $name . '_title' => $title,
            'cv_' . $name . '_type'   => $type,
            'cv_' . $name . '_value'  => $defaultValue,
        ];
        foreach ($payload as $key => $value) {
            if (!$this->isValidCustomVarStorageKey($key)) {
                return ServiceResult::fail('变量标识不合法');
            }
            Config::setValue($key, $value);
        }

        $builtin = $this->builtinContactCustomVars();
        if (isset($builtin[$name])) {
            $siteKey = $builtin[$name]['site_key'];
            if ($this->isValidStorageKey($siteKey)) {
                Config::setValue($siteKey, $defaultValue);
            }
        }

        $this->forgetRequestCache();

        $this->auditLogService->operate('保存自定义变量', 'admin.config', ['var' => $name, 'type' => $type]);

        return ServiceResult::ok(null, '保存成功');
    }

    /**
     * @param string $name 变量标识
     * @return ServiceResult
     */
    public function deleteCustomVar(string $name): ServiceResult
    {
        if (isset($this->builtinContactCustomVars()[$name])) {
            return ServiceResult::fail('系统内置联系方式变量不可删除，可清空内容后保存');
        }
        $err = $this->validateCustomVarName($name);
        if ($err !== null) {
            return $err;
        }
        Config::deleteByPrefix('cv_' . $name);
        $this->forgetRequestCache();
        $this->auditLogService->operate('删除自定义变量', 'admin.config', ['var' => $name]);

        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function filterAllowedKeys(array $data): array
    {
        $allowed = config('pivark.config_save_allowed_keys');
        if (!is_array($allowed) || $allowed === []) {
            return [];
        }
        $flip = array_flip($allowed);
        $out  = [];
        foreach ($data as $key => $value) {
            if (!isset($flip[$key])) {
                continue;
            }
            if (!$this->isValidStorageKey($key)) {
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeBinaryConfigValues(array $data): array
    {
        $explicit = [
            'member_register_ip_limit',
            'member_login_captcha',
            'member_register_captcha',
            'smart_search_enabled',
            'page_cache_warm_enabled',
            'front_minify_html_on',
            'front_minify_inline_css_on',
            'front_minify_inline_js_on',
        ];
        foreach ($data as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $isBinary = in_array($key, $explicit, true)
                || str_ends_with($key, '_enabled')
                || str_ends_with($key, '_allow')
                || str_ends_with($key, '_open');
            if (!$isBinary) {
                continue;
            }
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalized === null) {
                $normalized = in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
            }
            $data[$key] = $normalized ? '1' : '0';
        }

        return $data;
    }

    /**
     * @param string $name
     * @return ServiceResult|null
     */
    public function validateCustomVarName(string $name): ?ServiceResult
    {
        $name = trim($name);
        if ($name === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,50}$/', $name)) {
            return ServiceResult::fail('变量标识格式不正确');
        }
        $lower = strtolower($name);
        foreach ($this->reservedCustomVarPrefixes() as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return ServiceResult::fail('变量标识不能使用系统保留前缀');
            }
        }
        foreach ($this->allowedKeys() as $sysKey) {
            if ($lower === strtolower($sysKey)) {
                return ServiceResult::fail('变量标识与系统配置键冲突');
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    public function allowedKeys(): array
    {
        $keys = config('pivark.config_save_allowed_keys');
        if (!is_array($keys)) {
            return [];
        }
        $out = [];
        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function reservedCustomVarPrefixes(): array
    {
        $list = config('pivark.config_custom_var_reserved_prefixes');
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $prefix) {
            if (is_string($prefix) && $prefix !== '') {
                $out[] = $prefix;
            }
        }

        return $out;
    }

    private function isValidStorageKey(string $key): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key);
    }

    private function isValidCustomVarStorageKey(string $key): bool
    {
        return (bool) preg_match('/^cv_[a-zA-Z_][a-zA-Z0-9_]*_(title|type|value)$/', $key);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeUploadFormatKeys(array $data): array
    {
        if (app(UploadService::class)->svgUploadAllowed()) {
            return $data;
        }
        if (isset($data['upload_image_format'])) {
            $data['upload_image_format'] = $this->stripExtensionFromFormats((string) $data['upload_image_format'], 'svg');
        }
        return $data;
    }

    /**
     * URL 模式相关键：先归一（空串/旧别名）再进 schema，避免 SEO URL 保存 500。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeSiteUrlModeKeys(array $data): array
    {
        $urlMode = app(SiteUrlModeService::class);
        if (array_key_exists('site_url_mode', $data)) {
            $data['site_url_mode'] = $urlMode->normalizeMode((string) $data['site_url_mode']);
        }
        if (array_key_exists('site_url_suffix', $data)) {
            $data['site_url_suffix'] = $urlMode->normalizeSuffix((string) $data['site_url_suffix']);
        }
        if (array_key_exists('site_url_channel_rule', $data)) {
            $data['site_url_channel_rule'] = $urlMode->normalizeChannelRule((string) $data['site_url_channel_rule']);
        }
        if (array_key_exists('site_url_tag_page_rule', $data)) {
            $modeForPage = array_key_exists('site_url_mode', $data)
                ? $urlMode->normalizeMode((string) $data['site_url_mode'])
                : $urlMode->mode();
            $data['site_url_tag_page_rule'] = $urlMode->normalizeTagPageRule(
                (string) $data['site_url_tag_page_rule'],
                $modeForPage,
            );
        }
        if (array_key_exists('site_url_document_rule', $data)) {
            $data['site_url_document_rule'] = $urlMode->normalizeArticleRule((string) $data['site_url_document_rule']);
        }
        if (array_key_exists('site_list_page_style', $data)) {
            $legacy = strtolower(trim((string) $data['site_list_page_style'])) === 'path'
                ? SiteUrlModeService::TAG_PAGE_PATH
                : SiteUrlModeService::TAG_PAGE_QUERY;
            $modeForLegacy = array_key_exists('site_url_mode', $data)
                ? $urlMode->normalizeMode((string) $data['site_url_mode'])
                : $urlMode->mode();
            $data['site_url_tag_page_rule'] = $urlMode->normalizeTagPageRule($legacy, $modeForLegacy);
            unset($data['site_list_page_style']);
        }

        // 仅改 mode→static 且未带分页键时，也把库里的 query 收成 path
        if (
            array_key_exists('site_url_mode', $data)
            && $urlMode->normalizeMode((string) $data['site_url_mode']) === SiteUrlModeService::MODE_STATIC
            && !array_key_exists('site_url_tag_page_rule', $data)
        ) {
            $current = (string) $this->get('site_url_tag_page_rule', SiteUrlModeService::TAG_PAGE_QUERY);
            $data['site_url_tag_page_rule'] = $urlMode->normalizeTagPageRule($current, SiteUrlModeService::MODE_STATIC);
        }

        return $data;
    }

    private function stripExtensionFromFormats(string $formats, string $ext): string
    {
        $ext  = strtolower($ext);
        $list = [];
        foreach (explode('|', $formats) as $part) {
            $part = strtolower(trim($part));
            if ($part === '' || $part === $ext) {
                continue;
            }
            if ($part === 'jpeg') {
                $part = 'jpg';
            }
            if (!in_array($part, $list, true)) {
                $list[] = $part;
            }
        }
        return implode('|', $list);
    }
}
