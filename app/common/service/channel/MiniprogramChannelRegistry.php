<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\channel;

use app\common\service\auth\SocialAuthCapabilityRegistry;
use app\common\service\config\ConfigService;
use app\common\service\plugin\PluginService;

/** 小程序市场 SKU ↔ hub 插件 ↔ 工程 app 目录；hub / SKU 均由插件 boot 注册 */
final class MiniprogramChannelRegistry
{

    /** @var array<string, array{label:string, guide_route:string, social_auth_required:bool}> */
    private static array $hubEntries = [];

    /** @var array<string, array<string, mixed>> 插件注册的市场 SKU（优先于 config） */
    private static array $marketSkuEntries = [];

    public function resetHubRegistrations(): void
    {
        self::$hubEntries = [];
        self::$marketSkuEntries = [];
    }

    /**
     * @param array{label?:string, guide_route?:string, social_auth_required?:bool} $entry
     */
    public function registerHub(string $identifier, array $entry): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        $label = trim((string) ($entry['label'] ?? $identifier));
        $guide = trim((string) ($entry['guide_route'] ?? ''));
        if ($label === '' || $guide === '') {
            return;
        }
        self::$hubEntries[$identifier] = [
            'label'                => $label,
            'guide_route'          => $guide,
            'social_auth_required' => (bool) ($entry['social_auth_required'] ?? false),
        ];
    }

    /**
     * 插件注册市场 SKU 行（虚拟 SKU 亦可；内核 config 仅作空壳/兼容合并）
     *
     * @param array<string, mixed> $row
     */
    public function registerMarketSku(string $sku, array $row): void
    {
        $sku = strtolower(trim($sku));
        if ($sku === '' || $row === []) {
            return;
        }
        self::$marketSkuEntries[$sku] = $row;
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        unset(self::$hubEntries[$identifier]);
        foreach (self::$marketSkuEntries as $sku => $row) {
            $hub = strtolower(trim((string) ($row['hub'] ?? '')));
            if ($sku === $identifier || $hub === $identifier) {
                unset(self::$marketSkuEntries[$sku]);
            }
        }
    }

    public function isRegisteredHub(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));

        return $identifier !== '' && isset(self::$hubEntries[$identifier]);
    }

    public function hubPlugin(): string
    {
        $selected = strtolower(trim((string) app(ConfigService::class)->get('mp_miniprogram_hub_plugin', '')));
        if ($selected !== '' && $this->isRegisteredHub($selected)) {
            return $selected;
        }
        $fallback = strtolower(trim((string) config('miniprogram_channels.hub_plugin', '')));
        if ($fallback !== '' && $this->isRegisteredHub($fallback)) {
            return $fallback;
        }
        foreach (array_keys(self::$hubEntries) as $id) {
            return $id;
        }

        return '';
    }

    /**
     * @return array{label:string, guide_route:string, social_auth_required:bool}|null
     */
    public function hubEntry(?string $identifier = null): ?array
    {
        $id = strtolower(trim($identifier ?? $this->hubPlugin()));
        if ($id === '' || !isset(self::$hubEntries[$id])) {
            return null;
        }

        return self::$hubEntries[$id];
    }

    public function guideRoute(?string $identifier = null): string
    {
        $entry = $this->hubEntry($identifier);
        if ($entry !== null) {
            $route = trim((string) ($entry['guide_route'] ?? ''));
            if ($route !== '') {
                return $route;
            }
        }

        return trim((string) config('miniprogram_channels.admin_spa.hub_guide', ''));
    }

    public function hubRequiresSocialAuth(?string $identifier = null): bool
    {
        $entry = $this->hubEntry($identifier);

        return (bool) ($entry['social_auth_required'] ?? false);
    }

    public function hasSocialAuth(): bool
    {
        if (!$this->hubRequiresSocialAuth()) {
            return true;
        }

        return app(SocialAuthCapabilityRegistry::class)->hasActiveProvider();
    }

    /** @return list<array{id:string, label:string, guide_route:string, enabled:bool}> */
    public function hubOptionsForAdmin(): array
    {
        $out = [];
        foreach (self::$hubEntries as $id => $entry) {
            $out[] = [
                'id'           => $id,
                'label'        => (string) ($entry['label'] ?? $id),
                'guide_route'  => (string) ($entry['guide_route'] ?? ''),
                'enabled'      => app(PluginService::class)->isEnabled($id),
            ];
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    public function marketSkus(): array
    {
        $fromConfig = config('miniprogram_channels.market_skus');
        $configRows = is_array($fromConfig) ? $fromConfig : [];

        // 插件注册优先；config 仅兼容旧部署自定义行
        return array_merge($configRows, self::$marketSkuEntries);
    }

    /**
     * @return array{sku:string,hub:string,platform:string,edition:string,app_dir:string,app_id_config:string,virtual:bool}|null
     */
    public function resolveMarketSku(string $identifier): ?array
    {
        $sku = strtolower(trim($identifier));
        if ($sku === '') {
            return null;
        }

        $rows = $this->marketSkus();
        if (!isset($rows[$sku]) || !is_array($rows[$sku])) {
            $hub = $this->hubPlugin();
            if ($hub !== '' && $sku === $hub) {
                return $this->normalizeSkuRow($sku, [
                    'hub'           => $hub,
                    'platform'      => 'wechat',
                    'edition'       => MiniprogramConfigService::EDITION_CONTENT,
                    'app_dir'       => 'wechat-content',
                    'app_id_config' => 'social_auth_mp_wechat_app_id',
                ]);
            }

            return null;
        }

        return $this->normalizeSkuRow($sku, $rows[$sku]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{sku:string,hub:string,platform:string,edition:string,app_dir:string,app_id_config:string,virtual:bool}
     */
    private function normalizeSkuRow(string $sku, array $row): array
    {
        $hubDefault = $this->hubPlugin();
        $hub = strtolower(trim((string) ($row['hub'] ?? $hubDefault)));

        return [
            'sku'           => $sku,
            'hub'           => $hub !== '' ? $hub : $hubDefault,
            'platform'      => strtolower(trim((string) ($row['platform'] ?? 'wechat'))) ?: 'wechat',
            'edition'       => strtolower(trim((string) ($row['edition'] ?? MiniprogramConfigService::EDITION_CONTENT)))
                ?: MiniprogramConfigService::EDITION_CONTENT,
            'app_dir'       => trim((string) ($row['app_dir'] ?? 'wechat-content')) ?: 'wechat-content',
            'app_id_config' => trim((string) ($row['app_id_config'] ?? 'social_auth_mp_wechat_app_id'))
                ?: 'social_auth_mp_wechat_app_id',
            'virtual'       => !empty($row['virtual']),
        ];
    }

    /** @return list<string> */
    public function allSkuIdentifiers(): array
    {
        return array_keys($this->marketSkus());
    }

    /**
     * 虚拟 SKU 的 plugin.json 等价信息（市场列表/购买）
     *
     * @return array<string, mixed>|null
     */
    public function pseudoManifest(string $sku): ?array
    {
        $resolved = $this->resolveMarketSku($sku);
        if ($resolved === null) {
            return null;
        }

        $rows = $this->marketSkus();
        $row  = $rows[$sku] ?? [];

        $mp = is_array($row['miniprogram'] ?? null) ? $row['miniprogram'] : [
            'platform' => $resolved['platform'],
            'edition'  => $resolved['edition'],
        ];

        return [
            'identifier'     => $sku,
            'name'         => (string) ($row['name'] ?? $sku),
            'description'  => (string) ($row['description'] ?? ''),
            'version'      => '1.0.0',
            'kind'         => 'miniprogram',
            'miniprogram'  => $mp,
            'author'       => '元舟 PivArk 官方',
            'color'        => '#07c160',
            'commercial'   => is_array($row['commercial'] ?? null) ? $row['commercial'] : [],
            'publisher_type' => 'official',
            'package'      => 'pivark/' . $sku,
            '_manifest_valid' => true,
        ];
    }

    /**
     * @return list<array{sku:string,edition:string,label:string,licensed:bool,app_dir:string}>
     */
    public function editionsForAdmin(): array
    {
        $out = [];
        foreach ($this->marketSkus() as $sku => $row) {
            if (!is_array($row)) {
                continue;
            }
            $mp = is_array($row['miniprogram'] ?? null) ? $row['miniprogram'] : [];
            $out[] = [
                'sku'      => (string) $sku,
                'edition'  => (string) ($row['edition'] ?? ''),
                'label'    => (string) ($mp['label'] ?? $row['name'] ?? $sku),
                'licensed' => app(MiniprogramChannelService::class)->isSkuLicensed((string) $sku),
                'app_dir'  => (string) ($row['app_dir'] ?? ''),
            ];
        }

        return $out;
    }
}
