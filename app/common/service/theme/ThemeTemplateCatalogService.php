<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\theme;
use app\common\service\theme\ThemeService;

use app\common\service\template\TemplateMetaService;
/**
 * 主题模板目录：按内容页 / 标签列表页分 scope，供后台 tpl_name 下拉展示中文说明。
 * 文件名 SSOT：{角色}_{实体}[_{变体}].php — 如 list_document、view_document、list_page_about。
 * 说明优先读 theme.json templates，其次模板文件头 <!-- pv:template ... -->（见 TemplateMetaService）。
 */
class ThemeTemplateCatalogService
{

    public function __construct(
        private readonly ThemeService $themeService,
    ) {
    }

    public const SCOPE_DOCUMENT = 'document';
    public const SCOPE_TAG      = 'tag';
    public const SCOPE_LIST     = 'list';
    public const SCOPE_PAGE     = 'page';
    public const SCOPE_HOME     = 'home';
    public const SCOPE_SYSTEM   = 'system';

    public const TPL_LIST_DOCUMENT        = 'list_document';
    public const TPL_VIEW_DOCUMENT        = 'view_document';
    public const TPL_LIST_PAGE            = 'list_page';
    public const TPL_LIST_TAG             = 'list_tag';
    public const TPL_LIST_SEARCH            = 'list_search';
    public const TPL_LIST_DOCUMENT_SEARCH   = 'list_document_search';
    public const TPL_LIST_CHANNEL         = 'list_channel';

    /** 单页模板：list_page（默认） / list_page_about（定制） */
    public const SITE_PAGE_PREFIX = 'list_page';

    /**
     * 产品频道橱窗列表模板（挂 Tag；前台走 data_contract；前缀 list_product_，禁止再用 list_page_）。
     *
     * @var list<string>
     */
    public const TAG_PRODUCT_SHOWCASE_PAGES = [
        'list_product_pricing.php',
        'list_product_plugins.php',
        'list_product_miniprogram.php',
        'list_product_templates.php',
    ];

    /**
     * 旧橱窗文件名 → 规范名（库内 tpl_name / 主题解析兼容）。
     *
     * @var array<string, string>
     */
    public const TAG_PRODUCT_SHOWCASE_LEGACY = [
        'list_page_pricing.php'          => 'list_product_pricing.php',
        'list_page_apps_plugins.php'     => 'list_product_plugins.php',
        'list_page_apps_miniprogram.php' => 'list_product_miniprogram.php',
        'list_page_templates.php'        => 'list_product_templates.php',
    ];

    /**
     * @return list<array{file:string,label:string,hint:string,title:string}>
     */
    public function listOptions(string $scope, ?string $theme = null): array
    {
        $scope = $this->normalizeScope($scope);
        $theme = $theme ?? $this->themeService->getCurrentTheme();
        $files = $this->scanFiles($scope, $theme);
        $options = [];

        if ($scope === self::SCOPE_TAG) {
            $options[] = [
                'file'  => '',
                'label' => '系统默认',
                'hint'  => 'list_document.php 标准列表',
                'title' => '系统默认 · 标准列表页',
            ];
        }

        $seen = [];
        foreach ($files as $file) {
            $seen[$file] = true;
            $meta      = $this->metaFor($file, $scope, $theme);
            $options[] = [
                'file'  => $file,
                'label' => $meta['label'],
                'hint'  => $meta['hint'],
                'title' => $meta['label'] . ' · ' . $file,
            ];
        }

        if ($scope === self::SCOPE_TAG) {
            foreach (self::TAG_PRODUCT_SHOWCASE_PAGES as $file) {
                if (isset($seen[$file])) {
                    continue;
                }
                if (!$this->themeService->siteTemplateExists($theme, $file)) {
                    continue;
                }
                $meta  = $this->metaFor($file, $scope, $theme);
                $label = $meta['label'] !== '' ? $meta['label'] : '授权对比';
                $hint  = $meta['hint'] !== '' ? $meta['hint'] : '产品频道橱窗页';
                $options[] = [
                    'file'  => $file,
                    'label' => $label,
                    'hint'  => $hint,
                    'title' => $label . ' · ' . $file,
                ];
            }
        }

        return $options;
    }

