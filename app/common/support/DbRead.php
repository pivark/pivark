<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use think\db\Query;
use think\facade\Env;
use think\Model;

/** 只读库连接（配置 DB_READ_HOST 后前台读路径可走副本） */
final class DbRead
{
    private static ?bool $enabled = null;

    public static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }
        $host = trim((string) Env::get('DB_READ_HOST', ''));
        self::$enabled = $host !== '' && $host !== (string) Env::get('DB_HOST', '127.0.0.1');

        return self::$enabled;
    }

    /** @return string|null 连接名 mysql_read 或 null 表示主库 */
    public static function connectionName(): ?string
    {
        return self::enabled() ? 'mysql_read' : null;
    }

    /**
     * 前台只读查询：有读库时走 mysql_read，否则默认连接。
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return T|Query
     */
    public static function model(string $modelClass): Model|Query
    {
        $conn = self::connectionName();

        return $conn !== null ? $modelClass::connect($conn) : $modelClass::query();
    }
}
