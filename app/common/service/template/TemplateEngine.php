<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use think\facade\Request;

use app\common\service\theme\ThemeService;
use app\common\support\FrontVendorAsset;
use app\common\support\ProjectPaths;
use app\common\support\SiteDomainContext;
use app\common\support\SiteUrl;

/**
 * 前台 {pv:*} 标签模板引擎（纯标签解析，不使用 eval）
 */

/**
 * 前台 {pv:*} 模板引擎门面。
 * @see template/README.md
 */
class TemplateEngine
{

    /** @var array<string, string> theme id => post_render.php realpath */
    private static array $postRenderLibRealpath = [];

    /** @var array<string, true> theme id => 无 post_render */
    private static array $postRenderLibAbsent = [];

    /** 在 PluginBootstrap 与每次 render 前调用，保证 worker 内请求隔离 */
    public function beginRequest(): void
    {
        app(TemplateEngineState::class)->resetRequestRuntime();
        app(\app\common\service\front\FrontAssetRegistry::class)->resetRequest();
        app(\app\common\service\document\DocumentPublicService::class)->forgetListPublicRequestCache();
        app(\app\common\service\tag\TagPublicService::class)->forgetListPublicQueryRequestCache();
        app(\app\common\service\item\ItemService::class)->forgetListPublicRequestCache();
        app(\app\common\service\search\SearchDriverFactory::class)->reset();
    }

    /**
     * 绕过页级 parse 缓存（当前等同 render；预热/调试专用入口）
     *
     * @param array<string, mixed> $vars
     */
    public function renderUncached(string $template, array $vars = []): string
    {
        return $this->renderInternal($template, $vars, false);
    }

    public function render(string $template, array $vars = []): string
    {
        return $this->renderInternal($template, $vars, true);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function renderInternal(string $template, array $pageVars, bool $useParseCache): string
    {
        $this->beginRequest();
        $memberRender = str_starts_with($template, 'member/');
        if (!$memberRender) {
            \app(\app\common\service\plugin\PluginService::class)->bootstrapEnabled();
            app(\app\common\service\kernel\KernelBootstrapService::class)->boot();
        }
        $pageVars = $this->withRequestQuery($pageVars);
        $theme = app(ThemeService::class)->getCurrentTheme();
        TemplateEngineState::$activeTheme = $theme;

        TemplateEngineState::$memberTemplateRender = $memberRender;
        try {
            if ($memberRender) {
                $path = app(ThemeService::class)->resolveMemberTemplatePath($template);
            } else {
                $path = app(ThemeService::class)->resolveSiteTemplatePathWithFallback($template . '.php', $theme);
            }
        } finally {
            if (!$memberRender) {
                TemplateEngineState::$memberTemplateRender = false;
            }
        }
        if ($path === '' || !is_file($path)) {
            if ($memberRender) {
                $path = app(\app\common\service\plugin\registry\PluginFrontTemplateRegistry::class)->resolveMemberTemplate($template);
            } else {
                $path = app(\app\common\service\plugin\registry\PluginFrontTemplateRegistry::class)->resolveSiteTemplate($template . '.php', $theme);
            }
        }
        if ($path === '' || !is_file($path)) {
            TemplateEngineState::$memberTemplateRender = false;

            return '<!-- template not found: ' . htmlspecialchars($template) . ' -->';
        }

        if ($useParseCache && !$memberRender) {
            $cachedHtml = $this->tryParseCacheHit($theme, $path, $pageVars);
            if ($cachedHtml !== null) {
                return $cachedHtml;
            }
        }

        $raw = app(TemplateMetaService::class)->stripLeadMeta((string) file_get_contents($path));
        if (str_contains($raw, '<?php')) {
            TemplateEngineState::$memberTemplateRender = false;
            throw new \RuntimeException('模板禁止 PHP 代码，请使用 {pv:*} 标签：' . $template);
        }
        $html = app(TemplateCompileCacheService::class)->remember($path, $raw);

        app(TemplateTagdocumentsPrefetch::class)->warmFromHtml($html, $theme);
        $vars = array_merge(app(TemplateSiteVars::class)->siteVars(), $pageVars);
        if ($memberRender) {
            $vars = array_merge($vars, FrontVendorAsset::memberTemplateVars(app(ThemeService::class)->getCurrentMemberTheme()));
        }
        if (!empty($vars['seo_title'])) {
            $vars['seo_title'] = app(\app\common\service\seo\SeoTitleService::class)->finalizeFromPageVars($vars);
        }
        $vars = array_merge($vars, app(TemplateSiteVars::class)->buildSeoMetaVars($vars));

        $html = app(TemplateTagParser::class)->parseTags($html, $vars, $theme, true);
        TemplateEngineState::$memberTemplateRender = false;
        $html = app(TemplateTagParser::class)->applyPageVars($html, $vars);
        $html = $this->injectDeferredFrontAssets($html);

        if ($useParseCache && !$memberRender) {
            app(TemplateParseCacheService::class)->set(
                $theme,
                $path,
                $pageVars,
                app(TemplateParseCacheService::class)->neutralizeVolatileForCache($html, $vars)
            );
        }

        return $this->finalizeRenderedHtml(
            $this->finalizePublicHtml(
                app(TemplateMetaService::class)->sanitizeOutput($html),
                $theme
            )
        );
    }

    private function finalizeRenderedHtml(string $html): string
    {
        return app(\app\common\service\front\FrontPageMinifyService::class)->maybeOptimize($html);
    }

    private function finalizePublicHtml(string $html, string $theme): string
    {
        if (TemplateEngineState::$memberTemplateRender || $html === '') {
            return $html;
        }

        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $theme)) {
            return $html;
        }

