<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\registry;

use app\common\contract\PluginApiException;
use app\common\contract\PluginApiInterface;
use app\common\service\plugin\boot\PluginRuntimeFaultGuard;

/** 插件 API 注册表（跨插件唯一入口） */
final class PluginApiRegistry
{
    /** @var array<string, array<string, PluginApiInterface>> pluginId => apiName => instance */
    private static array $apis = [];

    public function register(string $apiName, PluginApiInterface $api): void
    {
        $pluginId = trim($api->pluginIdentifier());
        $apiName  = trim($apiName);
        if ($pluginId === '' || $apiName === '') {
            throw new \InvalidArgumentException('pluginIdentifier 与 apiName 不能为空');
        }
        self::$apis[$pluginId][$apiName] = $api;
    }

    public function resolve(string $pluginId, string $apiName): ?PluginApiInterface
    {
        $pluginId = trim($pluginId);
        $apiName  = trim($apiName);

        return self::$apis[$pluginId][$apiName] ?? null;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function invoke(string $pluginId, string $apiName, string $method, array $params = []): mixed
    {
        $pluginId = trim($pluginId);
        $apiName  = trim($apiName);
        $method   = trim($method);

        $api = $this->resolve($pluginId, $apiName);
        if ($api === null) {
            throw new PluginApiException(
                sprintf('插件 API 未注册：%s / %s', $pluginId, $apiName),
            );
        }

        return PluginRuntimeFaultGuard::invoke(
            static fn (): mixed => $api->call($method, $params),
            'plugin_api_invoke_failed',
            [
                'plugin_id' => $pluginId,
                'api_name'  => $apiName,
                'method'    => $method,
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function registeredNames(string $pluginId): array
    {
        $pluginId = trim($pluginId);
        if ($pluginId === '' || !isset(self::$apis[$pluginId])) {
            return [];
        }

        return array_keys(self::$apis[$pluginId]);
    }

    public function reset(): void
    {
        self::$apis = [];
    }

    public function removeForIdentifier(string $pluginId): void
    {
        $pluginId = strtolower(trim($pluginId));
        if ($pluginId === '') {
            return;
        }
        unset(self::$apis[$pluginId]);
    }
}
