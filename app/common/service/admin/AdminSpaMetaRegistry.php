<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;


/**
 * 后台 SPA / 模块页 meta 扩展点（admin.spa_meta）
 *
 * 按 bucket 聚合插件贡献的 JSON 片段（如 product_center · shopCenterNav）。
 */
final class AdminSpaMetaRegistry
{

    /** @var array<string, list<array{identifier:string, handler:callable, priority:int}>> */
    private static array $handlersByBucket = [];

    public function reset(): void
    {
        self::$handlersByBucket = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        foreach (array_keys(self::$handlersByBucket) as $bucket) {
            self::$handlersByBucket[$bucket] = array_values(array_filter(
                self::$handlersByBucket[$bucket],
                static fn (array $entry): bool => strtolower(trim((string) ($entry['identifier'] ?? ''))) !== $identifier,
            ));
            if (self::$handlersByBucket[$bucket] === []) {
                unset(self::$handlersByBucket[$bucket]);
            }
        }
    }

    /**
     * @param callable(array<string,mixed>): array<string,mixed> $handler
     */
    public function register(string $identifier, string $bucket, callable $handler, int $priority = 100): void
    {
        $identifier = trim($identifier);
        $bucket     = trim($bucket);
        if ($identifier === '' || $bucket === '') {
            return;
        }
        self::$handlersByBucket[$bucket] ??= [];
        self::$handlersByBucket[$bucket][] = [
            'identifier' => $identifier,
            'handler'    => $handler,
            'priority'   => $priority,
        ];
        usort(self::$handlersByBucket[$bucket], static function (array $a, array $b): int {
            return ($a['priority'] <=> $b['priority']) ?: strcmp($a['identifier'], $b['identifier']);
        });
    }

    /**
     * 合并 bucket 内全部 handler 返回值（后者覆盖同键）。
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function collectMerged(string $bucket, array $ctx = []): array
    {
        $bucket = trim($bucket);
        if ($bucket === '' || !isset(self::$handlersByBucket[$bucket])) {
            return [];
        }
        $merged = [];
        foreach (self::$handlersByBucket[$bucket] as $entry) {
            $chunk = ($entry['handler'])($ctx);
            if (!is_array($chunk) || $chunk === []) {
                continue;
            }
            $merged = array_merge($merged, $chunk);
        }

        return $merged;
    }

    /**
     * 取 bucket 内第一个非空 handler 结果（品项中心 shopCenterNav 等单对象 meta）。
     *
     * @param array<string, mixed> $ctx
     */
    public function collectFirst(string $bucket, array $ctx = []): ?array
    {
        $bucket = trim($bucket);
        if ($bucket === '' || !isset(self::$handlersByBucket[$bucket])) {
            return null;
        }
        foreach (self::$handlersByBucket[$bucket] as $entry) {
            $chunk = ($entry['handler'])($ctx);
            if (is_array($chunk) && $chunk !== []) {
                return $chunk;
            }
        }

        return null;
    }

    public function hasBucket(string $bucket): bool
    {
        $bucket = trim($bucket);

        return $bucket !== '' && !empty(self::$handlersByBucket[$bucket]);
    }
}
