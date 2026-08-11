<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\release;

use app\common\model\Plugin;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\package\PluginCoreVersionRequirementService;

/**
 * 整站核心升级前预检：已装插件 requires.pivark_core 对当前/目标核心。
 */
final class SiteUpgradePreflightService
{
    public function __construct(
        private readonly CoreUpdateRemoteService $coreUpdateRemote,
        private readonly PluginService $pluginService,
        private readonly PluginCoreVersionRequirementService $coreRequirement,
    ) {
    }

    /**
     * @param array<string, mixed>|null $coreCheck CoreUpdateRemoteService::check() 结果；null 则现查
     * @return array{
     *   current_core:string,
     *   target_core:string,
     *   has_core_update:bool,
     *   min_version:string,
     *   min_version_blocked:bool,
     *   plugins:list<array<string,mixed>>,
     *   incompatible_current:list<array<string,mixed>>,
     *   incompatible_target:list<array<string,mixed>>,
     *   missing_core_requires:list<array<string,mixed>>,
     *   blockers:list<string>,
     *   warnings:list<string>,
     *   recommended_order:string,
     *   recommended_order_label:string,
     *   can_start_core_upgrade:bool,
     *   summary:string
     * }
     */
    public function report(?array $coreCheck = null): array
    {
        $check = is_array($coreCheck) ? $coreCheck : $this->coreUpdateRemote->check(false);
        $current = trim((string) ($check['current'] ?? $this->coreRequirement->currentCoreVersion()));
        $latest  = trim((string) ($check['latest'] ?? $current));
        $hasUpdate = !empty($check['has_update']);
        $target = $hasUpdate && $latest !== '' ? $latest : $current;
        $minVersion = trim((string) ($check['min_version'] ?? ''));
        $minBlocked = !empty($check['min_version_blocked']);

        $plugins = [];
        $incompatCurrent = [];
        $incompatTarget = [];
        $missingRequires = [];

        $rows = Plugin::where('installed', 1)->order('identifier', 'asc')->select();
        foreach ($rows as $row) {
            $identifier = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($identifier === '') {
                continue;
            }
            $manifest = $this->pluginService->readManifest($identifier);
            $name = is_array($manifest)
                ? trim((string) ($manifest['name'] ?? $identifier))
                : (string) ($row['name'] ?? $identifier);
            $constraint = is_array($manifest) ? $this->coreRequirement->pivarkCoreConstraint($manifest) : '';
            $pluginVer = is_array($manifest)
                ? trim((string) ($manifest['version'] ?? ($row['version'] ?? '')))
                : trim((string) ($row['version'] ?? ''));

            $entry = [
                'identifier'       => $identifier,
                'name'             => $name !== '' ? $name : $identifier,
                'version'          => $pluginVer,
                'pivark_core'      => $constraint,
                'enabled'          => (int) ($row['enabled'] ?? 0) === 1 ? 1 : 0,
                'ok_current'       => 1,
                'ok_target'        => 1,
                'current_errors'   => [],
                'target_errors'    => [],
            ];

            if (!is_array($manifest) || empty($manifest['_manifest_valid'])) {
                $entry['ok_current'] = 0;
                $entry['current_errors'] = ['plugin.json 无效或缺失'];
                $incompatCurrent[] = $entry;
                $plugins[] = $entry;
                continue;
            }

            if ($constraint === '') {
                $missingRequires[] = [
                    'identifier' => $identifier,
                    'name'       => $entry['name'],
                    'version'    => $pluginVer,
                ];
            }

            $curCoreErr = $this->coreRequirement->pivarkCoreRuntimeErrors($manifest, null);
            if ($curCoreErr !== []) {
                $entry['ok_current'] = 0;
                $entry['current_errors'] = $curCoreErr;
                $incompatCurrent[] = $entry;
            }

            if ($hasUpdate && $target !== '' && $target !== $current) {
                $tgtCoreErr = $this->coreRequirement->pivarkCoreRuntimeErrors($manifest, $target);
                if ($tgtCoreErr !== []) {
                    $entry['ok_target'] = 0;
                    $entry['target_errors'] = $tgtCoreErr;
                    $incompatTarget[] = $entry;
                }
            }

            $plugins[] = $entry;
        }

        $blockers = [];
        $warnings = [];

        if ($minBlocked) {
            $blockers[] = '当前核心 V' . $current . ' 低于最低升级阶梯 V' . $minVersion
                . '，请先升级到中间版本，不可直达 V' . $latest;
        }
        foreach ($incompatCurrent as $row) {
            $warnings[] = '插件 ' . ($row['name'] ?? $row['identifier'])
                . ' 与当前核心不兼容：' . implode('；', $row['current_errors'] ?? []);
        }
        foreach ($incompatTarget as $row) {
            $blockers[] = '升到核心 V' . $target . ' 后插件 '
                . ($row['name'] ?? $row['identifier'])
                . ' 将不兼容：' . implode('；', $row['target_errors'] ?? [])
                . '（请先升级或停用该插件）';
        }
        foreach ($missingRequires as $row) {
            $warnings[] = '插件 ' . ($row['name'] ?? $row['identifier'])
                . ' 未声明 requires.pivark_core，升级后兼容性无法保证';
        }

        $canStart = !$minBlocked && $incompatTarget === [];
        if ($hasUpdate && empty($check['can_apply'])) {
            $canStart = false;
            if (!$minBlocked) {
                $blockers[] = trim((string) ($check['upgrade_notice'] ?? '当前不可在线升级核心'));
            }
        }

        $order = 'ok';
        $orderLabel = '无需升级或可按当前状态操作';
        if ($minBlocked) {
            $order = 'core_ladder_first';
            $orderLabel = '先升中间核心版本（阶梯），再升最新';
        } elseif ($incompatTarget !== []) {
            $order = 'plugins_before_core';
            $orderLabel = '先升级或停用不兼容插件，再升整站核心';
        } elseif ($incompatCurrent !== []) {
            $order = 'fix_plugins';
            $orderLabel = '当前已有插件与核心不兼容，建议先处理插件';
        } elseif ($hasUpdate && $canStart) {
            $order = 'core_then_plugins';
            $orderLabel = '可升整站核心；完成后检查插件是否有新版本';
        }

        $summary = $this->buildSummary(
            $current,
            $target,
            $hasUpdate,
            $minBlocked,
            count($incompatCurrent),
            count($incompatTarget),
            count($missingRequires),
            $canStart
        );

        return [
            'current_core'             => $current,
            'target_core'              => $target,
            'has_core_update'          => $hasUpdate,
            'min_version'              => $minVersion,
            'min_version_blocked'      => $minBlocked,
            'plugins'                  => $plugins,
            'incompatible_current'     => $incompatCurrent,
            'incompatible_target'      => $incompatTarget,
            'missing_core_requires'    => $missingRequires,
            'blockers'                 => array_values(array_unique(array_filter($blockers))),
            'warnings'                 => array_values(array_unique(array_filter($warnings))),
            'recommended_order'        => $order,
            'recommended_order_label'  => $orderLabel,
            'can_start_core_upgrade'   => $canStart && $hasUpdate && !empty($check['can_apply']),
            'summary'                  => $summary,
        ];
    }

    private function buildSummary(
        string $current,
        string $target,
        bool $hasUpdate,
        bool $minBlocked,
        int $badCurrent,
        int $badTarget,
        int $missing,
        bool $canStart
    ): string {
        if ($minBlocked) {
            return '核心跨度过大，须先走升级阶梯，不可直达最新。';
        }
        if ($badTarget > 0) {
            return '目标核心 V' . $target . ' 与 ' . $badTarget . ' 个已装插件不兼容，已阻止开始升级。';
        }
        if ($hasUpdate && $canStart) {
            $extra = $missing > 0 ? '（另有 ' . $missing . ' 个插件未声明内核约束）' : '';

            return '可从 V' . $current . ' 升级到 V' . $target . '；已装插件与目标核心兼容' . $extra . '。';
        }
        if ($badCurrent > 0) {
            return '当前核心下有 ' . $badCurrent . ' 个插件不兼容，请先处理插件。';
        }
        if (!$hasUpdate) {
            return '核心已是最新；已扫描已装插件与当前核心的兼容性。';
        }

        return '暂不可在线升级核心，请查看阻止原因。';
    }
}