    public function isTagProductShowcaseTpl(string $tplFile): bool
    {
        $file = $this->toCanonicalFilename($tplFile);
        if ($file === '') {
            $bare = $this->stripPhpExt($tplFile);
            if ($bare === '') {
                return false;
            }
            $file = $bare . '.php';
        }

        return in_array($file, self::TAG_PRODUCT_SHOWCASE_PAGES, true)
            || isset(self::TAG_PRODUCT_SHOWCASE_LEGACY[$file]);
    }

    /**
     * Tag 频道类型：只认本目录橱窗/列表模板名，禁散落 str_contains('product')。
     *
     * @return 'product'|'page'|'document'
     */
    public function inferTagTopicChannel(string $tplFile): string
    {
        if ($this->isTagProductShowcaseTpl($tplFile)) {
            return 'product';
        }
        $base = strtolower($this->stripPhpExt($tplFile));
        if ($base === '') {
            return 'document';
        }
        if (str_starts_with($base, 'list_product')) {
            return 'product';
        }
        if (str_starts_with($base, 'list_page')) {
            return 'page';
        }

        return 'document';
    }

    /**
     * @return list<string>
     */
    public function listFiles(string $scope, ?string $theme = null): array
    {
        $files = [];
        foreach ($this->listOptions($scope, $theme) as $row) {
            if ($row['file'] !== '') {
                $files[] = $row['file'];
            }
        }

        return $files;
    }

    public function normalizeScopePublic(string $scope): string
    {
        return $this->normalizeScope($scope);
    }

    public function matchesScope(string $filename, string $scope): bool
    {
        $scope = $this->normalizeScope($scope);
        $base  = $this->stripPhpExt($filename);

        if ($scope === self::SCOPE_DOCUMENT) {
            if (preg_match('/^view_(document|item)(?:_|$)/', $base)) {
                return true;
            }

            return (bool) preg_match('/^document_(?!list\b)[a-zA-Z0-9_\-]*$/i', $base);
        }

        if (in_array($base, [
            self::TPL_LIST_TAG,
            'tags_index',
            'tags',
            self::TPL_LIST_SEARCH,
            self::TPL_LIST_DOCUMENT_SEARCH,
            'document_list_search',
            'search',
            self::TPL_LIST_PAGE,
        ], true) || str_starts_with($base, self::SITE_PAGE_PREFIX . '_')) {
            return false;
        }

        if (preg_match('/^list_(document|channel|product|item)(?:_|$)/', $base)) {
            return true;
        }

        return (bool) preg_match('/^(document_list|channel_landing|tag_list)[a-zA-Z0-9_\-]*$/i', $base);
    }

    /**
     * 旧 tpl 名 → 规范 basename（不含 .php）。
     */
    public function toCanonicalBasename(string $name): string
    {
        $base = $this->stripPhpExt($name);
        if ($base === '') {
            return '';
        }

        $legacyShowcase = self::TAG_PRODUCT_SHOWCASE_LEGACY[$base . '.php'] ?? '';
        if ($legacyShowcase !== '') {
            return $this->stripPhpExt($legacyShowcase);
        }

        if (preg_match('/^list_(document|page|tag|channel|product|item)(?:_.+)?$/', $base)) {
            return $base;
        }
        if (preg_match('/^view_(document|item)(?:_.+)?$/', $base)) {
            return $base;
        }
        if (preg_match('/^document_list(?:_(.+))?$/', $base, $m)) {
            return self::TPL_LIST_DOCUMENT . (isset($m[1]) && $m[1] !== '' ? '_' . $m[1] : '');
        }
        if (preg_match('/^document_view(?:_(.+))?$/', $base, $m)) {
            return self::TPL_VIEW_DOCUMENT . (isset($m[1]) && $m[1] !== '' ? '_' . $m[1] : '');
        }
        if (preg_match('/^channel_landing(?:_(.+))?$/', $base, $m)) {
            return self::TPL_LIST_CHANNEL . (isset($m[1]) && $m[1] !== '' ? '_' . $m[1] : '');
        }
        if (preg_match('/^page_(.+)$/', $base, $m)) {
            return self::SITE_PAGE_PREFIX . '_' . $m[1];
        }
        if ($base === 'page') {
            return self::TPL_LIST_PAGE;
        }
        if (in_array($base, ['tags_index', 'tags'], true)) {
            return self::TPL_LIST_TAG;
        }
        if (in_array($base, ['document_list_search', 'search', 'list_search', 'list_document_search'], true)) {
            return self::TPL_LIST_SEARCH;
        }
        if (in_array($base, ['docs', 'docs_hub', 'docs-hub'], true)) {
            return 'list_page_docs';
        }
        if ($base === 'tag_list') {
            return 'list_document_tag';
        }

        return $base;
    }

