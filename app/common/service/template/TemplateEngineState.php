<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\service\plugin\PluginService;
use app\common\support\SiteDomainContext;
use app\common\support\SiteUrl;

/**
 * 前台 {pv:*} 标签模板引擎（纯标签解析，不使用 eval）
 */

/** 模板引擎运行时状态与内核/插件扩展标签注册 */
class TemplateEngineState
{

public static ?string $activeTheme = null;

    public static bool $memberTemplateRender = false;

    public const MAX_PARSE_DEPTH = 24;

    /** @var list<string> 主循环检测用，避免对大 HTML 跑超长正则 */
    private const CORE_DETECT_TAGS = [
        'if', 'foreach', 'cache', 'include', 'config', 'var', 'assign', 'breadcrumb', 'position',
        'page', 'tagpage', 'nav', 'seo', 'tagnav', 'tagindex', 'searchform', 'list', 'arclist', 'related',
        'section',
        'tag', 'tagcloud', 'taglist', 'arcview', 'tagurl', 'friendlinks',
        'siteads',
        // 旧名（normalize 前 detect）
        'pagelist', 'pagination', 'navigation', 'tagsnav', 'documentlist', 'tagdocuments', 'volist',
    ];

    /** @var list<string>|null config/kernel_template_tags.php 缓存 */
    private static ?array $kernelDetectTagsCache = null;

    /** @return list<string> L1 registerKernelTag（detect 时不必先 boot 插件） */
    public static function kernelDetectTagNames(): array
    {
        if (self::$kernelDetectTagsCache !== null) {
            return self::$kernelDetectTagsCache;
        }
        /** @var mixed $cfg */
        $cfg = config('kernel.template_tags.tags');
        if (!is_array($cfg)) {
            self::$kernelDetectTagsCache = [];

            return self::$kernelDetectTagsCache;
        }
        $names = [];
        foreach ($cfg as $name) {
            $name = strtolower(trim((string) $name));
            if ($name !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                $names[] = $name;
            }
        }
        self::$kernelDetectTagsCache = array_values(array_unique($names));

        return self::$kernelDetectTagsCache;
    }

    /** @var array<string, callable> weapp + template_tag_register 钩子（C 层） */
    public static array $weappTagHandlers = [];

    /** @var array<string, callable> 内核标签处理器（form · favorite · product/item 等） */
    public static array $kernelTagHandlers = [];

    private static ?string $extensionTagPatternPart = null;

    private static string $extensionTagPatternFingerprint = '';

    public static bool $extensionTagsReady = false;

    public static bool $tagdocumentsPrefetched = false;

    /**
     * @param string               $template 模板名（不含 .php）
     * @param array<string, mixed> $vars     页面变量
     * @return string HTML
     *
     * 运营模式整页缓存由 {@see app(\app\common\service\site\SiteModeService::class)->getPageCache()} 在 {@see \app\home\controller\Base::render()} 层完成；
     * 开发模式每次请求均重新解析 `{pv:*}`。
     */

public function registerPluginTag(string $name, callable $handler): void
    {
        $name = strtolower(trim($name));
        if ($name !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            TemplateEngineState::$weappTagHandlers[$name] = $handler;
        }
    }

    public function registerKernelTag(string $name, callable $handler): void
    {
        $name = strtolower(trim($name));
        if ($name !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            TemplateEngineState::$kernelTagHandlers[$name] = $handler;
        }
    }

    /** 内核覆盖同名 weapp 扩展标签（与 ensureExtensionTags 注册顺序一致） */
    public static function resolveTagHandler(string $name): ?callable
    {
        $name = strtolower(trim($name));

        return TemplateEngineState::$kernelTagHandlers[$name]
            ?? TemplateEngineState::$weappTagHandlers[$name]
            ?? null;
    }

