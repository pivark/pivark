<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\search\ItemCatalogSearchDriverInterface;

/** 品项目录搜索驱动工厂（Phase C：elastic 接入点） */
final class ItemCatalogSearchDriverFactory
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
        private readonly SearchDegradedGuard $degradedGuard,
    ) {
    }

    public const DRIVER_SQL    = 'sql';
    public const DRIVER_MEILI  = 'meili';
    public const DRIVER_ELASTIC = 'elastic';

    private static ?ItemCatalogSearchDriverInterface $resolved = null;

    public function configuredDriver(): string
    {
        $searchDriver = $this->searchConfig->driver();
        if ($searchDriver === self::DRIVER_SQL) {
            return self::DRIVER_SQL;
        }

        $fallback = $searchDriver === self::DRIVER_MEILI ? self::DRIVER_MEILI : self::DRIVER_SQL;
        $v        = (string) config('pivark.item_catalog_search_driver', $fallback);

        return in_array($v, [self::DRIVER_SQL, self::DRIVER_MEILI, self::DRIVER_ELASTIC], true)
            ? $v
            : $fallback;
    }

    public function make(): ItemCatalogSearchDriverInterface
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }
        self::$resolved = match ($this->configuredDriver()) {
            self::DRIVER_ELASTIC => new ElasticItemCatalogSearchDriver(),
            self::DRIVER_MEILI   => new MeiliItemCatalogSearchDriver(),
            default              => new SqlItemCatalogSearchDriver(),
        };

        return self::$resolved;
    }

    /** 外部引擎不可用时回退 SQL */
    public function makeForList(): ItemCatalogSearchDriverInterface
    {
        $driver = $this->make();
        if ($driver->name() === self::DRIVER_SQL || $driver->isAvailable()) {
            return $driver;
        }

        $this->degradedGuard->markExternalUnavailable();

        return new SqlItemCatalogSearchDriver();
    }

    public function reset(): void
    {
        self::$resolved = null;
    }
}
