<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 富文本 HTML 基础消毒（去脚本与事件属性，保留常见排版标签） */
class HtmlSanitizer
{
    private const ALLOWED_TAGS =
        '<p><br><hr><strong><b><em><i><u><s><h1><h2><h3><h4><h5><h6>'
        . '<ul><ol><li><a><img><blockquote><pre><code><table><thead><tbody><tr><th><td>'
        . '<div><span><figure><figcaption><article><section><button>';

    /**
     * @param string $html ԭʼ HTML
     * @return string 消毒后 HTML
     */
    public static function cleanArticle(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html) ?? '';
        $html = preg_replace('#<object\b[^>]*>.*?</object>#is', '', $html) ?? '';
        $html = preg_replace('#<embed\b[^>]*/?>#is', '', $html) ?? '';
        $html = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('#\s(src|href)\s*=\s*([\'"])\s*data:[^\2]*\2#i', '', $html) ?? '';
        $html = strip_tags($html, self::ALLOWED_TAGS);
        return trim($html);
    }

    /**
     * @param string $text 纯文本字段
     * @param int    $max  最大长度，0 不截断
     */
    public static function cleanPlainText(string $text, int $max = 0): string
    {
        $text = trim(strip_tags($text));
        if ($text === '') {
            return '';
        }
        if ($max > 0) {
            $text = mb_substr($text, 0, $max);
        }
        return $text;
    }

    /**
     * @param string $url 图片/链接 URL
     */
    public static function cleanUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^(https?://|/)[^\s<>"\']+#i', $url) === 1) {
            return $url;
        }
        return '';
    }

    /**
     * 从正文 HTML / Markdown 抽第一张可用图 URL（作封面 litpic；跳过 data:）
     */
    public static function extractFirstImageUrl(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        if (preg_match_all('/<img\b[^>]*\b(?:src|data-src)\s*=\s*("|\')((?:(?!\1).)+)\1/i', $content, $matches) > 0) {
            foreach ($matches[2] as $raw) {
                $normalized = self::normalizeCoverImageUrl(html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        if (preg_match_all('/!\[[^\]]*]\(([^)]+)\)/', $content, $mdMatches) > 0) {
            foreach ($mdMatches[1] as $inside) {
                $part = trim((string) $inside);
                if ($part === '') {
                    continue;
                }
                $url = preg_split('/\s+/', $part)[0] ?? '';
                $url = trim($url, "\"'");
                $normalized = self::normalizeCoverImageUrl($url);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return '';
    }

    private static function normalizeCoverImageUrl(string $input): string
    {
        $url = trim($input);
        if ($url === '' || str_starts_with($url, 'data:')) {
            return '';
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            $parts = parse_url($url);
            if (!is_array($parts)) {
                return '';
            }
            $path = (string) ($parts['path'] ?? '');
            if (str_contains($path, '/uploads/')) {
                $query = isset($parts['query']) ? '?' . $parts['query'] : '';

                return self::cleanUrl($path . $query);
            }

            return self::cleanUrl($url);
        }
        if (str_starts_with($url, 'uploads/')) {
            $url = '/' . $url;
        }

        return self::cleanUrl($url);
    }
}
