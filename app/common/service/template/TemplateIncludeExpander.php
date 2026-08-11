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

final class TemplateIncludeExpander
{

    private const MAX_DEPTH = 16;

    /**
     * 将 include 片段递归拼接为一段 HTML，供预取扫描 {pv:tagdocuments}
     */
    public function expandForPrefetch(string $html, ?string $theme = null): string
    {
        $theme ??= TemplateEngineState::$activeTheme ?? app(ThemeService::class)->getCurrentTheme();
        /** @var array<string, true> $seen */
        $seen = [];

        return $this->expand($html, $theme, $seen, 0);
    }

    /**
     * @param array<string, true> $seen theme|relativePath
     */
    private function expand(string $html, string $theme, array &$seen, int $depth): string
    {
        if ($depth >= self::MAX_DEPTH) {
            return $html;
        }

        $expanded = preg_replace_callback(
            '/\{pv:include\b([^}]*)\}/i',
            function (array $m) use ($theme, &$seen, $depth): string {
                $attrs = app(TemplateTagParser::class)->parseAttrs($m[1]);
                $file  = trim((string) ($attrs['file'] ?? ''));
                if ($file === '' || str_contains($file, '..')) {
                    return '';
                }

                $key = $theme . "\0" . $file;
                if (isset($seen[$key])) {
                    return '';
                }
                $seen[$key] = true;

                $path = $this->resolvePartialPath($theme, $file);
                if ($path === '') {
                    return '';
                }

                $raw = app(TemplateMetaService::class)->stripLeadMeta((string) file_get_contents($path));
                if (str_contains($raw, '<?php')) {
                    return '';
                }
                $chunk = app(TemplateCompileCacheService::class)->remember($path, $raw);

                return $this->expand($chunk, $theme, $seen, $depth + 1);
            },
            $html
        );

        return $expanded ?? $html;
    }

    private function resolvePartialPath(string $theme, string $file): string
    {
        if (TemplateEngineState::$memberTemplateRender) {
            return app(ThemeService::class)->resolveMemberIncludePath($file);
        }

        $rel  = $file . '.php';
        $path = app(ThemeService::class)->resolveSiteTemplatePath($theme, $rel);
        if ($path !== '') {
            return $path;
        }

        return app(ThemeService::class)->resolveSiteTemplatePath(app(ThemeService::class)->defaultThemeId(), $rel);
    }
}