    /** 清空 L1 + weapp 扩展标签表（插件 bootstrap 每进程一次后须重注册） */
    public function resetExtensionTags(): void
    {
        TemplateEngineState::$weappTagHandlers = [];
        TemplateEngineState::$kernelTagHandlers = [];
        TemplateEngineState::$extensionTagsReady = false;
        self::$extensionTagPatternPart        = null;
        self::$extensionTagPatternFingerprint = '';
    }

    public function resetTagdocumentsPrefetch(): void
    {
        self::$tagdocumentsPrefetched = false;
    }

    /** 每请求重置模板解析运行时（siteVars、tagdocuments/tagcloud 预取等） */
    public function resetRequestRuntime(): void
    {
        app(TemplateSiteVars::class)->forgetRequestCache();
        $this->resetTagdocumentsPrefetch();
        app(TemplateTagdocumentsBatchService::class)->reset();
        app(TemplateTagcloudBatchService::class)->reset();
        app(TemplateAssignStateService::class)->reset();
        app(TemplateIncludeMemoService::class)->reset();
        app(TemplateIncludeDepthService::class)->reset();
        app(TemplateTagInvokeCacheService::class)->reset();
        TemplateEngineState::$extensionTagsReady = false;
    }

    /** 内核与 weapp 扩展标签就绪 */
    public function ensureExtensionTags(): void
    {
        if (TemplateEngineState::$extensionTagsReady) {
            return;
        }
        \app(\app\common\service\plugin\PluginService::class)->bootstrapEnabled();
        app(\app\common\service\kernel\KernelBootstrapService::class)->registerKernelHooksOnce();
        app(\app\common\service\hook\HookService::class)->fire('template_tag_register', [
            'register' => function (string $name, callable $handler): void {
                $this->registerPluginTag($name, $handler);
            },
        ]);
        // resetExtensionTags 后须重注册 L1 品项/表单等（KernelBootstrapService::boot）
        app(\app\common\service\kernel\KernelBootstrapService::class)->boot();
        TemplateEngineState::$extensionTagsReady = true;
    }

    /** 已注册内核与 weapp 扩展标签名（parse 动态段用） */
    public function registeredExtensionTagNames(): array
    {
        $this->ensureExtensionTags();

        return array_keys(TemplateEngineState::$kernelTagHandlers + TemplateEngineState::$weappTagHandlers);
    }

    /** 已注册扩展标签名指纹（parse/compile 缓存键用） */
    public function extensionTagDetectPart(): string
    {
        $names = $this->registeredExtensionTagNames();
        sort($names);

        return implode(',', $names);
    }

    /** 扩展标签正则片段（按指纹缓存，避免每轮 parse 重建） */
    public function extensionTagPatternPart(): string
    {
        $fp = $this->extensionTagDetectPart();
        if (self::$extensionTagPatternPart !== null && self::$extensionTagPatternFingerprint === $fp) {
            return self::$extensionTagPatternPart;
        }
        $part = '';
        foreach ($this->registeredExtensionTagNames() as $tagName) {
            $part .= '|' . preg_quote($tagName, '/');
        }
        self::$extensionTagPatternPart        = $part;
        self::$extensionTagPatternFingerprint = $fp;

        return $part;
    }

    /** stripos 检测是否仍有待解析 {pv:*}，替代对大 HTML 的 preg_match */
    public function hasDetectablePvTags(string $html): bool
    {
        if (!str_contains($html, '{pv:') && !str_contains($html, '{PV:')) {
            return false;
        }
        foreach (self::CORE_DETECT_TAGS as $tag) {
            if (stripos($html, '{pv:' . $tag) !== false) {
                return true;
            }
        }
        foreach (self::kernelDetectTagNames() as $tag) {
            if (stripos($html, '{pv:' . $tag) !== false) {
                return true;
            }
        }
        foreach ($this->registeredExtensionTagNames() as $name) {
            if (stripos($html, '{pv:' . $name) !== false) {
                return true;
            }
        }

        return false;
    }
}
