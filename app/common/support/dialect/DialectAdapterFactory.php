<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support\dialect;

/** 按 database.connections.mysql.type 解析方言（默认 mysql） */
final class DialectAdapterFactory
{
    private static ?DialectAdapter $instance = null;

    public static function make(): DialectAdapter
    {
        if (self::$instance instanceof DialectAdapter) {
            return self::$instance;
        }

        $type = strtolower(trim((string) config('database.connections.mysql.type', 'mysql')));

        return self::$instance = match ($type) {
            'mysql', 'mariadb' => new MySqlDialectAdapter(),
            default            => new MySqlDialectAdapter(),
        };
    }
}
