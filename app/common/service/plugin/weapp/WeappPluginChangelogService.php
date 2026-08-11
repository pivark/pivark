<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\weapp;

use app\common\service\plugin\PluginService;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;
use app\common\support\WeappPluginReadmeParser;
use app\common\support\WeappPublicAsset;

/**
 * 插件版本更新说明 SSOT
 *
 * 优先级：README.md `## 升级日志` → CHANGELOG.md（兼容）→ plugin.json → catalog changelog_html
 */
final class WeappPluginChangelogService
{
    /** README.md 中升级日志章节标题（与功能介绍 / 使用说明同一文件） */
    private const README_SECTION_TITLES = [
        '升级日志',
        '版本更新',
        '更新日志',
        '版本记录',
        'changelog',
    ];

    public function __construct(
        private readonly PluginService $pluginService,
    ) {
    }

    public function hasEntries(string $identifier): bool
    {
        return $this->entries($identifier) !== [];
    }

    /**
     * @return list<array{version:string,date:string,notes:list<string>}>
     */
    public function entries(string $identifier): array
    {
        $identifier = $this->normalizeIdentifier($identifier);
        if ($identifier === '') {
            return [];
        }

        $fromReadme = $this->entriesFromReadmeSection($identifier);
        if ($fromReadme !== []) {
            return $fromReadme;
        }

        $fromFile = $this->entriesFromChangelogFile($identifier);
        if ($fromFile !== []) {
            return $fromFile;
        }

        $manifest = $this->pluginService->readManifest($identifier);

        return $this->entriesFromManifest(is_array($manifest) ? $manifest : []);
    }

    /**
     * @param array<string, mixed>|null $catalogFallback
     */
    public function html(string $identifier, ?array $catalogFallback = null): string
    {
        $identifier = $this->normalizeIdentifier($identifier);
        if ($identifier === '') {
            return '';
        }

        $entries = $this->entries($identifier);
        if ($entries === [] && is_array($catalogFallback)) {
            $entries = $this->entriesFromManifest($catalogFallback);
        }
        if ($entries === []) {
            $catalogHtml = trim((string) ($catalogFallback['changelog_html'] ?? ''));
            if ($catalogHtml !== '') {
                return WeappPublicAsset::normalizeDocHtml($catalogHtml, $identifier);
            }

            return '';
        }

        return $this->renderEntriesHtml($entries);
    }

    /**
     * @return list<array{version:string,date:string,notes:list<string>}>
     */
    private function entriesFromReadmeSection(string $identifier): array
    {
        $path = ProjectPaths::root() . '/weapp/' . $identifier . '/README.md';
        if (!is_readable($path)) {
            return [];
        }

        $markdown = trim(LocalFile::getContents($path) ?: '');
        if ($markdown === '') {
            return [];
        }

        $raw = WeappPluginReadmeParser::sectionContent($markdown, self::README_SECTION_TITLES);
        if ($raw === '') {
            return [];
        }

        return $this->parseMarkdownChangelog($raw);
    }

    /**
     * @return list<array{version:string,date:string,notes:list<string>}>
     */
    private function entriesFromChangelogFile(string $identifier): array
    {
        $path = ProjectPaths::root() . '/weapp/' . $identifier . '/CHANGELOG.md';
        if (!is_readable($path)) {
            return [];
        }

        $raw = trim(LocalFile::getContents($path) ?: '');
        if ($raw === '') {
            return [];
        }

        return $this->parseMarkdownChangelog($raw);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array{version:string,date:string,notes:list<string>}>
     */
    private function entriesFromManifest(array $manifest): array
    {
        $rows = $manifest['changelog'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeEntry(
                (string) ($row['version'] ?? ''),
                (string) ($row['date'] ?? ''),
                $row['notes'] ?? []
            );
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * @return list<array{version:string,date:string,notes:list<string>}>
     */
    private function parseMarkdownChangelog(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        if ($markdown === '') {
            return [];
        }

        $blocks = preg_split('/\n(?=#{2,3}\s+)/', $markdown) ?: [];
        $out      = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (str_starts_with($block, '### ')) {
                $block = substr($block, 4);
            } elseif (str_starts_with($block, '## ')) {
                $block = substr($block, 3);
            }
            $lines = preg_split('/\n/', $block) ?: [];
            $head  = trim((string) ($lines[0] ?? ''));
            if ($head === '') {
                continue;
            }

            [$version, $date] = $this->parseVersionDateHeading($head);
            if ($version === '' || !preg_match('/^\d/', $version)) {
                continue;
            }
            $notes = [];
            foreach (array_slice($lines, 1) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^[-*+]\s+(.+)$/', $line, $m)) {
                    $notes[] = trim($m[1]);
                    continue;
                }
                if ($notes === []) {
                    $notes[] = $line;
                } else {
                    $notes[count($notes) - 1] .= ' ' . $line;
                }
            }

            $normalized = $this->normalizeEntry($version, $date, $notes);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseVersionDateHeading(string $heading): array
    {
        $heading = trim($heading);
        if (preg_match('/^v?([\d.]+(?:[-+][\w.]+)?)\s*(?:·|\||\(|（)\s*(\d{4}-\d{2}-\d{2})/u', $heading, $m)) {
            return [(string) $m[1], (string) $m[2]];
        }
        if (preg_match('/^v?([\d.]+(?:[-+][\w.]+)?)\s*$/', $heading, $m)) {
            return [(string) $m[1], ''];
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*·\s*v?([\d.]+(?:[-+][\w.]+)?)/u', $heading, $m)) {
            return [(string) $m[2], (string) $m[1]];
        }

        return [$heading, ''];
    }

    /**
     * @param mixed $notesRaw
     * @return array{version:string,date:string,notes:list<string>}|null
     */
    private function normalizeEntry(string $version, string $date, mixed $notesRaw): ?array
    {
        $version = ltrim(trim($version), 'vV');
        if ($version === '') {
            return null;
        }

        $notes = [];
        if (is_string($notesRaw)) {
            $notesRaw = trim($notesRaw) === '' ? [] : [trim($notesRaw)];
        }
        if (is_array($notesRaw)) {
            foreach ($notesRaw as $note) {
                $note = trim((string) $note);
                if ($note !== '') {
                    $notes[] = $note;
                }
            }
        }

        return [
            'version' => $version,
            'date'    => trim($date),
            'notes'   => $notes,
        ];
    }

    /**
     * @param list<array{version:string,date:string,notes:list<string>}> $entries
     */
    private function renderEntriesHtml(array $entries): string
    {
        $html = '<div class="pv-weapp-changelog-list">';
        foreach ($entries as $index => $entry) {
            $version = htmlspecialchars($entry['version'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $date    = htmlspecialchars($entry['date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $badge   = $index === 0 ? ' is-latest' : '';
            $html   .= '<article class="pv-weapp-changelog-release">';
            $html   .= '<header class="pv-weapp-changelog-release__head">';
            $html   .= '<span class="pv-weapp-changelog-badge' . $badge . '">v' . $version . '</span>';
            if ($date !== '') {
                $html .= '<time class="pv-weapp-changelog-release__date" datetime="' . $date . '">' . $date . '</time>';
            }
            $html .= '</header>';
            $notes = $entry['notes'];
            if ($notes !== []) {
                $html .= '<ul class="pv-weapp-changelog-release__notes">';
                foreach ($notes as $note) {
                    $html .= '<li>' . htmlspecialchars((string) $note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
                }
                $html .= '</ul>';
            }
            $html .= '</article>';
        }
        $html .= '</div>';

        return $html;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
    }
}
