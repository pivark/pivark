<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

/** 模板源静态编译、分词与标签摘要（Phase C） */
final class TemplateTagTokenizer
{

    /**
     * 静态编译：去 HTML 注释 + 旧标签别名（与 parseTags 前两步一致，可跨请求缓存）
     */
    public function compileSource(string $html): string
    {
        $html = app(TemplateSiteVars::class)->stripHtmlComments($html);

        return app(TemplateTagParser::class)->normalizeLegacyTagNames($html);
    }

    /**
     * @return list<array{type:string,value:string}>
     */
    public function tokenize(string $compiled): array
    {
        if ($compiled === '') {
            return [];
        }

        $tokens = [];
        $offset = 0;
        if (preg_match_all(
            '/(\{pv:[a-z][a-z0-9_]*\b[^}]*\}|\{\/pv:[a-z][a-z0-9_]*\})/i',
            $compiled,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches[0] as $match) {
                $text = (string) ($match[0] ?? '');
                $pos  = (int) ($match[1] ?? 0);
                if ($text === '') {
                    continue;
                }
                if ($pos > $offset) {
                    $tokens[] = ['type' => 'text', 'value' => substr($compiled, $offset, $pos - $offset)];
                }
                $tokens[] = ['type' => 'pv', 'value' => $text];
                $offset   = $pos + strlen($text);
            }
        }
        if ($offset < strlen($compiled)) {
            $tokens[] = ['type' => 'text', 'value' => substr($compiled, $offset)];
        }

        return $tokens;
    }

    /**
     * @return array{has_pv_tags:bool,tag_names:list<string>,token_count:int,pv_token_count:int}
     */
    public function analyze(string $compiled): array
    {
        $tokens   = $this->tokenize($compiled);
        $pvNames  = [];
        $pvCount  = 0;
        foreach ($tokens as $token) {
            if (($token['type'] ?? '') !== 'pv') {
                continue;
            }
            $pvCount++;
            $raw = (string) ($token['value'] ?? '');
            if (preg_match('/\{pv:([a-z][a-z0-9_]*)\b/i', $raw, $m)) {
                $pvNames[strtolower($m[1])] = true;
            } elseif (preg_match('/\{\/pv:([a-z][a-z0-9_]*)\}/i', $raw, $m)) {
                $pvNames[strtolower($m[1])] = true;
            }
        }

        return [
            'has_pv_tags'    => $pvCount > 0,
            'tag_names'      => array_keys($pvNames),
            'token_count'    => count($tokens),
            'pv_token_count' => $pvCount,
        ];
    }

    public function hasPvTags(string $compiled): bool
    {
        return (bool) preg_match('/\{pv:[a-z][a-z0-9_]*\b/i', $compiled)
            || (bool) preg_match('/\{\/pv:[a-z][a-z0-9_]*\}/i', $compiled);
    }
}
