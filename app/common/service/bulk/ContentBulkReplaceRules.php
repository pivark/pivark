<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\bulk;


use app\common\service\document\DocumentAttrHelper;
use app\common\model\Document;
use app\common\model\DocumentTag;
use app\common\model\SitePage;
use think\db\Query;
use app\common\support\HtmlSanitizer;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;

/** 批量查找替换：文档 + 可选单页，预览/分批执行/快照/审计 */

/** 规则规范化与字段替换 */
class ContentBulkReplaceRules
{


    public const MAX_SCAN           = 50000;
    public const BATCH_SIZE         = 100;
    public const PREVIEW_SNIPPETS   = 50;
    public const MAX_FIND_LEN       = 500;
    public const MAX_RULES          = 20;
    public const RATE_LIMIT_SECONDS = 60;

    /** @var array<string, string> */
    public const DOCUMENT_FIELDS = [
        'content'          => 'PC 正文',
        'content_mobile'   => '手机正文',
        'summary'          => '摘要',
        'subtitle'         => '副标题',
        'seo_title'        => 'SEO 标题',
        'seo_keywords'     => 'SEO 关键词',
        'seo_description'  => 'SEO 描述',
        'litpic'           => '缩略图 URL',
        'external_url'     => '外链 URL',
    ];

    /** @var list<string> */
    private const HTML_FIELDS = ['content', 'content_mobile'];

    /** @var list<string> */
    private const DEFAULT_FIELDS = ['content', 'content_mobile'];

    /**
     * @param array{find?:string,replace?:string,rules?:list<array{find?:string,replace?:string}>} $input
     * @return list<array{find:string,replace:string}>
     */

public function normalizeRules(array $input): array
    {
        $rules = $input['rules'] ?? [];
        if (is_array($rules) && $rules !== []) {
            $out = [];
            foreach ($rules as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $find = trim((string) ($row['find'] ?? ''));
                if ($find === '') {
                    continue;
                }
                if (mb_strlen($find) > self::MAX_FIND_LEN) {
                    continue;
                }
                $out[] = [
                    'find'    => $find,
                    'replace' => (string) ($row['replace'] ?? ''),
                ];
                if (count($out) >= self::MAX_RULES) {
                    break;
                }
            }

            return $out;
        }

        $find = trim((string) ($input['find'] ?? ''));
        if ($find === '') {
            return [];
        }

        return [
            [
                'find'    => $find,
                'replace' => (string) ($input['replace'] ?? ''),
            ],
        ];
    }

public function normalizeTagNames(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : (preg_split('/[,，\s]+/u', $raw) ?: []);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $name) {
            $name = trim((string) $name);
            if ($name !== '' && mb_strlen($name) <= 120) {
                $out[$name] = $name;
            }
        }

        return array_values($out);
    }

public function normalizeAttrKeys(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $allowed = DocumentAttrHelper::ATTR_FLAGS;
        $out     = [];
        foreach ($raw as $key) {
            $key = trim((string) $key);
            if ($key !== '' && in_array($key, $allowed, true)) {
                $out[$key] = $key;
            }
        }

        return array_values($out);
    }

public function validateRules(array $rules, array $options): ?string
    {
        if ($rules === []) {
            return '查找内容不能为空';
        }
        if (($options['regex'] ?? false) === true) {
            foreach ($rules as $rule) {
                $pattern = $this->regexPattern($rule['find'], $options);
                if (@preg_match($pattern, '') === false) {
                    return '正则表达式无效：' . $rule['find'];
                }
            }
        }

        return null;
    }

