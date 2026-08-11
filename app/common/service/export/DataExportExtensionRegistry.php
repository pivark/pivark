<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\export;


/** 插件扩展 data export profile 元数据（业务 export 接口仍在 weapp/） */
final class DataExportExtensionRegistry
{

    /** @var array<string, array<string, mixed>> */
    private static array $profiles = [];

    public function reset(): void
    {
        self::$profiles = [];
    }

    /**
     * @param array<string, mixed> $meta 须含 profile、permission、path、label
     */
    public function register(array $meta): void
    {
        $profile = trim((string) ($meta['profile'] ?? ''));
        if ($profile === '') {
            return;
        }
        $meta['plugin_id'] = trim((string) ($meta['plugin_id'] ?? ''));
        self::$profiles[$profile] = $meta;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return self::$profiles;
    }

    public function labelForPermission(string $permission): string
    {
        $permission = trim($permission);
        if ($permission === '') {
            return '';
        }
        foreach ($this->mergedProfiles() as $meta) {
            if ((string) ($meta['permission'] ?? '') === $permission) {
                return (string) ($meta['label'] ?? '');
            }
        }

        return '';
    }

    /** @return array<string, array<string, mixed>> */
    public function mergedProfiles(): array
    {
        /** @var array<string, array<string, mixed>> $core */
        $core = app(DataExportRegistry::class)->coreProfiles();

        return array_merge($core, self::$profiles);
    }
}
