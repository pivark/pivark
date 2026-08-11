<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\enterprise;

/** 企业数字资产后端注册（插件 boot 注册，内核无 \weapp\ 字面量） */
final class EnterpriseAssetBackendRegistry
{

    /** @var array<string, class-string> */
    private static array $backends = [];

    public function register(string $identifier, string $backendClass): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $backendClass === '') {
            return;
        }
        self::$backends[$identifier] = $backendClass;
    }

    public function reset(): void
    {
        $kernel = self::$backends['kernel'] ?? null;
        self::$backends = [];
        if ($kernel !== null) {
            self::$backends['kernel'] = $kernel;
        }
    }

    /** @return class-string|null */
    public function backendClass(?string $identifier = null): ?string
    {
        if (!isset(self::$backends['kernel']) && class_exists(EnterpriseAssetKernelService::class)) {
            self::$backends['kernel'] = EnterpriseAssetKernelService::class;
        }
        if ($identifier !== null && trim($identifier) !== '') {
            $key = strtolower(trim($identifier));

            return self::$backends[$key] ?? null;
        }
        if (isset(self::$backends['kernel'])) {
            return self::$backends['kernel'];
        }
        if (isset(self::$backends['tender'])) {
            return self::$backends['tender'];
        }

        return self::$backends[array_key_first(self::$backends)] ?? null;
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier !== '') {
            unset(self::$backends[$identifier]);
        }
    }
}
