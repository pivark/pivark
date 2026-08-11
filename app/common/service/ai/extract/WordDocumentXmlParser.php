<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai\extract;

/** 从 word/document.xml 提取纯文本 */
class WordDocumentXmlParser
{

    public function toPlainText(string $xml): string
    {
        if ($xml === '') {
            return '';
        }

        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:tr>/', "\n", $xml) ?? $xml;

        $parts = [];
        if (preg_match_all('/<w:t(?:\s[^>]*)?>([^<]*)<\/w:t>/u', $xml, $matches)) {
            foreach ($matches[1] as $chunk) {
                $parts[] = html_entity_decode($chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if ($parts !== []) {
            $text = implode('', $parts);
            $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
            $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

            return trim($text);
        }

        $plain = strip_tags($xml);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return trim($plain);
    }
}
