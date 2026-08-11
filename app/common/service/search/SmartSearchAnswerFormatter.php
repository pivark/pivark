<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

/** 前台知识搜索摘要 · 轻量 Markdown → 安全 HTML */
final class SmartSearchAnswerFormatter
{

    public function __construct(
        private readonly SearchTextSanitizer $textSanitizer,
    ) {
    }

    public function toHtml(string $answer): string
    {
        $answer = $this->sanitizeUtf8($this->textSanitizer->maskLine(trim(str_replace(["\r\n", "\r"], "\n", $answer))));
        if ($answer === '') {
            return '';
        }

        $text = $this->inlineMarkdown(htmlspecialchars($answer, ENT_QUOTES, 'UTF-8'));
        $blocks = preg_split('/\n\s*\n/', $text) ?: [];
        $html   = [];

        foreach ($blocks as $block) {
            $block = trim((string) $block);
            if ($block === '') {
                continue;
            }

            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn (string $l): bool => $l !== ''));
            if ($lines === []) {
                continue;
            }

            if ($this->isBulletBlock($lines)) {
                if ($this->isSpecBlock($lines)) {
                    $html[] = $this->renderSpecList($lines);
                } else {
                    $html[] = $this->renderBulletList($lines);
                }
                continue;
            }

            $html[] = $this->renderParagraph($block);
        }

        $html = implode("\n", $html);

        return $this->sanitizeUtf8($html);
    }

    private function inlineMarkdown(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/u', '<em>$1</em>', $text) ?? $text;

        return $text;
    }

    /** @param list<string> $lines */
    private function isBulletBlock(array $lines): bool
    {
        foreach ($lines as $line) {
            if (!str_starts_with($line, '- ')) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $lines */
    private function isSpecBlock(array $lines): bool
    {
        foreach ($lines as $line) {
            if (!preg_match('/^- <strong>[^<]+<\/strong>[：:]/u', $line)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $lines */
    private function renderSpecList(array $lines): string
    {
        $items = [];
        foreach ($lines as $line) {
            if (!preg_match('/^- <strong>([^<]+)<\/strong>[：:]\s*(.+)$/u', $line, $m)) {
                continue;
            }
            $items[] = '<li><span class="pv-smart-search-spec__label">' . $m[1]
                . '</span><span class="pv-smart-search-spec__value">' . $m[2] . '</span></li>';
        }

        if ($items === []) {
            return $this->renderBulletList($lines);
        }

        return '<ul class="pv-smart-search-specs">' . implode('', $items) . '</ul>';
    }

    /** @param list<string> $lines */
    private function renderBulletList(array $lines): string
    {
        $items = [];
        foreach ($lines as $line) {
            if (!preg_match('/^- (.+)$/u', $line, $m)) {
                continue;
            }
            $items[] = '<li>' . $m[1] . '</li>';
        }

        return '<ul class="pv-smart-search-list">' . implode('', $items) . '</ul>';
    }

    private function renderParagraph(string $block): string
    {
        $content = str_replace("\n", '<br>', $block);
        $class   = str_starts_with($content, '<strong>') ? ' pv-smart-search-tip' : '';

        return '<p class="pv-smart-search-paragraph' . $class . '">' . $content . '</p>';
    }

    private function sanitizeUtf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $clean = iconv('UTF-8', 'UTF-8//IGNORE', $text);

        return $clean !== false ? $clean : '';
    }
}
