<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\model\Document;

/** 从 documents 正文提取纯文本 */
class DocumentPlainTextService
{

    /**
     * @param array<string, mixed>|null $row
     */
    public function fromDocumentRow(?array $row): string
    {
        if ($row === null) {
            return '';
        }
        $html = (string) ($row['content'] ?? '');
        if ($html === '') {
            $html = (string) ($row['content_mobile'] ?? '');
        }

        return $this->fromHtml($html);
    }

    public function fromDocumentId(int $documentId): string
    {
        if ($documentId < 1) {
            return '';
        }
        $row = Document::where('id', $documentId)->whereNull('deleted_at')->find()?->toArray();

        return $this->fromDocumentRow($row);
    }

    public function fromHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return trim($plain);
    }
}