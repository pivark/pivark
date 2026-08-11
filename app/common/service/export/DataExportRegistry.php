<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\export;


/** 发行版 data export profile 读取（config/kernel/data_export.php） */
final class DataExportRegistry
{

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $core = null;

    /** @return array<string, array<string, mixed>> */
    public function coreProfiles(): array
    {
        if (self::$core !== null) {
            return self::$core;
        }
        $path = dirname(__DIR__, 4) . '/config/kernel/data_export.php';
        if (!is_file($path)) {
            self::$core = [];

            return self::$core;
        }
        $loaded = require $path;

        self::$core = is_array($loaded) ? $loaded : [];

        return self::$core;
    }

    /** @return array<string, mixed>|null */
    public function find(string $profile): ?array
    {
        $profile = trim($profile);
        if ($profile === '') {
            return null;
        }
        $all = app(DataExportExtensionRegistry::class)->mergedProfiles();

        return $all[$profile] ?? null;
    }
}
