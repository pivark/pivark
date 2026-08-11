<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\service\theme\ThemeService;
use app\common\support\ProjectPaths;

/**
 * 当前主题模板取数标签清单（扫包，非站长勾选）。
 *
 * @phpstan-type TagHit array{tag: string, attrs: array<string, string>, file: string, tpl: string}
 */
final class ThemeTemplateTagInventoryService
{
    /** 自拉取数标签（不含 list：读控制器 {$list}） */
    private const DATA_TAGS = [
        'arclist'      => true,
        'tagdocuments' => true,
        'nav'          => true,
        'navigation'   => true,
        'tagcloud'     => true,
        'breadcrumb'   => true,
        'tagnav'       => true,
    ];

    private const MAX_FILES = 400;

    public function __construct(
        private readonly ThemeService $themeService,
    ) {
    }

    /**
     * @return array{
     *   theme_id: string,
     *   templates: list<string>,
     *   tags: list<TagHit>
     * }
     */
    public function scanCurrent(?string $themeId = null): array
    {
        $themeId = $themeId ?? $this->themeService->getCurrentTheme();
        $themeId = trim($themeId);
        if ($themeId === '' || str_contains($themeId, '..') || str_contains($themeId, '/') || str_contains($themeId, '\\')) {
            return ['theme_id' => '', 'templates' => [], 'tags' => []];
        }

        $root = ProjectPaths::root() . 'template/' . $themeId;
        if (!is_dir($root)) {
            return ['theme_id' => $themeId, 'templates' => [], 'tags' => []];
        }

        $files = $this->listPhpFiles($root);
        $tags = [];
        $templates = [];

        foreach ($files as $abs) {
            $rel = $this->relFromThemeRoot($root, $abs);
            if ($rel === '') {
                continue;
            }
            $raw = @file_get_contents($abs);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            if (str_contains($raw, '<?php') && !preg_match('/\{pv:/i', $raw)) {
                continue;
            }
            $body = app(TemplateMetaService::class)->stripLeadMeta($raw);
            $compiled = app(TemplateTagTokenizer::class)->compileSource($body);
            try {
                $compiled = app(TemplateIncludeExpander::class)->expandForPrefetch($compiled, $themeId);
            } catch (\Throwable) {
                // include 展开失败仍扫本文件
            }
            $tpl = $this->tplBasename($rel);
            if ($tpl !== '') {
                $templates[$tpl] = true;
            }
            foreach ($this->extractDataTags($compiled, $rel, $tpl) as $hit) {
                $tags[] = $hit;
            }
        }

        if ($tags !== []) {
            $templates['home'] = true;
        }
        $tplList = array_keys($templates);
        sort($tplList);

        return [
            'theme_id'  => $themeId,
            'templates' => $tplList,
            'tags'      => $tags,
        ];
    }

    /**
     * 门禁/探针：对单段 HTML 抽取得数标签（不扫磁盘）。
     *
     * @return list<array{tag: string, attrs: array<string, string>, file: string, tpl: string}>
     */
    public function extractDataTagsFromHtml(string $html, string $file = 'pc/home.php', string $tpl = 'home'): array
    {
        $compiled = app(TemplateTagTokenizer::class)->compileSource(
            app(TemplateMetaService::class)->stripLeadMeta($html)
        );

        return $this->extractDataTags($compiled, $file, $tpl);
    }

    /**
     * @return list<string> absolute paths
     */
    private function listPhpFiles(string $root): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $out[] = $file->getPathname();
            if (count($out) >= self::MAX_FILES) {
                break;
            }
        }
        sort($out);

        return $out;
    }

    private function relFromThemeRoot(string $root, string $abs): string
    {
        $rootN = str_replace('\\', '/', rtrim($root, '/\\')) . '/';
        $absN = str_replace('\\', '/', $abs);
        if (!str_starts_with($absN, $rootN)) {
            return '';
        }

        return substr($absN, strlen($rootN));
    }

    private function tplBasename(string $rel): string
    {
        $base = basename(str_replace('\\', '/', $rel));
        if (!str_ends_with($base, '.php')) {
            return '';
        }
        $name = substr($base, 0, -4);
        if ($name === '' || str_starts_with($name, '_')) {
            return '';
        }
        // partials / includes 不进页暖列表
        $norm = str_replace('\\', '/', $rel);
        if (str_contains($norm, '/partials/') || str_contains($norm, '/include/')) {
            return '';
        }

        return $name;
    }

    /**
     * @return list<TagHit>
     */
    private function extractDataTags(string $html, string $file, string $tpl): array
    {
        $html = app(TemplateTagParser::class)->normalizeLegacyTagNames($html);
        if (!preg_match_all(
            '/\{pv:([a-z][a-z0-9_]*)\b((?:[^{}]|\{\$[a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)*\})*)\}/i',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($matches as $m) {
            $tag = strtolower((string) ($m[1] ?? ''));
            if ($tag === '' || !isset(self::DATA_TAGS[$tag])) {
                continue;
            }
            if ($tag === 'navigation') {
                $tag = 'nav';
            }
            if ($tag === 'tagdocuments') {
                $tag = 'arclist';
            }
            $attrs = app(TemplateTagParser::class)->parseAttrs((string) ($m[2] ?? ''));
            ksort($attrs);
            $key = $tag . ':' . hash('sha256', json_encode($attrs, JSON_UNESCAPED_UNICODE) . '|' . $file);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'tag'   => $tag,
                'attrs' => $attrs,
                'file'  => $file,
                'tpl'   => $tpl,
            ];
        }

        return $out;
    }
}
