<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\registry;

use app\common\service\plugin\PluginService;

/** Weapp*Gateway 契约代际 SSOT（manifest min_gateway_version / api_version） */
final class PluginApiVersionRegistry
{
    /** @var array<string, list<string>> 代际 => 代表性 Gateway 方法（文档/门禁） */
    private const GATEWAY_METHODS_BY_VERSION = [
        '1.0' => [
            'weappDb',
            'documentListPublic',
            'documentSaveAdmin',
            'pluginRouteRegister',
            'eventDispatch',
            'uploadAttachment',
        ],
    ];

    public static function currentGatewayVersion(): string
    {
        $v = trim((string) config('pivark.plugin_core_api_version', '1.0'));

        return $v !== '' ? $v : '1.0';
    }

    /** @return list<string> */
    public static function catalogMethods(string $version): array
    {
        return self::GATEWAY_METHODS_BY_VERSION[$version] ?? [];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function validateManifest(array $manifest): array
    {
        $min = trim((string) ($manifest['min_gateway_version'] ?? ''));
        if ($min === '') {
            $min = trim((string) ($manifest['api_version'] ?? '1.0'));
        }
        if ($min === '') {
            $min = '1.0';
        }
        if (!preg_match('/^\d+\.\d+(\.\d+)?$/', $min)) {
            return ['min_gateway_version / api_version 须为语义化版本（如 1.0）'];
        }
        $current = self::currentGatewayVersion();
        if (version_compare($current, $min, '<')) {
            return [
                '插件需要 Gateway ≥ ' . $min . '，当前核心 Gateway 为 ' . $current,
            ];
        }

        return [];
    }

    public function assertCompatible(string $identifier): void
    {
        if (!(bool) config('plugin.security.gateway_version_enforce', true)) {
            return;
        }
        $manifest = app(PluginService::class)->readManifest($identifier);
        if (!is_array($manifest)) {
            return;
        }
        $errors = $this->validateManifest($manifest);
        if ($errors !== []) {
            throw new \RuntimeException($errors[0]);
        }
    }
}
