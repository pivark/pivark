<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

use app\common\service\plugin\registry\PluginExtensionRegistry;
use app\common\service\weapp\WeappItemGateway;
use app\common\service\weapp\WeappPluginGateway;

/** 读 plugin.json extensions → 各 Registry（manifest 自动加载 · batch 9） */
final class PluginExtensionManifestLoader
{
    /** @var list<string> */
    private const SUPPORTED_KEYS = [
        'document_save',
        'product_tab_after_persist',
        'item_after_save',
        'plugin_sku_tier_apply',
    ];

    public function applyForIdentifier(string $identifier, ?array $manifest): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !is_array($manifest)) {
            return;
        }
        $extensions = $manifest['extensions'] ?? null;
        if (!is_array($extensions) || $extensions === []) {
            return;
        }

        foreach ($extensions as $key => $cfg) {
            $key = strtolower(trim((string) $key));
            if ($key === '' || !in_array($key, self::SUPPORTED_KEYS, true)) {
                continue;
            }
            if ($key === 'document_save') {
                if (!is_array($cfg)) {
                    continue;
                }
                foreach ($this->normalizeExtensionConfigs($cfg) as $one) {
                    $this->applyExtension($identifier, $key, $one);
                }
                continue;
            }
            if (!is_array($cfg)) {
                continue;
            }
            $this->applyExtension($identifier, $key, $cfg);
        }
    }

    /**
     * @return list<string>
     */
    public function validate(?array $manifest): array
    {
        if (!is_array($manifest)) {
            return ['manifest 非对象'];
        }
        $extensions = $manifest['extensions'] ?? null;
        if ($extensions === null) {
            return [];
        }
        if (!is_array($extensions)) {
            return ['extensions 须为对象'];
        }

        $errors = [];
        foreach ($extensions as $key => $cfg) {
            $key = strtolower(trim((string) $key));
            if ($key === '' || !in_array($key, self::SUPPORTED_KEYS, true)) {
                $errors[] = 'extensions.' . $key . ' 暂不支持自动加载';
                continue;
            }
            if ($key === 'document_save') {
                if (!is_array($cfg)) {
                    $errors[] = 'extensions.document_save 须为对象或数组';
                    continue;
                }
                foreach ($this->normalizeExtensionConfigs($cfg) as $idx => $one) {
                    $errors = array_merge($errors, $this->validateDocumentSaveConfig($idx, $one));
                }
                continue;
            }
            if (!is_array($cfg)) {
                $errors[] = 'extensions.' . $key . ' 须为对象';
                continue;
            }
            $handler = trim((string) ($cfg['handler'] ?? ''));
            if ($handler === '' || !str_contains($handler, '::')) {
                $errors[] = 'extensions.' . $key . '.handler 无效';
            }
            if ($key === 'product_tab_after_persist') {
                $keys = $cfg['post_keys'] ?? null;
                if (!is_array($keys) || $keys === []) {
                    $errors[] = 'extensions.product_tab_after_persist.post_keys 必填';
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $cfg
     * @return list<array<string, mixed>>
     */
    private function normalizeExtensionConfigs(array $cfg): array
    {
        if (isset($cfg['handler']) || isset($cfg['post_keys'])) {
            return [$cfg];
        }
        $out = [];
        foreach ($cfg as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $cfg
     * @return list<string>
     */
    private function validateDocumentSaveConfig(int|string $idx, array $cfg): array
    {
        $label = is_int($idx) ? 'extensions.document_save[' . $idx . ']' : 'extensions.document_save';
        $errors = [];
        $handler = trim((string) ($cfg['handler'] ?? ''));
        if ($handler === '' || !str_contains($handler, '::')) {
            $errors[] = $label . '.handler 无效';
        }
        $keys = $cfg['post_keys'] ?? null;
        if (!is_array($keys) || $keys === []) {
            $errors[] = $label . '.post_keys 必填';
        }
        $assetSync = trim((string) ($cfg['asset_sync_handler'] ?? ''));
        if ($assetSync !== '' && !str_contains($assetSync, '::')) {
            $errors[] = $label . '.asset_sync_handler 无效';
        }

        return $errors;
    }

    /** @param array<string, mixed> $cfg */
    private function applyExtension(string $identifier, string $key, array $cfg): void
    {
        $handler = $this->resolveCallable((string) ($cfg['handler'] ?? ''));
        if ($handler === null) {
            return;
        }

        if ($key === 'document_save') {
            $postKeys = [];
            foreach ((array) ($cfg['post_keys'] ?? []) as $postKey) {
                $postKey = trim((string) $postKey);
                if ($postKey !== '') {
                    $postKeys[] = $postKey;
                }
            }
            if ($postKeys === []) {
                return;
            }
            $meta = ['post_keys' => $postKeys];
            if (!empty($cfg['shared'])) {
                $meta['shared'] = true;
            }
            $assetSync = $this->resolveDocumentAssetSyncCallable((string) ($cfg['asset_sync_handler'] ?? ''));
            if ($assetSync !== null) {
                $meta['asset_sync'] = $assetSync;
            }
            app(PluginExtensionRegistry::class)->register(
                PluginExtensionRegistry::POINT_DOCUMENT_ADDON_SAVE,
                $identifier,
                $handler,
                (int) ($cfg['priority'] ?? 50),
                $meta,
            );

            return;
        }

        if ($key === 'product_tab_after_persist') {
            $postKeys = [];
            foreach ((array) ($cfg['post_keys'] ?? []) as $postKey) {
                $postKey = trim((string) $postKey);
                if ($postKey !== '') {
                    $postKeys[] = $postKey;
                }
            }
            if ($postKeys === []) {
                return;
            }
            app(WeappItemGateway::class)->productTabAfterPersistRegister(
                $identifier,
                $postKeys,
                $handler,
                (int) ($cfg['priority'] ?? 100),
            );

            return;
        }

        if ($key === 'item_after_save') {
            app(WeappItemGateway::class)->itemAfterSaveRegister(
                $identifier,
                $handler,
                (int) ($cfg['priority'] ?? 100),
            );

            return;
        }

        if ($key === 'plugin_sku_tier_apply') {
            app(WeappPluginGateway::class)->pluginSkuTierApplyRegister($identifier, $handler);
        }
    }

    /** @return (callable(int): void)|null */
    private function resolveDocumentAssetSyncCallable(string $handler): ?callable
    {
        $resolved = $this->resolveCallable($handler);
        if ($resolved === null) {
            return null;
        }

        return static function (int $documentId) use ($resolved): void {
            $resolved($documentId);
        };
    }

    /** @return (callable)|null */
    private function resolveCallable(string $handler): ?callable
    {
        $handler = trim($handler);
        if ($handler === '' || !str_contains($handler, '::')) {
            return null;
        }
        [$class, $method] = explode('::', $handler, 2);
        $class  = trim($class);
        $method = trim($method);
        if ($class === '' || $method === '' || !class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        return [$class, $method];
    }
}