    /**
     * 规范 basename 对应的旧主题文件名（不含 .php）。
     *
     * @return list<string>
     */
    public function legacyBasenames(string $canonical): array
    {
        $canonical = $this->stripPhpExt($canonical);
        $legacy    = [];

        if (preg_match('/^list_document(?:_(.+))?$/', $canonical, $m)) {
            $suffix   = $m[1] ?? '';
            $legacy[] = $suffix === '' ? 'document_list' : 'document_list_' . $suffix;
        } elseif (preg_match('/^view_document(?:_(.+))?$/', $canonical, $m)) {
            $suffix   = $m[1] ?? '';
            $legacy[] = $suffix === '' ? 'document_view' : 'document_view_' . $suffix;
        } elseif (preg_match('/^list_channel(?:_(.+))?$/', $canonical, $m)) {
            $suffix   = $m[1] ?? '';
            $legacy[] = $suffix === '' ? 'channel_landing' : 'channel_landing_' . $suffix;
        } elseif (preg_match('/^list_product(?:_(.+))?$/', $canonical)) {
            foreach (self::TAG_PRODUCT_SHOWCASE_LEGACY as $oldFile => $newFile) {
                if ($this->stripPhpExt($newFile) === $canonical) {
                    $legacy[] = $this->stripPhpExt($oldFile);
                }
            }
        } elseif (preg_match('/^list_page(?:_(.+))?$/', $canonical, $m)) {
            $slug = $m[1] ?? '';
            if ($slug === '') {
                $legacy[] = 'page';
            } else {
                $legacy[] = 'page_' . $slug;
                $legacy[] = $slug;
            }
        } elseif ($canonical === self::TPL_LIST_TAG) {
            $legacy = ['tags_index', 'tags'];
        } elseif ($canonical === self::TPL_LIST_SEARCH || $canonical === self::TPL_LIST_DOCUMENT_SEARCH) {
            $legacy = ['document_list_search', 'search', 'list_document_search', 'list_search'];
        } elseif ($canonical === 'list_document_tag') {
            $legacy = ['tag_list'];
        }

        return $legacy;
    }

    /**
     * @return self::SCOPE_*|'legacy_page'|'unknown'
     */
    public function classifyBasename(string $basename): string
    {
        $base = $this->stripPhpExt($basename);
        if ($base === '') {
            return 'unknown';
        }
        if ($base === 'home') {
            return self::SCOPE_HOME;
        }
        if ($base === 'error') {
            return self::SCOPE_SYSTEM;
        }
        if ($base === self::TPL_LIST_TAG || in_array($base, ['tags_index', 'tags'], true)) {
            return self::SCOPE_LIST;
        }
        if ($base === self::TPL_LIST_SEARCH
            || $base === self::TPL_LIST_DOCUMENT_SEARCH
            || in_array($base, ['document_list_search', 'search'], true)) {
            return self::SCOPE_LIST;
        }
        if (preg_match('/^list_document(?:_|$)/', $base)
            && $base !== self::TPL_LIST_DOCUMENT_SEARCH
            && $base !== self::TPL_LIST_SEARCH) {
            return self::SCOPE_TAG;
        }
        if (preg_match('/^list_channel(?:_|$)/', $base)) {
            return self::SCOPE_TAG;
        }
        if (preg_match('/^list_product(?:_|$)/', $base)) {
            return self::SCOPE_TAG;
        }
        if (preg_match('/^list_item(?:_|$)/', $base)) {
            return self::SCOPE_TAG;
        }
        if ($base === 'list_document_tag' || $base === 'tag_list') {
            return self::SCOPE_TAG;
        }
        if (preg_match('/^view_document(?:_|$)/', $base)) {
            return self::SCOPE_DOCUMENT;
        }
        if (preg_match('/^view_item(?:_|$)/', $base)) {
            return self::SCOPE_DOCUMENT;
        }
        if (preg_match('/^document_list/', $base) || preg_match('/^(channel_landing|tag_list)/', $base)) {
            return self::SCOPE_TAG;
        }
        if (preg_match('/^document_view/', $base)) {
            return self::SCOPE_DOCUMENT;
        }
        // 旧橱窗名尚未 canonicalize 时勿当单页
        if (isset(self::TAG_PRODUCT_SHOWCASE_LEGACY[$base . '.php'])) {
            return self::SCOPE_TAG;
        }
        if ($base === self::TPL_LIST_PAGE || str_starts_with($base, self::SITE_PAGE_PREFIX . '_')) {
            return self::SCOPE_PAGE;
        }
        if (str_starts_with($base, 'page_')) {
            return self::SCOPE_PAGE;
        }
        if (preg_match('/^[a-z][a-z0-9_\-]*$/', $base)) {
            return 'legacy_page';
        }

        return 'unknown';
    }

