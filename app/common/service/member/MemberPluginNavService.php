<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\boot\PluginBootService;
use app\common\service\plugin\PluginService;
use app\common\model\Plugin;

/** 前台会员中心 — 插件 manifest surfaces.member_center 导航 */
class MemberPluginNavService
{

    public function __construct(
        private readonly PluginService $plugins,
        private readonly EntitlementService $entitlements,
        private readonly PluginBootService $pluginBoot,
        private readonly MemberCenterNavRegistry $memberCenterNavRegistry,
    ) {
    }

    /**
     * @return list<array{identifier:string,label:string,url:string,route:string,icon:string}>
     */
    public function listForMemberCenter(): array
    {
        $this->pluginBoot->bootstrapEnabled();
        $out = [];
        $rows = Plugin::where('installed', 1)->where('enabled', 1)->select()->toArray();
        foreach ($rows as $row) {
            $identifier = (string) ($row['identifier'] ?? '');
            $manifest   = $this->plugins->readManifest($identifier);
            if ($manifest === null) {
                continue;
            }
            if ($identifier === '' || !$this->entitlements->can($identifier)) {
                continue;
            }
            $surfaces = is_array($manifest['surfaces'] ?? null) ? $manifest['surfaces'] : [];
            $mc       = is_array($surfaces['member_center'] ?? null) ? $surfaces['member_center'] : [];
            if (empty($mc['enabled'])) {
                continue;
            }
            foreach ($this->entriesFromManifest($identifier, $mc, $manifest) as $entry) {
                if (!$this->memberCenterNavRegistry->isRouteVisible($identifier, $entry)) {
                    continue;
                }
                $icon = trim((string) ($entry['icon'] ?? ''));
                if ($icon === '') {
                    $icon = trim((string) ($mc['icon'] ?? ''));
                }
                if ($icon === '') {
                    $icon = 'bi-puzzle';
                }
                $out[] = [
                    'identifier' => $entry['identifier'],
                    'label'      => $entry['label'],
                    'url'        => $entry['url'],
                    'route'      => $entry['route'],
                    'icon'       => $icon,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $mc
     * @param array<string, mixed> $manifest
     * @return list<array{identifier:string,label:string,url:string,route:string}>
     */
    private function entriesFromManifest(string $identifier, array $mc, array $manifest): array
    {
        $entries = [];
        $mainRoute = trim((string) ($mc['route'] ?? ''));
        if ($mainRoute !== '') {
            $entries[] = [
                'identifier' => $identifier,
                'label'      => (string) ($mc['label'] ?? $manifest['name'] ?? $identifier),
                'url'        => '/member/' . ltrim($mainRoute, '/'),
                'route'      => $mainRoute,
            ];
        }
        $extras = $mc['extra_routes'] ?? [];
        if (!is_array($extras)) {
            return $entries;
        }
        foreach ($extras as $extra) {
            if (!is_array($extra)) {
                continue;
            }
            $route = trim((string) ($extra['route'] ?? ''));
            if ($route === '') {
                continue;
            }
            $entry = [
                'identifier' => $identifier,
                'label'      => (string) ($extra['label'] ?? $route),
                'url'        => '/member/' . ltrim($route, '/'),
                'route'      => $route,
            ];
            $extraIcon = trim((string) ($extra['icon'] ?? ''));
            if ($extraIcon !== '') {
                $entry['icon'] = $extraIcon;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

}
