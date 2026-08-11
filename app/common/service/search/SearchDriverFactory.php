<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\service\search\ElasticSearchDriver;
use app\common\service\search\MeilisearchSearchDriver;
use app\common\service\search\SqlSearchDriver;
use app\common\support\AppService;
use app\common\support\search\SearchDriverInterface;

class SearchDriverFactory
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
        private readonly SearchDegradedGuard $degradedGuard,
    ) {
    }

    private static ?SearchDriverInterface $resolved = null;

    private static ?string $forcedDriver = null;

    private static bool $fellBackToSql = false;

    public function forceDriver(?string $name): void
    {
        self::$forcedDriver = $name;
        self::$resolved     = null;
    }

    public function driverName(): string
    {
        if (self::$forcedDriver !== null && self::$forcedDriver !== '') {
            return self::$forcedDriver;
        }

        return $this->searchConfig->driver();
    }

    private function makeDriver(string $class): SearchDriverInterface
    {
        $driver = AppService::make($class);
        if (!$driver instanceof SearchDriverInterface) {
            throw new \RuntimeException('Search driver must implement SearchDriverInterface: ' . $class);
        }

        return $driver;
    }

    public function make(): SearchDriverInterface
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }
        $name = $this->driverName();
        self::$resolved = match ($name) {
            'meili' => $this->makeDriver(MeilisearchSearchDriver::class),
            'elastic' => $this->makeDriver(ElasticSearchDriver::class),
            default => $this->makeDriver(SqlSearchDriver::class),
        };

        return self::$resolved;
    }

    public function reset(): void
    {
        self::$resolved     = null;
        self::$forcedDriver = null;
        self::$fellBackToSql = false;
    }

    public function fellBackToSql(): bool
    {
        return self::$fellBackToSql;
    }

    /** 配置为外部引擎但不可用时回退 SQL */
    public function makeForSearch(): SearchDriverInterface
    {
        self::$fellBackToSql = false;
        $name = $this->driverName();
        if ($name !== 'sql' && $this->degradedGuard->isDegraded()) {
            self::$fellBackToSql = true;

            return $this->makeDriver(SqlSearchDriver::class);
        }
        $driver = $this->make();
        if ($driver->name() === 'sql') {
            return $driver;
        }
        if ($driver->isAvailable()) {
            return $driver;
        }

        self::$fellBackToSql = true;
        $this->degradedGuard->markExternalUnavailable();

        return $this->makeDriver(SqlSearchDriver::class);
    }
}
