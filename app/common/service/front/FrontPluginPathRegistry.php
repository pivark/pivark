<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\front;

use app\common\service\plugin\gateway\PluginGatewayCallerContext;
use think\Response;

/** 前台 pathPaged 插件路径扩展（front.plugin.path） */
final class FrontPluginPathRegistry
{

    /** @var array<string, array{handler:callable(string): (Response|null), identifier:?string}> */
    private static array $handlers = [];

    public function reset(): void
    {
        self::$handlers = [];
    }

    /** @param callable(string): (Response|null) $handler */
    public function register(string $pathPrefix, callable $handler, ?string $identifier = null): void
    {
        $pathPrefix = trim($pathPrefix, '/');
        if ($pathPrefix === '') {
            return;
        }
        if ($identifier === null || trim($identifier) === '') {
            $identifier = PluginGatewayCallerContext::currentIdentifier();
        }
        $identifier = $identifier !== null ? strtolower(trim($identifier)) : null;
        if ($identifier === '') {
            $identifier = null;
        }
        self::$handlers[$pathPrefix] = [
            'handler'    => $handler,
            'identifier' => $identifier,
        ];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        $norm = str_replace('_', '-', $identifier);
        $dir  = str_replace('-', '_', $identifier);
        foreach (self::$handlers as $prefix => $entry) {
            $owned = strtolower((string) ($entry['identifier'] ?? ''));
            $p     = strtolower((string) $prefix);
            if ($owned !== '' && (str_replace('_', '-', $owned) === $norm || $owned === $identifier)) {
                unset(self::$handlers[$prefix]);
                continue;
            }
            if ($p === $identifier || $p === $norm || $p === $dir) {
                unset(self::$handlers[$prefix]);
            }
        }
    }

    public function dispatch(string $pathPrefix, string $segment): ?Response
    {
        $pathPrefix = trim($pathPrefix, '/');
        if ($pathPrefix === '' || !isset(self::$handlers[$pathPrefix])) {
            return null;
        }
        $result = (self::$handlers[$pathPrefix]['handler'])($segment);

        return $result instanceof Response ? $result : null;
    }
}
