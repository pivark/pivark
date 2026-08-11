<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\release;

use app\common\service\plugin\market\PluginMarketShelfDirectory;

/**
 * 核心 updates.json releases[] 阶梯路径：一档一档可达，不可跳过被 min_version 拦住的档。
 */
final class CoreUpdateLadderService
{
    public function __construct(
        private readonly PluginMarketShelfDirectory $versionCompare,
        private readonly CoreUpdateRemoteService $coreUpdateRemote,
    ) {
    }

    /**
     * @param array<string, mixed> $check CoreUpdateRemoteService::check()
     * @return array{
     *   path:list<array<string,mixed>>,
     *   steps:int,
     *   next:?array<string,mixed>,
     *   reaches_latest:bool,
     *   can_auto_ladder:bool,
     *   summary:string,
     *   versions:list<string>
     * }
     */
    public function planFromCheck(array $check): array
    {
        $current = trim((string) ($check['current'] ?? $this->coreUpdateRemote->currentVersion()));
        $latest  = trim((string) ($check['latest'] ?? $current));
        $releases = is_array($check['releases'] ?? null) ? $check['releases'] : [];
        $path = $this->buildPath($current, $releases, $latest);

        $next = $path[0] ?? null;
        $reaches = $path !== []
            && trim((string) ($path[array_key_last($path)]['version'] ?? '')) === $latest;
        $canAuto = $next !== null
            && trim((string) ($next['download_url'] ?? '')) !== ''
            && !empty($check['has_update'])
            && empty($check['upgrade_license_required'])
            && $this->siteAllowsOnlineApply();

        $versions = array_values(array_map(
            static fn (array $row): string => trim((string) ($row['version'] ?? '')),
            $path
        ));

        return [
            'path'            => $path,
            'steps'           => count($path),
            'next'            => $next,
            'reaches_latest'  => $reaches,
            'can_auto_ladder' => $canAuto,
            'versions'        => $versions,
            'summary'         => $this->summary($current, $latest, $path, $canAuto, $reaches),
        ];
    }

    /**
     * @param list<array<string,mixed>> $releases
     * @return list<array<string,mixed>>
     */
    public function buildPath(string $current, array $releases, string $latestGoal): array
    {
        $current = trim($current);
        $latestGoal = trim($latestGoal);
        if ($current === '' || $latestGoal === '' || !$this->versionCompare->versionNewer($latestGoal, $current)) {
            return [];
        }

        $normalized = [];
        foreach ($releases as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ver = trim((string) ($row['version'] ?? ''));
            if ($ver === '') {
                continue;
            }
            $normalized[$ver] = [
                'version'      => $ver,
                'min_version'  => trim((string) ($row['min_version'] ?? '')),
                'download_url' => trim((string) ($row['download_url'] ?? '')),
                'sha256'       => strtolower(trim((string) ($row['sha256'] ?? ''))),
                'signature'    => trim((string) ($row['signature'] ?? '')),
                'urgent'       => !empty($row['urgent']),
                'published_at' => trim((string) ($row['published_at'] ?? '')),
            ];
        }
        if ($normalized === []) {
            return [];
        }

        uksort($normalized, static fn (string $a, string $b): int => version_compare($a, $b));

        $path = [];
        $cursor = $current;
        $guard = 0;
        while ($this->versionCompare->versionNewer($latestGoal, $cursor) && $guard < 40) {
            ++$guard;
            $candidates = [];
            foreach ($normalized as $ver => $row) {
                if (!$this->versionCompare->versionNewer($ver, $cursor)) {
                    continue;
                }
                $min = trim((string) ($row['min_version'] ?? ''));
                if ($min !== '' && $this->versionCompare->versionNewer($min, $cursor)) {
                    continue;
                }
                $candidates[$ver] = $row;
            }
            if ($candidates === []) {
                break;
            }
            $nextVer = array_key_first($candidates);
            $step = $candidates[$nextVer];
            // 解析相对 download_url（与 check 展示一致）
            $step['download_url'] = $this->resolveDownloadUrl((string) ($step['download_url'] ?? ''));
            $path[] = $step;
            $cursor = $nextVer;
            if ($cursor === $latestGoal) {
                break;
            }
        }

        return $path;
    }

    private function resolveDownloadUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // 复用 RemoteService 解析：走一次空 check 代价大，直接相对路径留给 apply 侧 resolve
        return $url;
    }

    private function siteAllowsOnlineApply(): bool
    {
        return app(\app\common\service\site\SiteCoreLicenseService::class)->allowsOnlineCoreApply();
    }

    /**
     * @param list<array<string,mixed>> $path
     */
    private function summary(
        string $current,
        string $latest,
        array $path,
        bool $canAuto,
        bool $reaches
    ): string {
        if ($path === []) {
            return '无需阶梯升级，或清单缺少可达中间版本。';
        }
        $labels = array_map(
            static fn (array $row): string => 'V' . trim((string) ($row['version'] ?? '')),
            $path
        );
        $chain = 'V' . $current . ' → ' . implode(' → ', $labels);
        if (!$reaches) {
            return '阶梯路径未达最新 V' . $latest . '：' . $chain . '（请补发布中间包）。';
        }
        if ($canAuto) {
            return '可按阶梯自动连升：' . $chain . '。';
        }

        return '已规划阶梯：' . $chain . '（当前不可在线连升，请检查授权或下载地址）。';
    }
}