        if (isset(self::$postRenderLibAbsent[$theme])) {
            return $html;
        }

        $real = self::$postRenderLibRealpath[$theme] ?? null;
        if ($real === null) {
            $lib = ProjectPaths::root() . 'template/' . $theme . '/lib/post_render.php';
            $realPath = realpath($lib);
            $themeLibRoot = realpath(ProjectPaths::root() . 'template/' . $theme . '/lib');
            if ($realPath === false || $themeLibRoot === false || !is_file($realPath)
                || !str_starts_with($realPath, $themeLibRoot . DIRECTORY_SEPARATOR)) {
                self::$postRenderLibAbsent[$theme] = true;

                return $html;
            }
            self::$postRenderLibRealpath[$theme] = $realPath;
            $real = $realPath;
        }

        require_once $real;

        return function_exists('theme_post_render_public_html')
            ? theme_post_render_public_html($html)
            : $html;
    }

    /**
     * parse 缓存命中：跳过读模板源文件与 compile 阶段。
     *
     * @param array<string, mixed> $pageVars
     */
    private function tryParseCacheHit(string $theme, string $path, array $pageVars): ?string
    {
        $cached = app(TemplateParseCacheService::class)->get($theme, $path, $pageVars);
        if ($cached === null) {
            return null;
        }

        TemplateEngineState::$memberTemplateRender = false;

        return $this->finalizeRenderedHtml(
            $this->finalizePublicHtml(
                app(TemplateMetaService::class)->sanitizeOutput(
                    app(TemplateParseCacheService::class)->restoreVolatileFromCache(
                        $cached,
                        app(TemplateSiteVars::class)->volatileSiteVars()
                    )
                ),
                $theme
            )
        );
    }

    /**
     * footer include 中的 {pv:frontassets} 往往早于正文插件标签执行，解析结束后补注入本页登记的脚本。
     */
    private function injectDeferredFrontAssets(string $html): string
    {
        $assets = app(\app\common\service\front\FrontAssetRegistry::class)->renderHtml();
        if ($assets === '') {
            return $html;
        }
        if (stripos($html, '</body>') === false) {
            return $html . $assets;
        }

        return preg_replace('/<\/body>/i', $assets . "\n</body>", $html, 1) ?? ($html . $assets);
    }

    public function siteVars(): array
    {
        return app(TemplateSiteVars::class)->siteVars();
    }

    public function registerPluginTag(string $name, callable $handler): void
    {
        app(TemplateEngineState::class)->registerPluginTag($name, $handler);
    }

    public function registerKernelTag(string $name, callable $handler): void
    {
        app(TemplateEngineState::class)->registerKernelTag($name, $handler);
    }

    public function ensureExtensionTags(): void
    {
        app(TemplateEngineState::class)->ensureExtensionTags();
    }

    public function registeredExtensionTagNames(): array
    {
        return app(TemplateEngineState::class)->registeredExtensionTagNames();
    }

    public function resetExtensionTags(): void
    {
        app(TemplateEngineState::class)->resetExtensionTags();
    }

    /** @deprecated 别名：旧测例/文档写 resetPluginTags，语义同 resetExtensionTags */
    public function resetPluginTags(): void
    {
        $this->resetExtensionTags();
    }

    public function parseTags(string $html, array $pageVars = [], ?string $theme = null, bool $precompiled = false): string
    {
        return app(TemplateTagParser::class)->parseTags($html, $pageVars, $theme, $precompiled);
    }

    public function stripRemainingPvTags(string $html): string
    {
        return app(TemplateTagParser::class)->stripRemainingPvTags($html);
    }

    public function applyPageVars(string $html, array $vars): string
    {
        return app(TemplateTagParser::class)->applyPageVars($html, $vars);
    }

    /**
     * 模板 HTTP 边界：将 query 注入 pageVars，供 ItemTemplateTagService 等只读 pageVars。
     *
     * @param array<string, mixed> $pageVars
     * @return array<string, mixed>
     */
    private function withRequestQuery(array $pageVars): array
    {
        if (!isset($pageVars['request_query']) || !is_array($pageVars['request_query'])) {
            $pageVars['request_query'] = Request::get();
        }

        return $pageVars;
    }
}