public function replaceOnce(
        string $text,
        string $find,
        string $replace,
        array $options,
        string $field
    ): array {
        if ($find === '') {
            return [$text, 0];
        }

        if (($options['regex'] ?? false) === true) {
            $pattern = $this->regexPattern($find, $options);
            $count   = 0;
            $out     = preg_replace($pattern, $replace, $text, -1, $count);

            return [(string) ($out ?? $text), max(0, $count)];
        }

        if (($options['whole_word'] ?? false) === true) {
            $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($find, '/') . '(?![\p{L}\p{N}_])/u';
            if (($options['case_insensitive'] ?? false) === true) {
                $pattern .= 'i';
            }
            $count = 0;
            $out   = preg_replace($pattern, $replace, $text, -1, $count);

            return [(string) ($out ?? $text), max(0, $count)];
        }

        if (($options['case_insensitive'] ?? false) === true) {
            $n   = 0;
            $low = mb_strtolower($text);
            $needle = mb_strtolower($find);
            $pos = 0;
            $buf = $text;
            while (($pos = mb_strpos($low, $needle, $pos)) !== false) {
                $len = mb_strlen($find);
                $buf = mb_substr($buf, 0, $pos) . $replace . mb_substr($buf, $pos + $len);
                $low = mb_strtolower($buf);
                $pos += mb_strlen($replace);
                $n++;
            }

            return [$buf, $n];
        }

        $count = substr_count($text, $find);

        return [str_replace($find, $replace, $text), $count];
    }

public function applyRulesToText(string $text, array $rules, array $options, string $field): array
    {
        $hits = 0;
        foreach ($rules as $rule) {
            [$text, $n] = $this->replaceOnce($text, $rule['find'], $rule['replace'], $options, $field);
            $hits      += $n;
        }

        return [$text, $hits];
    }

public function regexPattern(string $find, array $options): string
    {
        $mods = 'u';
        if (($options['case_insensitive'] ?? false) === true) {
            $mods .= 'i';
        }

        return '/' . str_replace('/', '\/', $find) . '/' . $mods;
    }

public function replaceInField(string $text, string $field, array $rules, array $options): array
    {
        $total = 0;
        if (($options['links_only'] ?? false) === true && $this->isHtmlField($field)) {
            $new = preg_replace_callback(
                '/\shref\s*=\s*(["\'])([^"\']*)\1/i',
                function (array $m) use ($rules, $options, $field, &$total): string {
                    $url = $m[2];
                    [$u2, $n] = $this->applyRulesToText($url, $rules, $options, $field);
                    $total += $n;

                    return ' href=' . $m[1] . $u2 . $m[1];
                },
                $text
            ) ?? $text;

            return [(string) $new, $total];
        }

        return $this->applyRulesToText($text, $rules, $options, $field);
    }

public function countHits(string $text, array $rules, array $options, string $field): int
    {
        if (($options['links_only'] ?? false) === true && $this->isHtmlField($field)) {
            $n = 0;
            if (preg_match_all('/\shref\s*=\s*(["\'])([^"\']*)\1/i', $text, $m)) {
                foreach ($m[2] as $url) {
                    foreach ($rules as $rule) {
                        [, $c] = $this->replaceOnce((string) $url, $rule['find'], '', array_merge($options, ['dry_count' => true]), $field);
                        $n += $c;
                    }
                }
            }

            return $n;
        }

        $total = 0;
        foreach ($rules as $rule) {
            [, $c] = $this->replaceOnce($text, $rule['find'], '', $options, $field);
            $total += $c;
        }

        return $total;
    }

public function buildSnippet(string $text, array $rules, array $options, string $field): array
    {
        $find = $rules[0]['find'] ?? '';
        $pos  = 0;
        if (($options['case_insensitive'] ?? false) === true) {
            $pos = mb_stripos($text, $find) ?: 0;
        } else {
            $pos = mb_strpos($text, $find) ?: 0;
        }
        $start  = max(0, $pos - 60);
        $excerpt = mb_substr($text, $start, 160);
        [$after] = $this->replaceInField($excerpt, $field, $rules, $options);

        return [
            'field'  => $field,
            'before' => $excerpt,
            'after'  => $after,
        ];
    }

public function isHtmlField(string $field): bool
    {
        return in_array($field, self::HTML_FIELDS, true) || $field === 'content';
    }

public function sanitizeField(string $field, string $value): string
    {
        if (in_array($field, self::HTML_FIELDS, true)) {
            return \app\common\support\HtmlSanitizer::cleanArticle($value);
        }
        if (in_array($field, ['summary', 'subtitle', 'seo_title', 'seo_keywords', 'seo_description'], true)) {
            return \app\common\support\HtmlSanitizer::cleanPlainText($value, $field === 'summary' ? 500 : 500);
        }

        return \app\common\support\HtmlSanitizer::cleanPlainText($value, 2000);
    }
}
