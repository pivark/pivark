<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\front;

use app\common\service\config\ConfigService;

/**
 * 前台 HTML / 内联 CSS·JS 源码 Minify（用户可选；外链静态资源仍靠构建或 Nginx）。
 */
final class FrontPageMinifyService
{

    public const KEY_HTML       = 'front_minify_html_on';
    public const KEY_INLINE_CSS = 'front_minify_inline_css_on';
    public const KEY_INLINE_JS  = 'front_minify_inline_js_on';

    /** @var list<string> */
    public const CONFIG_KEYS = [
        self::KEY_HTML,
        self::KEY_INLINE_CSS,
        self::KEY_INLINE_JS,
    ];

    public function maybeOptimize(string $html): string
    {
        if ($html === '' || !$this->anyEnabled()) {
            return $html;
        }

        if ($this->isOn(self::KEY_INLINE_CSS)) {
            $html = $this->minifyTaggedBlocks($html, 'style', static fn (string $body): string => self::minifyCss($body));
        }
        if ($this->isOn(self::KEY_INLINE_JS)) {
            $html = $this->minifyTaggedBlocks($html, 'script', fn (string $body, string $openTag): string => $this->minifyScriptBlock($body, $openTag));
        }
        if ($this->isOn(self::KEY_HTML)) {
            $html = $this->minifyHtmlDocument($html);
        }

        return $html;
    }

    public function anyEnabled(): bool
    {
        foreach (self::CONFIG_KEYS as $key) {
            if ($this->isOn($key)) {
                return true;
            }
        }

        return false;
    }

    public function isOn(string $key): bool
    {
        return filter_var(app(ConfigService::class)->get($key, '0'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param callable(string): string $minifier
     */
    private function minifyTaggedBlocks(string $html, string $tag, callable $minifier): string
    {
        $pattern = '/<' . $tag . '\b([^>]*)>(.*?)<\/' . $tag . '>/is';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($minifier, $tag): string {
                $open = (string) ($m[1] ?? '');
                $body = (string) ($m[2] ?? '');
                if ($tag === 'script' && !self::isMinifiableScriptOpenTag($open)) {
                    return $m[0];
                }
                $optimized = $tag === 'script'
                    ? $minifier($body, $open)
                    : $minifier($body);

                return '<' . $tag . $open . '>' . $optimized . '</' . $tag . '>';
            },
            $html
        );
    }

    private function minifyScriptBlock(string $body, string $openTag): string
    {
        if (!self::isMinifiableScriptOpenTag($openTag)) {
            return $body;
        }

        return self::minifyJs($body);
    }

    private static function isMinifiableScriptOpenTag(string $openTag): bool
    {
        if (!preg_match('/\btype\s*=\s*(["\'])(.*?)\1/i', $openTag, $m)) {
            return true;
        }
        $type = strtolower(trim($m[2]));
        if ($type === '' || $type === 'text/javascript' || $type === 'application/javascript' || $type === 'module') {
            return true;
        }

        return false;
    }

    private function minifyHtmlDocument(string $html): string
    {
        $placeholders = [];
        $index        = 0;
        $patterns     = [
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<style\b[^>]*>.*?<\/style>/is',
            '/<pre\b[^>]*>.*?<\/pre>/is',
            '/<textarea\b[^>]*>.*?<\/textarea>/is',
        ];

        foreach ($patterns as $pattern) {
            $html = (string) preg_replace_callback(
                $pattern,
                static function (array $matches) use (&$placeholders, &$index): string {
                    $token                  = '%%PIVMIN' . $index . '%%';
                    $placeholders[$token] = $matches[0];
                    $index++;

                    return $token;
                },
                $html
            );
        }

        $html = (string) preg_replace('/<!--(?!\s*(?:\[if|<!|\])).*?-->/s', '', $html);
        $html = (string) preg_replace('/\s{2,}/', ' ', $html);
        $html = (string) preg_replace('/>\s+</', '><', $html);

        foreach ($placeholders as $token => $block) {
            $html = str_replace($token, $block, $html);
        }

        return trim($html);
    }

    private static function minifyCss(string $css): string
    {
        $css = (string) preg_replace('/\/\*.*?\*\//s', '', $css);
        $css = (string) preg_replace('/\s+/', ' ', $css);
        $css = (string) preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css);

        return trim($css);
    }

    private static function minifyJs(string $js): string
    {
        $js = (string) preg_replace('/\/\*.*?\*\//s', '', $js);
        $js = (string) preg_replace('/^\s*\/\/.*$/m', '', $js);
        $js = (string) preg_replace('/\s+/', ' ', $js);

        return trim($js);
    }
}
