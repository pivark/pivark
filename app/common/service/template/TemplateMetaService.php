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
use app\common\service\theme\ThemeTemplateCatalogService;
/**
 * 主题模板元数据：优先 theme.json templates，其次文件头 <!-- pv:template -->（渲染前剥离）。
 */
class TemplateMetaService
{

    public function __construct(
        private readonly ThemeService $themeService,
        private readonly ThemeTemplateCatalogService $themeTemplateCatalogService,
    ) {
    }

    private const HEAD_BYTES = 2048;

    /**
     * @return array{label:string, hint:string, scope:string}
     */
    public function readFromPath(string $path): array
    {
        if (!is_file($path)) {
            return $this->emptyMeta();
        }

        $resolved = $this->resolveThemeFileFromPath($path);
        if ($resolved !== null) {
            $jsonMeta = $this->themeService->readTemplateCatalogMeta($resolved['theme'], $resolved['file']);
            if ($jsonMeta['label'] !== '' || $jsonMeta['scope'] !== '') {
                return $this->finalizeMeta($jsonMeta);
            }
        }

        $head = (string) file_get_contents($path, false, null, 0, self::HEAD_BYTES);

        return $this->parseLeadMeta($head);
    }

    /**
     * @return array{label:string, hint:string, scope:string}
     */
    public function parseLeadMeta(string $content): array
    {
        if (!preg_match('/<!--\s*pv:template\b(.*?)(?:-->|\z)/si', $content, $m)) {
            return $this->emptyMeta();
        }

        $block = trim($m[1]);
        $meta  = $this->emptyMeta();

        if (preg_match_all('/\b(label|hint|scope)\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $block, $attrs, PREG_SET_ORDER)) {
            foreach ($attrs as $row) {
                $key = strtolower($row[1]);
                $val = trim($row[3] !== '' ? $row[3] : $row[4]);
                if ($val !== '') {
                    $meta[$key] = $val;
                }
            }
        }

        foreach (preg_split('/\r\n|\r|\n/', $block) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '-->')) {
                continue;
            }
            if (!preg_match('/^(label|hint|scope)\s*:\s*(.+)$/iu', $line, $kv)) {
                continue;
            }
            $key = strtolower($kv[1]);
            $val = trim($kv[2]);
            if ($val !== '') {
                $meta[$key] = $val;
            }
        }

        return $this->finalizeMeta($meta);
    }

    /**
     * @param array{label:string, hint:string, scope:string} $meta
     * @return array{label:string, hint:string, scope:string}
     */
    private function finalizeMeta(array $meta): array
    {
        if ($meta['scope'] !== '') {
            $meta['scope'] = $this->themeTemplateCatalogService->normalizeScopePublic($meta['scope']);
        }

        return $meta;
    }

    /**
     * @return array{theme:string, file:string}|null
     */
    private function resolveThemeFileFromPath(string $path): ?array
    {
        $norm = str_replace('\\', '/', $path);
        if (preg_match('#/template/([^/]+)/(?:pc|m|mobile)/([^/]+\.php)$#i', $norm, $m)) {
            return ['theme' => $m[1], 'file' => $m[2]];
        }
        if (preg_match('#/template/([^/]+)/([^/]+\.php)$#i', $norm, $m)) {
            return ['theme' => $m[1], 'file' => $m[2]];
        }

        return null;
    }

    public function stripUtf8Bom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        return $content;
    }

    public function sanitizeOutput(string $html): string
    {
        return $this->stripUtf8Bom($html);
    }

    public function stripLeadMeta(string $content): string
    {
        $content = $this->stripUtf8Bom($content);

        return (string) preg_replace('/^\s*<!--\s*pv:template\b.*?-->\s*/si', '', $content, 1);
    }

    /**
     * @return array{label:string, hint:string, scope:string}
     */
    private function emptyMeta(): array
    {
        return ['label' => '', 'hint' => '', 'scope' => ''];
    }
}