    public function isReservedRootTemplate(string $basename): bool
    {
        return match ($this->classifyBasename($basename)) {
            self::SCOPE_HOME,
            self::SCOPE_SYSTEM,
            self::SCOPE_LIST,
            self::SCOPE_DOCUMENT,
            self::SCOPE_TAG => true,
            default => false,
        };
    }

    public function isSitePageCandidate(string $basename): bool
    {
        $kind = $this->classifyBasename($basename);

        return $kind === self::SCOPE_PAGE || $kind === 'legacy_page';
    }

    /**
     * @param list<string> $legacy
     */
    public function resolveThemeBasename(string $preferred, array $legacy = [], ?string $theme = null): string
    {
        $preferred = $this->toCanonicalBasename($preferred);
        $candidates = array_values(array_unique(array_filter(
            array_merge([$preferred], $legacy, $this->legacyBasenames($preferred)),
            static fn (string $name): bool => trim($name) !== ''
        )));
        foreach ($this->themeResolutionOrder($theme) as $themeId) {
            foreach ($candidates as $base) {
                $base = $this->stripPhpExt($base);
                if ($base !== '' && $this->themeService->siteTemplateExists($themeId, $base . '.php')) {
                    return $base;
                }
            }
        }

        return $preferred;
    }

    /** @return list<string> */
    private function themeResolutionOrder(?string $theme): array
    {
        return $this->themeService->siteTemplateSearchThemes($theme);
    }

    public function systemTagsIndexTpl(?string $theme = null): string
    {
        return $this->resolveThemeBasename(self::TPL_LIST_TAG, [], $theme);
    }

    public function systemSearchTpl(?string $theme = null): string
    {
        return $this->resolveThemeBasename(self::TPL_LIST_SEARCH, [self::TPL_LIST_DOCUMENT_SEARCH], $theme);
    }

    public function defaultListDocumentTpl(?string $theme = null): string
    {
        return $this->resolveThemeBasename(self::TPL_LIST_DOCUMENT, [], $theme);
    }

    public function resolveTagListTpl(string $tplFile, ?string $theme = null): string
    {
        $canonical = $this->toCanonicalBasename($tplFile);
        if ($this->isTagProductShowcaseTpl($canonical !== '' ? $canonical . '.php' : $tplFile)) {
            return $this->resolveThemeBasename(
                $canonical !== '' ? $canonical : $this->stripPhpExt($tplFile),
                [],
                $theme
            );
        }

        // 易优迁入的 lists_* / list_* 变体：主题盘上有文件则原样用，禁止一律打成 list_document
        $bare = $this->stripPhpExt($tplFile);
        if ($bare !== '' && $this->themeHasListTplFile($bare, $theme)) {
            return $this->resolveThemeBasename($bare, [], $theme);
        }

        if ($canonical === '' || !preg_match('/^list_(document|channel|product|page|item)/', $canonical)) {
            $canonical = self::TPL_LIST_DOCUMENT;
        }

        $legacy = [];
        if (preg_match('/^list_document_[a-z0-9_\-]+$/', $canonical)) {
            $legacy[] = self::TPL_LIST_DOCUMENT;
        }
        if (preg_match('/^list_channel_[a-z0-9_\-]+$/', $canonical)) {
            $legacy[] = self::TPL_LIST_CHANNEL;
        }

        return $this->resolveThemeBasename($canonical, $legacy, $theme);
    }

    /** 主题 pc/ 下是否存在该列表模板（含 lists_article 等迁入名） */
    private function themeHasListTplFile(string $bare, ?string $theme = null): bool
    {
        $bare = trim($bare);
        if ($bare === '' || str_contains($bare, '..') || str_contains($bare, '/') || str_contains($bare, '\\')) {
            return false;
        }
        // 仅放行列表向文件名，避免误把 view_/partials 当列表
        if (!preg_match('/^(lists?|list)_/i', $bare)) {
            return false;
        }

        return $this->themeService->siteTemplateExistsWithFallback($bare . '.php', $theme);
    }

