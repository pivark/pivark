<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\package\PluginInstallPreflightService;
use app\common\support\ServiceResult;

/**
 * 插件市场 versions[] 阶梯连升：有历史版号包则一档一档；仅 latest 则一次到位。
 */
final class PluginUpgradeLadderService
{
    public function __construct(
        private readonly PluginMarketShelfDirectory $remoteCatalog,
        private readonly PluginService $pluginService,
        private readonly PluginInstallPreflightService $preflight,
    ) {
    }

    /**
     * @return array{
     *   identifier:string,
     *   local_version:string,
     *   remote_version:string,
     *   path:list<array{version:string,package_url:string,package_sha256?:string}>,
     *   steps:int,
     *   can_ladder:bool,
     *   one_shot:bool,
     *   summary:string,
     *   preflight:array{ok:bool,errors:list<string>}
     * }
     */
    public function plan(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        $manifest = $this->pluginService->readManifest($identifier);
        $local = is_array($manifest) ? trim((string) ($manifest['version'] ?? '')) : '';
        $remoteRow = $this->remoteCatalog->indexByIdentifier()[$identifier] ?? null;
        $remote = is_array($remoteRow) ? trim((string) ($remoteRow['version'] ?? '')) : '';
        $versions = $this->remoteCatalog->versions($identifier);
        $path = $this->buildPath($local, $remote, $versions);
        if ($path === [] && $remote !== '' && ($local === '' || $this->remoteCatalog->versionNewer($remote, $local))) {
            $url = $this->remoteCatalog->packageUrl($identifier);
            if ($url !== '') {
                $path = [[
                    'version'        => $remote,
                    'package_url'    => $url,
                    'package_sha256' => $this->remoteCatalog->packageSha256($identifier),
                ]];
            }
        }
        $oneShot = count($path) <= 1;
        $preflight = $this->preflight->forUpgrade($identifier);

        return [
            'identifier'     => $identifier,
            'local_version'  => $local,
            'remote_version' => $remote,
            'path'           => $path,
            'steps'          => count($path),
            'can_ladder'     => $path !== [] && !empty($preflight['ok']),
            'one_shot'       => $oneShot,
            'summary'        => $this->summary($local, $remote, $path, $preflight),
            'preflight'      => $preflight,
        ];
    }

    /**
     * @return ServiceResult
     */
    public function apply(string $identifier): ServiceResult
    {
        $plan = $this->plan($identifier);
        if (empty($plan['preflight']['ok'])) {
            $errs = is_array($plan['preflight']['errors'] ?? null) ? $plan['preflight']['errors'] : [];

            return ServiceResult::fail('升级预检未通过：' . implode('；', $errs), data: $plan);
        }
        $path = is_array($plan['path'] ?? null) ? $plan['path'] : [];
        if ($path === []) {
            return ServiceResult::fail('没有可应用的插件升级路径', data: $plan);
        }

        $applied = [];
        foreach ($path as $step) {
            if (!is_array($step)) {
                continue;
            }
            $ver = trim((string) ($step['version'] ?? ''));
            $url = trim((string) ($step['package_url'] ?? ''));
            if ($url === '') {
                return ServiceResult::fail('版本 V' . $ver . ' 缺少 package_url', data: [
                    'applied' => $applied,
                    'plan'    => $plan,
                ]);
            }
            $one = app(PluginMarketAcquireService::class)->applyRemotePackageForUpdate($identifier, $url);
            if (!$one->isOk()) {
                return ServiceResult::fail(
                    '升到 V' . $ver . ' 失败：' . (string) ($one->message() ?? ''),
                    data: [
                        'applied'   => $applied,
                        'failed_at' => $ver,
                        'plan'      => $plan,
                    ]
                );
            }
            $applied[] = $ver !== '' ? $ver : $url;
        }

        return ServiceResult::ok([
            'applied' => $applied,
            'steps'   => count($applied),
            'plan'    => $this->plan($identifier),
        ], '已按阶梯升级 ' . count($applied) . ' 档');
    }

    /**
     * @param list<array{version:string,package_url:string,package_sha256?:string}> $versions
     * @return list<array{version:string,package_url:string,package_sha256?:string}>
     */
    public function buildPath(string $local, string $remote, array $versions): array
    {
        $local = trim($local);
        $remote = trim($remote);
        if ($remote === '' || ($local !== '' && !$this->remoteCatalog->versionNewer($remote, $local))) {
            return [];
        }

        $sorted = $versions;
        usort($sorted, static function (array $a, array $b): int {
            return version_compare(
                trim((string) ($a['version'] ?? '')),
                trim((string) ($b['version'] ?? ''))
            );
        });

        $path = [];
        foreach ($sorted as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ver = trim((string) ($row['version'] ?? ''));
            $url = trim((string) ($row['package_url'] ?? ''));
            if ($ver === '' || $url === '') {
                continue;
            }
            if ($local !== '' && !$this->remoteCatalog->versionNewer($ver, $local)) {
                continue;
            }
            if ($remote !== '' && version_compare($ver, $remote, '>')) {
                continue;
            }
            $path[] = [
                'version'         => $ver,
                'package_url'     => $url,
                'package_sha256'  => strtolower(trim((string) ($row['package_sha256'] ?? ''))),
            ];
        }

        return $path;
    }

    /**
     * @param list<array{version:string,package_url:string,package_sha256?:string}> $path
     * @param array{ok:bool,errors:list<string>} $preflight
     */
    private function summary(string $local, string $remote, array $path, array $preflight): string
    {
        if (empty($preflight['ok'])) {
            return '升级预检未通过：' . implode('；', $preflight['errors'] ?? []);
        }
        if ($path === []) {
            return $remote === '' || $remote === $local
                ? '已是市场最新或无远程版本。'
                : '市场有新版本但缺少可达包路径。';
        }
        $labels = array_map(
            static fn (array $row): string => 'V' . ($row['version'] ?? ''),
            $path
        );
        $from = $local !== '' ? 'V' . $local : '当前';

        return count($path) === 1
            ? $from . ' → ' . $labels[0] . '（单包到位）'
            : '按阶梯连升：' . $from . ' → ' . implode(' → ', $labels);
    }
}
