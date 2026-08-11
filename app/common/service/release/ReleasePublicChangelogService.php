<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 前台/后台共用：读 static/release/changelog.json + updates.json（访客向版本说明）。
 */
declare(strict_types=1);

namespace app\common\service\release;

final class ReleasePublicChangelogService
{
    public const LABEL_MAP = [
        'feat'     => '新增',
        'fix'      => '修复',
        'improve'  => '优化',
        'refactor' => '重构',
        'security' => '安全',
        'update'   => '更新',
        'launch'   => '首发',
    ];

    /** 中文前缀 → kind（与 LABEL_MAP 互逆） */
    private const PREFIX_KIND = [
        '新增' => 'feat',
        '修复' => 'fix',
        '优化' => 'improve',
        '重构' => 'refactor',
        '安全' => 'security',
        '更新' => 'update',
        '首发' => 'launch',
    ];

    /**
     * Community 更新说明页变量。
     *
     * @return array<string, mixed>
     */
    public function templateVars(): array
    {
        $updates = $this->loadUpdates();
        $items = $this->mergeItems($this->loadChangelogItems(), $updates);
        $latest = $items[0] ?? null;
        $latestVer = is_array($latest) ? (string) ($latest['version'] ?? '') : '';

        return [
            'www_release_latest'           => $latestVer,
            'www_release_latest_label'     => $latestVer !== '' ? 'v' . $latestVer : '',
            'www_release_published_at'     => is_array($latest) ? (string) ($latest['published_at'] ?? '') : '',
            'www_release_install_url'      => is_array($latest) ? (string) ($latest['install_url'] ?? '') : '',
            'www_release_upgrade_url'      => is_array($latest) ? (string) ($latest['upgrade_url'] ?? '') : '',
            'www_release_sha256'           => is_array($latest) ? (string) ($latest['sha256'] ?? '') : '',
            'www_release_has_security'     => is_array($latest) && !empty($latest['has_security']) ? 1 : 0,
            'www_release_items'            => $items,
            'www_release_items_empty'      => $items === [] ? 1 : 0,
            'www_release_gitee_url'        => $this->giteeUrl(),
            'www_release_changelog_url'    => '/changelog',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadChangelogItems(): array
    {
        $path = $this->publicReleasePath('changelog.json');
        if (!is_readable($path)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return [];
        }
        $items = is_array($raw['items'] ?? null) ? $raw['items'] : [];
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $norm = $this->normalizeItem($row);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadUpdates(): array
    {
        $path = $this->publicReleasePath('updates.json');
        if (!is_readable($path)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($path), true);

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param list<array<string, mixed>> $changelogItems
     * @param array<string, mixed> $updates
     * @return list<array<string, mixed>>
     */
    private function mergeItems(array $changelogItems, array $updates): array
    {
        $byVer = [];
        foreach ($changelogItems as $item) {
            $v = (string) ($item['version'] ?? '');
            if ($v !== '') {
                $byVer[$v] = $item;
            }
        }

        $releases = is_array($updates['releases'] ?? null) ? $updates['releases'] : [];
        foreach ($releases as $row) {
            if (!is_array($row)) {
                continue;
            }
            $v = trim((string) ($row['version'] ?? ''));
            if ($v === '') {
                continue;
            }
            $fromUpdates = $this->normalizeItem([
                'version'      => $v,
                'published_at' => (string) ($row['published_at'] ?? $updates['published_at'] ?? ''),
                'tags'         => $row['tags'] ?? [],
                'highlights'   => $row['highlights'] ?? $row['notes'] ?? $row['summary'] ?? [],
                'install_url'  => (string) ($row['install_url'] ?? ('/static/release/pivark-community-install-' . $v . '.zip')),
                'upgrade_url'  => (string) ($row['download_url'] ?? ('/static/release/pivark-community-upgrade-' . $v . '.zip')),
                'sha256'       => (string) ($row['sha256'] ?? ''),
            ]);
            if ($fromUpdates === null) {
                continue;
            }
            if (!isset($byVer[$v])) {
                $byVer[$v] = $fromUpdates;
                continue;
            }
            // changelog 优先正文；补齐 updates 的 sha / 下载链
            if ($byVer[$v]['sha256'] === '' && $fromUpdates['sha256'] !== '') {
                $byVer[$v]['sha256'] = $fromUpdates['sha256'];
            }
            if ($byVer[$v]['upgrade_url'] === '' && $fromUpdates['upgrade_url'] !== '') {
                $byVer[$v]['upgrade_url'] = $fromUpdates['upgrade_url'];
            }
            if ($byVer[$v]['install_url'] === '' && $fromUpdates['install_url'] !== '') {
                $byVer[$v]['install_url'] = $fromUpdates['install_url'];
            }
            if ($byVer[$v]['highlights'] === [] && $fromUpdates['highlights'] !== []) {
                $byVer[$v]['highlights'] = $fromUpdates['highlights'];
                $byVer[$v]['entries'] = $fromUpdates['entries'] ?? [];
                $byVer[$v]['has_security'] = $fromUpdates['has_security'];
            }
        }

        $list = array_values($byVer);
        usort($list, static function (array $a, array $b): int {
            return version_compare((string) ($b['version'] ?? ''), (string) ($a['version'] ?? ''));
        });

        foreach ($list as $i => &$item) {
            $item['is_latest'] = $i === 0 ? 1 : 0;
            $item['expanded'] = $i < 2 ? 1 : 0;
            $item['collapse_id'] = 'www-rel-' . preg_replace('/[^a-z0-9]+/i', '-', (string) ($item['version'] ?? $i));
        }
        unset($item);

        return $list;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function normalizeItem(array $row): ?array
    {
        $version = trim((string) ($row['version'] ?? ''));
        if ($version === '') {
            return null;
        }
        $highlights = [];
        $entries = [];
        $rawHl = $row['highlights'] ?? [];
        if (is_string($rawHl)) {
            $rawHl = preg_split('/\r?\n+/', $rawHl) ?: [];
        }
        if (is_array($rawHl)) {
            foreach ($rawHl as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $highlights[] = $line;
                $entries[] = $this->parseHighlightLine($line);
            }
        }
        $tags = [];
        $rawTags = $row['tags'] ?? [];
        if (is_array($rawTags)) {
            foreach ($rawTags as $t) {
                $t = strtolower(trim((string) $t));
                if ($t !== '' && isset(self::LABEL_MAP[$t])) {
                    $tags[] = $t;
                }
            }
        }
        $hasSecurity = in_array('security', $tags, true);
        foreach ($entries as $entry) {
            if (($entry['kind'] ?? '') === 'security') {
                $hasSecurity = true;
                break;
            }
        }

        $installUrl = trim((string) ($row['install_url'] ?? ''));
        $upgradeUrl = trim((string) ($row['upgrade_url'] ?? ''));
        // 缺链时按版本拼默认包路径，避免静态页/半截 JSON 露出无下载钮
        if ($installUrl === '') {
            $installUrl = '/static/release/pivark-community-install-' . $version . '.zip';
        }
        if ($upgradeUrl === '') {
            $upgradeUrl = '/static/release/pivark-community-upgrade-' . $version . '.zip';
        }

        return [
            'version'      => $version,
            'version_label'=> 'v' . $version,
            'published_at' => trim((string) ($row['published_at'] ?? '')),
            'tags'         => array_values(array_unique($tags)),
            'highlights'   => $highlights,
            'entries'      => $entries,
            'install_url'  => $installUrl,
            'upgrade_url'  => $upgradeUrl,
            'sha256'       => strtolower(trim((string) ($row['sha256'] ?? ''))),
            'has_security' => $hasSecurity ? 1 : 0,
        ];
    }

    /**
     * 「修复 文案」→ {kind, kind_label, text}；无前缀则 kind=update。
     *
     * @return array{kind:string,kind_label:string,text:string}
     */
    private function parseHighlightLine(string $line): array
    {
        foreach (self::PREFIX_KIND as $prefix => $kind) {
            if ($line === $prefix) {
                return [
                    'kind'       => $kind,
                    'kind_label' => $prefix,
                    'text'       => '',
                ];
            }
            if (str_starts_with($line, $prefix . ' ') || str_starts_with($line, $prefix . '　')) {
                return [
                    'kind'       => $kind,
                    'kind_label' => $prefix,
                    'text'       => trim(substr($line, strlen($prefix))),
                ];
            }
        }

        return [
            'kind'       => 'update',
            'kind_label' => '更新',
            'text'       => $line,
        ];
    }

    private function publicReleasePath(string $name): string
    {
        $root = defined('ROOT_PATH') ? rtrim(str_replace('\\', '/', (string) ROOT_PATH), '/') : '';

        return $root . '/public/static/release/' . ltrim($name, '/');
    }

    private function giteeUrl(): string
    {
        try {
            $u = trim((string) app(\app\common\service\config\ConfigService::class)->get('site_download_gitee_url', ''));
            if ($u !== '' && preg_match('#^https?://#i', $u)) {
                return $u;
            }
        } catch (\Throwable) {
        }

        return 'https://gitee.com/pivark/pivark/releases';
    }
}