    public function resolveDocumentViewTpl(string $tplFile, ?string $theme = null): string
    {
        $bare      = $this->stripPhpExt($tplFile);
        $canonical = $this->toCanonicalBasename($tplFile);
        if ($bare !== '' && preg_match('/^article[a-z0-9_\-]*$/', $bare)) {
            $canonical = $bare;
        } elseif ($canonical === '' || !str_starts_with($canonical, 'view_')) {
            $canonical = self::TPL_VIEW_DOCUMENT;
        }

        return $this->resolveThemeBasename($canonical, [], $theme);
    }

    /**
     * 文档/品项内容页模板下拉（view_document* / view_item* / view_apps*）。
     *
     * @return list<array{file:string,label:string,hint:string,title:string}>
     */
    public function listContentViewOptions(?string $theme = null): array
    {
        $theme = $theme ?? $this->themeService->getCurrentTheme();
        $dir   = $this->themeService->siteTemplateScanDir($theme) . DIRECTORY_SEPARATOR;
        $files = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..' || !is_file($dir . $name)) {
                    continue;
                }
                if (!str_ends_with(strtolower($name), '.php')) {
                    continue;
                }
                $base = $this->stripPhpExt($name);
                if (
                    preg_match('/^view_document(?:_|$)/', $base)
                    || preg_match('/^view_item(?:_|$)/', $base)
                    || preg_match('/^view_apps(?:_|$)/', $base)
                ) {
                    $files[] = $name;
                }
            }
        }
        sort($files);

        $options = [[
            'file'  => '',
            'label' => '系统默认',
            'hint'  => '文档 view_document · 品项 view_item',
            'title' => '系统默认 · 按内容类型回退',
        ]];
        foreach ($files as $file) {
            $scope = preg_match('/^view_document/', $this->stripPhpExt($file))
                ? self::SCOPE_DOCUMENT
                : self::SCOPE_DOCUMENT;
            $meta = $this->metaFor($file, $scope, $theme);
            $options[] = [
                'file'  => $file,
                'label' => $meta['label'],
                'hint'  => $meta['hint'],
                'title' => $meta['label'] . ' · ' . $file,
            ];
        }

        return $options;
    }

    public function resolveContentViewTpl(string $tplFile, string $fallbackBare = self::TPL_VIEW_DOCUMENT, ?string $theme = null): string
    {
        $bare = $this->stripPhpExt($tplFile);
        if ($bare === '') {
            $bare = $this->stripPhpExt($fallbackBare);
        }
        if ($bare === '') {
            $bare = self::TPL_VIEW_DOCUMENT;
        }
        if (str_starts_with($bare, 'view_')) {
            return $this->resolveThemeBasename($bare, [], $theme);
        }

        return $this->resolveDocumentViewTpl($tplFile !== '' ? $tplFile : $fallbackBare . '.php', $theme);
    }

    /**
     * 文档详情模板：正文 tpl_name → 主标签内容页模板 → 列表模板推断 → 系统默认。
     *
     * @param array<string, mixed> $detail
     */
    public function resolveDocumentViewTplForDetail(
        array $detail,
        ?string $primaryTagListTpl = null,
        ?string $theme = null,
        ?string $primaryTagViewTpl = null,
    ): string {
        $explicit = trim((string) ($detail['tpl_name'] ?? ''));
        if ($explicit !== '') {
            return $this->resolveDocumentViewTpl($explicit, $theme);
        }

        $tagView = trim((string) ($primaryTagViewTpl ?? ''));
        if ($tagView !== '') {
            return $this->resolveContentViewTpl($tagView, self::TPL_VIEW_DOCUMENT, $theme);
        }

        $listTpl = strtolower(trim((string) ($primaryTagListTpl ?? '')));
        if (str_contains($listTpl, 'list_item') || str_contains($listTpl, 'lists_product') || str_contains($listTpl, 'list_document_product')) {
            return $this->resolveContentViewTpl('view_item.php', 'view_item', $theme);
        }
        if (str_contains($listTpl, 'list_document_download')) {
            return $this->resolveDocumentViewTpl('view_document_download.php', $theme);
        }
        if (str_contains($listTpl, 'list_document_video')) {
            return $this->resolveDocumentViewTpl('view_document_video.php', $theme);
        }

        return $this->resolveDocumentViewTpl('', $theme);
    }

    public function resolveSitePageTpl(string $tplName, ?string $theme = null): string
    {
        $canonical = $this->toCanonicalBasename($tplName);

        return $this->resolveThemeBasename($canonical, [], $theme);
    }

    public function toCanonicalFilename(string $name): string
    {
        $canonical = $this->toCanonicalBasename($name);

        return $canonical !== '' ? $canonical . '.php' : '';
    }

    /**
     * @return list<string>
     */
    private function scanFiles(string $scope, string $theme): array
    {
        $dir = $this->themeService->siteTemplateScanDir($theme) . DIRECTORY_SEPARATOR;
        $files = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..' || !is_file($dir . $name)) {
                    continue;
                }
                if (!str_ends_with(strtolower($name), '.php')) {
                    continue;
                }
                if ($this->fileBelongsToScope($dir . $name, $name, $scope)) {
                    $files[] = $name;
                }
            }
        }

        if ($files === []) {
            $files = $scope === self::SCOPE_DOCUMENT ? ['view_document.php'] : ['list_document.php'];
        }

        sort($files);

        return $files;
    }

    private function fileBelongsToScope(string $path, string $filename, string $scope): bool
    {
        $metaScope = app(TemplateMetaService::class)->readFromPath($path)['scope'];
        if ($metaScope !== '') {
            return $this->normalizeScope($metaScope) === $scope;
        }

        return $this->matchesScope($filename, $scope);
    }

    /**
     * @return array{label:string, hint:string}
     */
    private function metaFor(string $file, string $scope, string $theme): array
    {
        $path = $this->themeService->resolveSiteTemplatePath($theme, $file);
        if ($path === '') {
            $path = ROOT_PATH . 'template' . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR . $file;
        }
        $fileMeta = app(TemplateMetaService::class)->readFromPath($path);
        $label = trim($fileMeta['label']);
        $hint  = trim($fileMeta['hint']);
        if ($label !== '') {
            return ['label' => $label, 'hint' => $hint];
        }

        return $this->guessMeta($file, $scope);
    }

    /**
     * @return array{label:string, hint:string}
     */
    private function guessMeta(string $file, string $scope): array
    {
        $base = $this->stripPhpExt($file);

        if ($scope === self::SCOPE_DOCUMENT) {
            $suffix = preg_replace('/^view_document_/i', '', preg_replace('/^view_document$/i', 'view', $base) ?? $base) ?? $base;
            if ($suffix === 'view') {
                return ['label' => '纯文章内容页', 'hint' => '请在模板文件头添加 pv:template 说明'];
            }

            return [
                'label' => $this->humanizeSlug($suffix) . '内容页',
                'hint'  => '请在模板文件头添加 pv:template 说明',
            ];
        }

        if (str_starts_with(strtolower($base), 'list_channel') || str_starts_with(strtolower($base), 'channel_landing')) {
            return ['label' => '频道首页', 'hint' => '请在模板文件头添加 pv:template 说明'];
        }
        if (str_starts_with(strtolower($base), 'list_document_tag') || str_starts_with(strtolower($base), 'tag_list')) {
            return ['label' => '纯文版列表页', 'hint' => '请在模板文件头添加 pv:template 说明'];
        }

        $slug = preg_replace('/^list_document_/i', '', preg_replace('/^list_document$/i', '', preg_replace('/^document_list_/i', '', $base) ?? $base) ?? $base) ?? $base;

        return [
            'label' => $this->humanizeSlug($slug !== '' ? $slug : '标准') . '列表页',
            'hint'  => '请在模板文件头添加 pv:template 说明',
        ];
    }

    private function humanizeSlug(string $slug): string
    {
        $slug = str_replace(['-', '_'], ' ', strtolower($slug));

        return $slug !== '' ? $slug : '自定义';
    }

    private function stripPhpExt(string $name): string
    {
        return preg_replace('/\.php$/i', '', strtolower(trim($name))) ?? '';
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if (in_array($scope, ['tag', 'channel', 'topic'], true)) {
            return self::SCOPE_TAG;
        }
        if (in_array($scope, ['list'], true)) {
            return self::SCOPE_LIST;
        }
        if (in_array($scope, ['page', 'site_page', 'sitepage'], true)) {
            return self::SCOPE_PAGE;
        }
        if (in_array($scope, ['home', 'index'], true)) {
            return self::SCOPE_HOME;
        }
        if (in_array($scope, ['system', 'error'], true)) {
            return self::SCOPE_SYSTEM;
        }

        return self::SCOPE_DOCUMENT;
    }
}
