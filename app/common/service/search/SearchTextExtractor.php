<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

/** 发布/保存时生成 documents.search_text */
final class SearchTextExtractor
{

    public function __construct(
        private readonly DocumentAddonSearchRegistry $addonRegistry,
    ) {
    }

    private const MAX_LEN = 200_000;

    public function fromHtml(string ...$htmlParts): string
    {
        $chunks = [];
        foreach ($htmlParts as $html) {
            $plain = $this->htmlToPlain($html);
            if ($plain !== '') {
                $chunks[] = $plain;
            }
        }
        $text = trim(implode("\n", $chunks));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > self::MAX_LEN) {
            $text = mb_substr($text, 0, self::MAX_LEN);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $saveData 含 content / content_mobile / title 等
     */
    public function forDocumentSave(array $saveData): string
    {
        $fromBody = $this->fromHtml(
            (string) ($saveData['content'] ?? ''),
            (string) ($saveData['content_mobile'] ?? ''),
        );
        $meta = trim(implode(' ', array_filter([
            (string) ($saveData['title'] ?? ''),
            (string) ($saveData['subtitle'] ?? ''),
            (string) ($saveData['summary'] ?? ''),
        ])));

        return trim($meta . ($fromBody !== '' && $meta !== '' ? "\n" : '') . $fromBody);
    }

    /**
     * @param array<string, mixed> $row documents 行或等效字段
     */
    public function forDocument(int $documentId, array $row): string
    {
        $base = $this->forDocumentSave($row);
        if ($documentId < 1) {
            return $base;
        }
        $addon = $this->addonRegistry->plainText($documentId);
        if ($addon === '') {
            return $base;
        }

        return trim($base . ($base !== '' ? "\n\n" : '') . $addon);
    }

    private function htmlToPlain(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $plain = strip_tags($html);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return trim((string) $plain);
    }
}
