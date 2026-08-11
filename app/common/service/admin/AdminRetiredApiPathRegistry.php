<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;


/** 插件 load-time 登记的已退役后台 API 前缀（中间件短路，不依赖路由 alias） */
final class AdminRetiredApiPathRegistry
{

    /** @var array<string, string> admin 相对前缀 => 提示文案 */
    private static array $prefixMessages = [];

    public function reset(): void
    {
        self::$prefixMessages = [];
    }

    public function registerPrefix(string $adminRelativePrefix, string $message): void
    {
        $prefix = strtolower(trim(str_replace('\\', '/', $adminRelativePrefix), '/'));
        if ($prefix === '') {
            return;
        }
        self::$prefixMessages[$prefix] = trim($message) !== '' ? trim($message) : 'API 已迁移';
    }

    /** @return array{blocked:bool,message:string} */
    public function match(string $path): array
    {
        foreach (self::normalizeAdminRelativePaths($path) as $normalized) {
            foreach (self::$prefixMessages as $prefix => $message) {
                if ($normalized === $prefix || str_starts_with($normalized, $prefix . '/')) {
                    return ['blocked' => true, 'message' => $message];
                }
            }
        }

        return ['blocked' => false, 'message' => ''];
    }

    /** @return list<string> */
    private static function normalizeAdminRelativePaths(string $path): array
    {
        $path = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($path === '') {
            return [];
        }
        $candidates = [$path];
        if (str_starts_with($path, 'admin/')) {
            $candidates[] = substr($path, 6);
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $p): bool => $p !== '')));
    }
}
