<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\seo;

/** 从页面变量解析可安全写入 meta content 的纯文本描述；文档保存时推导摘要 / TDK */
final class SeoExcerpt
{

    /** @var list<string> */
    private const STOPWORDS = [
        '一个', '我们', '你们', '他们', '她们', '自己', '其中', '此外', '同时', '比如', '例如',
        '通常', '一般', '主要', '包括', '支持', '提供', '实现', '功能', '系统', '平台', '方案',
        '服务', '产品', '内容', '文章', '文档', '页面', '用户', '客户', '企业', '管理', '配置',
        '设置', '操作', '步骤', '方法', '介绍', '说明', '概述', '简介', '总结', '可以', '进行',
        '通过', '使用', '需要', '已经', '更多', '相关', '点击', '查看', '了解', '如何', '什么',
        '以及', '或者', '如果', '因为', '所以', '它们', '这里', '那里', '这个', '那个', '这些',
        '那些', '不是', '没有', '可能', '应该', '将会', '已经', '非常', '特别', '目前', '现在',
        '的', '了', '是', '在', '有', '和', '与', '为', '等', '及', '或', '把', '被', '从', '对',
        '也', '就', '都', '而', '但', '这', '那', '其', '于', '以', '到', '由', '将', '并', '且',
    ];

    public function plain(string $raw, int $max = 160): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, '<') || str_contains($raw, '&lt;')) {
            $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $raw = strip_tags($raw);
        }

        $text = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, max(1, $max - 1)) . '…';
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function resolveForMeta(array $vars, int $max = 160): string
    {
        foreach ([
            (string) ($vars['seo_description'] ?? ''),
            (string) ($vars['page_summary'] ?? ''),
            (string) ($vars['document_summary'] ?? ''),
        ] as $candidate) {
            $plain = $this->plain($candidate, $max);
            if ($plain !== '') {
                return $plain;
            }
        }

        $content = trim((string) ($vars['page_content'] ?? $vars['document_content'] ?? ''));
        if ($content !== '') {
            $plain = $this->plain($content, $max);
            if ($plain !== '') {
                return $plain;
            }
        }

        return $this->plain((string) ($vars['site_description'] ?? ''), $max);
    }

    /**
     * 从文档字段推导摘要 / SEO 描述 / 关键词（供保存填空或后台预览）。
     *
     * @param array{title?:string,subtitle?:string,content?:string,tags?:list<string>|string} $fields
     * @return array{summary:string,seo_description:string,seo_keywords:string}
     */
    public function deriveForDocument(array $fields): array
    {
        $title    = trim((string) ($fields['title'] ?? ''));
        $subtitle = trim((string) ($fields['subtitle'] ?? ''));
        $plain    = $this->htmlToPlain((string) ($fields['content'] ?? ''));
        $tags     = $this->normalizeTags($fields['tags'] ?? []);

        $summary = $this->deriveSummary($plain, 200);
        if ($summary === '' && $subtitle !== '') {
            $summary = $this->truncateAtSentence($subtitle, 200);
        }

        $seoDescription = $this->deriveDescription($plain, $title, $subtitle, 200);
        $seoKeywords    = $this->deriveKeywords($title, $subtitle, $plain, $tags);

        return [
            'summary'          => $summary,
            'seo_description'  => $seoDescription,
            'seo_keywords'     => $seoKeywords,
        ];
    }

    public function deriveSummary(string $plain, int $max = 200): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }

        $lead = $this->firstParagraph($plain);

        return $this->truncateAtSentence($lead, $max);
    }

    public function deriveDescription(string $plain, string $title, string $subtitle, int $max = 200): string
    {
        $plain = trim($plain);
        if ($plain !== '') {
            $sentences = $this->splitSentences($this->firstParagraph($plain));
            $parts     = [];
            foreach ($sentences as $sentence) {
                $parts[] = $sentence;
                $joined  = implode('', $parts);
                if (mb_strlen($joined) >= min(120, (int) ($max * 0.6))) {
                    return $this->truncateAtSentence($joined, $max);
                }
            }
            if ($parts !== []) {
                return $this->truncateAtSentence(implode('', $parts), $max);
            }
        }

        if ($subtitle !== '') {
            return $this->truncateAtSentence($subtitle, $max);
        }
        if ($title !== '') {
            return $this->truncateAtSentence($title, $max);
        }

        return '';
    }

    /**
     * @param list<string> $tags
     */
    public function deriveKeywords(string $title, string $subtitle, string $plain, array $tags, int $maxCount = 8): string
    {
        $keywords = [];
        $seen     = [];

        $push = static function (string $word) use (&$keywords, &$seen, $maxCount): void {
            $word = trim($word);
            if ($word === '' || mb_strlen($word) < 2 || count($keywords) >= $maxCount) {
                return;
            }
            $key = mb_strtolower($word);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $keywords[] = $word;
        };

        foreach ($tags as $tag) {
            $push($tag);
        }

        foreach ($this->splitTitleSegments($title) as $segment) {
            $push($segment);
        }
        if ($subtitle !== '' && mb_strtolower($subtitle) !== mb_strtolower($title)) {
            $push($subtitle);
        }

        foreach ($this->extractEnglishTerms($title . ' ' . $subtitle . ' ' . $plain) as $term) {
            $push($term);
        }

        foreach ($this->extractFrequentBigrams($plain, $title, 3) as $bigram) {
            $push($bigram);
        }

        return implode(',', $keywords);
    }

    private function htmlToPlain(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $html  = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $plain = strip_tags($html);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return trim((string) $plain);
    }

    private function firstParagraph(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }
        $parts = preg_split('/\n{2,}|\r\n{2,}/u', $plain) ?: [$plain];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                return $part;
            }
        }

        return $plain;
    }

    /**
     * @return list<string>
     */
    private function splitSentences(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $chunks = preg_split('/(?<=[。！？!?；;])\s*/u', $text) ?: [];
        $out    = [];
        foreach ($chunks as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk !== '') {
                $out[] = $chunk;
            }
        }

        return $out !== [] ? $out : [$text];
    }

    private function truncateAtSentence(string $text, int $max): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $slice = mb_substr($text, 0, $max);
        if (preg_match('/^(.*[。！？!?；;])/u', $slice, $m)) {
            $candidate = trim((string) ($m[1] ?? ''));
            if ($candidate !== '' && mb_strlen($candidate) >= (int) ($max * 0.45)) {
                return $candidate;
            }
        }

        return rtrim($slice) . '…';
    }

    /**
     * @return list<string>
     */
    private function splitTitleSegments(string $title): array
    {
        $title = trim($title);
        if ($title === '') {
            return [];
        }
        $parts = preg_split('/[：:，,;；|·\/\\\\\\-\\s]+/u', $title) ?: [];
        $out   = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || $this->isStopword($part)) {
                continue;
            }
            if (mb_strlen($part) >= 2) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extractEnglishTerms(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (!preg_match_all('/\b[A-Za-z][A-Za-z0-9+#.-]{2,}\b/', $text, $matches)) {
            return [];
        }
        $out  = [];
        $seen = [];
        foreach ($matches[0] as $raw) {
            $term = trim((string) $raw);
            if ($term === '' || mb_strlen($term) < 3) {
                continue;
            }
            $key = strtolower($term);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $term;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extractFrequentBigrams(string $plain, string $title, int $limit): array
    {
        $sample = mb_substr($plain, 0, 3000);
        if ($sample === '' || !preg_match_all('/[\x{4e00}-\x{9fff}]{2,4}/u', $sample, $matches)) {
            return [];
        }

        $freq = [];
        foreach ($matches[0] as $token) {
            $token = (string) $token;
            if ($this->isStopword($token)) {
                continue;
            }
            $freq[$token] = ($freq[$token] ?? 0) + 1;
        }
        if ($freq === []) {
            return [];
        }

        arsort($freq);
        $titleLower = mb_strtolower($title);
        $picked     = [];
        foreach ($freq as $token => $count) {
            if ($count < 2 && !str_contains($titleLower, mb_strtolower($token))) {
                continue;
            }
            if ($this->isStopword($token)) {
                continue;
            }
            $picked[] = $token;
            if (count($picked) >= $limit) {
                break;
            }
        }

        return $picked;
    }

    /**
     * @param list<string>|string $tags
     * @return list<string>
     */
    private function normalizeTags(array|string $tags): array
    {
        if (is_string($tags)) {
            $tags = $tags === '' ? [] : array_map('trim', explode(',', $tags));
        }
        $out = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $out[] = $tag;
            }
        }

        return $out;
    }

    private function isStopword(string $word): bool
    {
        $word = trim($word);
        if ($word === '') {
            return true;
        }
        if (in_array($word, self::STOPWORDS, true)) {
            return true;
        }
        if (mb_strlen($word) <= 1) {
            return true;
        }
        if (preg_match('/^\d+$/', $word)) {
            return true;
        }

        return false;
    }
}
