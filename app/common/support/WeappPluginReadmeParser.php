<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 插件 README.md 章节解析与轻量 Markdown → HTML（功能介绍 / 使用说明 / 升级日志 SSOT） */
final class WeappPluginReadmeParser
{
    /**
     * @param list<string> $headingTitles 如 ['功能介绍']
     */
    public static function sectionContent(string $markdown, array $headingTitles): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        if ($markdown === '' || $headingTitles === []) {
            return '';
        }

        $want = [];
        foreach ($headingTitles as $title) {
            $title = trim($title);
            if ($title !== '') {
                $want[mb_strtolower($title)] = true;
            }
        }

        $blocks = preg_split('/\n(?=##\s+)/', $markdown) ?: [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (str_starts_with($block, '## ')) {
                $block = substr($block, 3);
            }
            $lines   = preg_split('/\n/', $block, 2) ?: [];
            $heading = trim((string) ($lines[0] ?? ''));
            if ($heading === '' || !isset($want[mb_strtolower($heading)])) {
                continue;
            }

            return trim((string) ($lines[1] ?? ''));
        }

        return '';
    }

    /**
     * 合并多个 ## 章节正文（用于 README 未写标准「功能介绍」时的市场/后台展示）
     *
     * @param list<string> $headingTitles 精确或前缀匹配（$prefixMatch=true 时）
     */
    public static function mergedSectionsContent(string $markdown, array $headingTitles, bool $prefixMatch = false): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        if ($markdown === '' || $headingTitles === []) {
            return '';
        }

        $want = [];
        foreach ($headingTitles as $title) {
            $title = trim($title);
            if ($title !== '') {
                $want[] = mb_strtolower($title);
            }
        }
        if ($want === []) {
            return '';
        }

        $blocks = preg_split('/\n(?=##\s+)/', $markdown) ?: [];
        $parts  = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (str_starts_with($block, '## ')) {
                $block = substr($block, 3);
            }
            $lines   = preg_split('/\n/', $block, 2) ?: [];
            $heading = trim((string) ($lines[0] ?? ''));
            $body    = trim((string) ($lines[1] ?? ''));
            if ($heading === '' || $body === '') {
                continue;
            }
            $hLower = mb_strtolower($heading);
            $hit    = false;
            foreach ($want as $title) {
                if ($prefixMatch ? str_starts_with($hLower, $title) : ($hLower === $title)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            $parts[] = '### ' . $heading . "\n\n" . $body;
        }

        return implode("\n\n", $parts);
    }

    public static function toHtml(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        if (self::shouldPassthroughHtml($content)) {
            return $content;
        }

        $parser = new self();

        return $parser->renderMarkdownLines(preg_split('/\n/', $content) ?: []);
    }

    /** 纯 HTML 块直出；混有 Markdown 表格/标题/列表时走混合解析 */
    private static function shouldPassthroughHtml(string $content): bool
    {
        if (!preg_match('/^\s*</', $content)) {
            return false;
        }

        if (preg_match('/^\|[^|\n]+\|/m', $content)) {
            return false;
        }

        if (preg_match('/^#{3,6}\s+/m', $content)) {
            return false;
        }

        return !preg_match('/^-\s+\S/m', $content);
    }

    /** @param list<string> $lines */
    private function renderMarkdownLines(array $lines): string
    {
        $html      = '';
        $inUl      = false;
        $inOl      = false;
        $inTable   = false;
        $tableRows = [];

        foreach ($lines as $line) {
            $trim = trim($line);

            if ($trim !== '' && str_contains($trim, '|') && preg_match('/^\|?.+\|.+\|?$/', $trim)) {
                $html .= $this->closeLists($inUl, $inOl);
                $inUl = false;
                $inOl = false;
                if (preg_match('/^[\|\s:-]+$/', str_replace('|', '', $trim))) {
                    continue;
                }
                $cells = array_map('trim', explode('|', trim($trim, '|')));
                $inTable = true;
                $tableRows[] = $cells;
                continue;
            }

            if ($inTable) {
                $html .= $this->flushTable($tableRows);
                $inTable = false;
                $tableRows = [];
            }

            if (self::isHtmlLine($trim)) {
                $html .= $this->closeLists($inUl, $inOl);
                $inUl = false;
                $inOl = false;
                $html .= $trim;
                continue;
            }

            if ($trim === '') {
                $html .= $this->closeLists($inUl, $inOl);
                $inUl = false;
                $inOl = false;
                continue;
            }

            if (preg_match('/^###\s+(.+)$/', $trim, $m)) {
                $html .= $this->closeLists($inUl, $inOl);
                $inUl = false;
                $inOl = false;
                $html .= '<h4>' . self::inlineHtml((string) $m[1]) . '</h4>';
                continue;
            }

            if (preg_match('/^-\s+(.+)$/', $trim, $m)) {
                if ($inOl) {
                    $html .= '</ol>';
                    $inOl = false;
                }
                if (!$inUl) {
                    $html .= '<ul>';
                    $inUl = true;
                }
                $html .= '<li>' . self::inlineHtml((string) $m[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $trim, $m)) {
                if ($inUl) {
                    $html .= '</ul>';
                    $inUl = false;
                }
                if (!$inOl) {
                    $html .= '<ol>';
                    $inOl = true;
                }
                $html .= '<li>' . self::inlineHtml((string) $m[1]) . '</li>';
                continue;
            }

            if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)$/', $trim, $m)) {
                $html .= $this->closeLists($inUl, $inOl);
                $inUl = false;
                $inOl = false;
                $alt = htmlspecialchars((string) $m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $src = htmlspecialchars((string) $m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= '<figure class="pv-guide-figure"><img src="' . $src . '" alt="' . $alt . '" loading="lazy" /></figure>';
                continue;
            }

            $html .= $this->closeLists($inUl, $inOl);
            $inUl = false;
            $inOl = false;
            $html .= '<p>' . self::inlineHtml($trim) . '</p>';
        }

        $html .= $this->closeLists($inUl, $inOl);
        if ($inTable) {
            $html .= $this->flushTable($tableRows);
        }

        return $html;
    }

    private function closeLists(bool $inUl, bool $inOl): string
    {
        $out = '';
        if ($inUl) {
            $out .= '</ul>';
        }
        if ($inOl) {
            $out .= '</ol>';
        }

        return $out;
    }

    /** @param list<list<string>> $tableRows */
    private function flushTable(array $tableRows): string
    {
        if ($tableRows === []) {
            return '';
        }

        $header = array_shift($tableRows);
        if ($header === null) {
            return '';
        }

        $out = '<table><thead><tr>';
        foreach ($header as $cell) {
            $out .= '<th>' . self::inlineHtml($cell) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($tableRows as $row) {
            $out .= '<tr>';
            foreach ($row as $cell) {
                $out .= '<td>' . self::inlineHtml($cell) . '</td>';
            }
            $out .= '</tr>';
        }

        return $out . '</tbody></table>';
    }

    private static function isHtmlLine(string $line): bool
    {
        return (bool) preg_match('/^<(\/?)([a-z][a-z0-9]*)\b[^>]*>/i', $line);
    }

    private static function inlineHtml(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);

        return $text;
    }
}
