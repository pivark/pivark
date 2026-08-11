<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\channel;
use app\common\service\channel\MiniprogramConfigService;
use app\common\service\channel\MiniprogramFeatureRegistry;
use app\common\service\channel\MiniprogramChannelService;

use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;

/** 小程序端可用的已启用插件能力（与 Entitlement 对齐） */
final class MiniprogramFeatureService
{

    public function __construct(
        private readonly MiniprogramChannelService $miniprogramChannelService,
        private readonly PluginService $pluginService,
        private readonly EntitlementService $entitlementService,
        private readonly MiniprogramConfigService $miniprogramConfigService,
    ) {
    }

    /** @return list<string> */
    public function enabledKeys(): array
    {
        if (!$this->miniprogramChannelService->isLicensed()) {
            return [];
        }

        $out = [];
        foreach (app(MiniprogramFeatureRegistry::class)->entries() as $id => $meta) {
            if (!$this->isFeatureEnabled($id, $meta)) {
                continue;
            }
            $out[] = $id;
        }

        return $out;
    }

    /**
     * @param array{label:string, needs_login:bool, enabled_checker:?callable} $meta
     */
    private function isFeatureEnabled(string $identifier, array $meta): bool
    {
        $checker = $meta['enabled_checker'] ?? null;
        if (is_callable($checker)) {
            return (bool) $checker();
        }

        return $this->pluginService->isEnabled($identifier) && $this->entitlementService->can($identifier);
    }

    /** @return list<array{key:string,label:string,needs_login:bool}> */
    public function forClient(): array
    {
        $list = [];
        foreach (app(MiniprogramFeatureRegistry::class)->entries() as $key => $meta) {
            if (!in_array($key, $this->enabledKeys(), true)) {
                continue;
            }
            $list[] = [
                'key'         => $key,
                'label'       => (string) ($meta['label'] ?? $key),
                'needs_login' => !empty($meta['needs_login']),
            ];
        }

        $edition = $this->miniprogramConfigService->edition();
        $list[]  = [
            'key'         => 'mp_edition_' . $edition,
            'label'       => $edition === MiniprogramConfigService::EDITION_SHOP ? '商城版' : '内容版',
            'needs_login' => false,
        ];

        return $list;
    }
}
