<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** Slug 生成与校验 SSOT（标签 / 品项 / 插件标识等共用基础规则） */
final class SlugHelper
{
    public const SLUG_TOKEN_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /**
     * 由可读文本生成 ASCII slug（不含唯一性后缀）。
     * 含汉字时优先 ICU 转写为拼音式拉丁文；仅当转写不可用或结果为空时才退回 prefix-md5。
     */
    public static function asciiFromText(string $text, string $fallbackPrefix = 'item', int $md5Len = 8): string
    {
        $text = trim($text);
        $hasHan = $text !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $text) === 1;
        $slug = self::sanitizeAsciiToken($text);

        if ($hasHan || $slug === '') {
            $latin = self::transliterateToLatin($text);
            if ($latin !== '') {
                $fromLatin = self::sanitizeAsciiToken($latin);
                if ($fromLatin !== '') {
                    $slug = $fromLatin;
                }
            }
        }

        if ($slug === '') {
            $seed = $text !== '' ? $text : $fallbackPrefix;
            $slug = $fallbackPrefix . '-' . substr(md5($seed), 0, max(4, $md5Len));
        }

        return substr($slug, 0, 90);
    }

    /**
     * 在 base 上追加 -N 后缀直至 exists 返回 false。
     *
     * @param callable(string): bool $exists
     */
    public static function ensureUnique(string $base, callable $exists, int $maxLen = 100): string
    {
        $base = substr(trim($base, '-'), 0, max(1, $maxLen - 4));
        if ($base === '') {
            $base = 'item';
        }
        $slug = $base;
        $n    = 1;
        while ($exists($slug)) {
            $slug = substr($base, 0, max(1, $maxLen - 4)) . '-' . $n++;
        }

        return substr($slug, 0, $maxLen);
    }

    public static function isValidToken(string $slug): bool
    {
        return $slug !== '' && preg_match(self::SLUG_TOKEN_PATTERN, $slug) === 1;
    }

    /**
     * @param  list<mixed>|string  $items
     * @return list<string>
     */
    public static function filterValidTokens(mixed $items, int $max = 20, int $maxLen = 64): array
    {
        if (is_string($items)) {
            $items = preg_split('/[\s,，;；]+/', $items) ?: [];
        }
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (count($out) >= $max) {
                break;
            }
            $slug = strtolower(trim((string) $item));
            if (strlen($slug) > $maxLen) {
                continue;
            }
            if (self::isValidToken($slug)) {
                $out[] = $slug;
            }
        }

        return $out;
    }

    private static function sanitizeAsciiToken(string $text): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $text) ?? '', '-'));
    }

    private static function transliterateToLatin(string $text): string
    {
        if ($text === '' || !extension_loaded('intl')) {
            return '';
        }
        $transliterator = transliterator_create('Any-Latin; Latin-ASCII; Lower()');
        if ($transliterator === null) {
            return '';
        }
        $out = $transliterator->transliterate($text);

        return is_string($out) ? $out : '';
    }
}
