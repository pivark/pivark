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

/** 前台早返回（如迁站遗留 URL 301），插件经 WeappFrontGateway 注册 */
final class FrontEarlyResponseRegistry
{
    /** @var list<array{handler:callable(): (Response|null), identifier:?string}> */
    private static array $handlers = [];

    public function reset(): void
    {
        self::$handlers = [];
    }

    /** @param callable(): (Response|null) $handler */
    public function register(callable $handler, ?string $identifier = null): void
    {
        if ($identifier === null || trim($identifier) === '') {
            $identifier = PluginGatewayCallerContext::currentIdentifier();
        }
        $identifier = $identifier !== null ? strtolower(trim($identifier)) : null;
        if ($identifier === '') {
            $identifier = null;
        }
        if ($identifier !== null) {
            $this->removeForIdentifier($identifier);
        }
        self::$handlers[] = [
            'handler'    => $handler,
            'identifier' => $identifier,
        ];
    }

    public function tryResponse(): ?Response
    {
        foreach (self::$handlers as $entry) {
            try {
                $resp = ($entry['handler'])();
                if ($resp instanceof Response) {
                    return $resp;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        self::$handlers = array_values(array_filter(
            self::$handlers,
            static fn (array $e): bool => ($e['identifier'] ?? null) !== $identifier
        ));
    }
}
