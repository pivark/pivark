<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\contract;

/** 默认 PluginApiInterface::call 分派到同名 public 方法 */
trait PluginApiCallableTrait
{
    /**
     * @param array<int|string, mixed> $params
     */
    public function call(string $method, array $params = []): mixed
    {
        $method = trim($method);
        if ($method === '' || $method === 'call' || $method === 'pluginIdentifier') {
            throw new PluginApiException(
                sprintf('插件 API 方法不可用：%s::%s', static::class, $method),
            );
        }
        try {
            $ref = new \ReflectionMethod($this, $method);
        } catch (\ReflectionException) {
            throw new PluginApiException(
                sprintf('插件 API 方法不存在：%s::%s', static::class, $method),
            );
        }
        if (!$ref->isPublic()) {
            throw new PluginApiException(
                sprintf('插件 API 方法不可调用：%s::%s', static::class, $method),
            );
        }

        return $ref->invoke($this, ...array_values($params));
    }
}
