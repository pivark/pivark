<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\content;

use app\common\service\config\ConfigService;
use think\facade\Db;

/**
 * 编辑器「特殊字符 / 表情」：utf8mb4 就绪检查 + 关闭时剥离四字节字符。
 */
class EditorSpecialCharsService
{
    /** 开启后允许入库的内容相关表（按需 CONVERT） */
    private const TARGET_TABLES = [
        'documents',
        'site_pages',
        'tags',
        'site_slides',
        'site_links',
        'site_nav',
        'forms',
    ];

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    public function enabled(): bool
    {
        return (string) $this->config->get('editor_special_chars', '0') === '1';
    }

    /**
     * 剥离 Unicode 平面补充区字符（含 emoji 等四字节 UTF-8）。
     */
    public function stripSupplementary(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $stripped = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);

        return is_string($stripped) ? $stripped : $text;
    }

    /** 关闭特殊字符时，对保存文本做剥离 */
    public function filterForSave(string $text): string
    {
        if ($this->enabled()) {
            return $text;
        }

        return $this->stripSupplementary($text);
    }

    /**
     * @return array{enabled:bool,charset_ready:bool,non_mb4_tables:list<string>,message:string}
     */
    public function status(): array
    {
        $nonMb4 = $this->nonUtf8mb4Tables();
        $enabled = $this->enabled();
        $ready = $nonMb4 === [];

        if ($enabled && $ready) {
            $message = '已开启：正文/标题可保存表情等特殊字符。';
        } elseif ($enabled && !$ready) {
            $message = '已开启，但以下表仍非 utf8mb4，保存表情可能失败：' . implode('、', $nonMb4);
        } elseif (!$enabled && $ready) {
            $message = '已关闭：保存文档时会自动去除表情等四字节字符。库表已是 utf8mb4，可随时开启。';
        } else {
            $message = '已关闭。开启时会尝试将内容表转为 utf8mb4。待转表：' . implode('、', $nonMb4);
        }

        return [
            'enabled'         => $enabled,
            'charset_ready'   => $ready,
            'non_mb4_tables'  => $nonMb4,
            'message'         => $message,
        ];
    }

    /**
     * 开启：确保内容表 utf8mb4，并写入配置。
     *
     * @return array{enabled:bool,charset_ready:bool,converted:list<string>,message:string}
     */
    public function enable(): array
    {
        $converted = $this->ensureUtf8mb4();
        $this->config->save(['editor_special_chars' => '1']);
        $status = $this->status();

        return [
            'enabled'       => true,
            'charset_ready' => $status['charset_ready'],
            'converted'     => $converted,
            'message'       => $converted === []
                ? '特殊字符已开启（库表本就是 utf8mb4）。'
                : ('特殊字符已开启，已转换：' . implode('、', $converted)),
        ];
    }

    /**
     * 关闭：仅写配置（不回退字符集）。
     *
     * @return array{enabled:bool,message:string}
     */
    public function disable(): array
    {
        $this->config->save(['editor_special_chars' => '0']);

        return [
            'enabled' => false,
            'message' => '特殊字符已关闭：之后保存文档将自动去除表情等四字节字符。',
        ];
    }

    /**
     * 在配置保存为开启时调用：补齐 utf8mb4。
     *
     * @return list<string> 实际执行了 CONVERT 的表名
     */
    public function ensureUtf8mb4(): array
    {
        $converted = [];
        $prefix = (string) config('database.connections.mysql.prefix', '');
        foreach (self::TARGET_TABLES as $logical) {
            $table = $prefix . $logical;
            if (!$this->tableExists($table)) {
                continue;
            }
            if ($this->tableIsUtf8mb4($table)) {
                continue;
            }
            Db::execute(
                'ALTER TABLE `' . str_replace('`', '``', $table) . '` '
                . 'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
            );
            $converted[] = $table;
        }

        return $converted;
    }

    /**
     * @return list<string>
     */
    public function nonUtf8mb4Tables(): array
    {
        $out = [];
        $prefix = (string) config('database.connections.mysql.prefix', '');
        foreach (self::TARGET_TABLES as $logical) {
            $table = $prefix . $logical;
            if (!$this->tableExists($table)) {
                continue;
            }
            if (!$this->tableIsUtf8mb4($table)) {
                $out[] = $table;
            }
        }

        return $out;
    }

    private function tableExists(string $table): bool
    {
        $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $table);
        $rows = Db::query("SHOW TABLES LIKE '" . str_replace("'", "''", $safe) . "'");

        return is_array($rows) && $rows !== [];
    }

    private function tableIsUtf8mb4(string $table): bool
    {
        $rows = Db::query(
            "SELECT CCSA.character_set_name AS cs
             FROM information_schema.`TABLES` T
             INNER JOIN information_schema.`COLLATION_CHARACTER_SET_APPLICABILITY` CCSA
               ON CCSA.collation_name = T.table_collation
             WHERE T.table_schema = DATABASE() AND T.table_name = ?
             LIMIT 1",
            [$table]
        );
        if (!is_array($rows) || $rows === []) {
            return false;
        }
        $cs = strtolower((string) ($rows[0]['cs'] ?? ''));
        if ($cs !== '' && $cs !== 'utf8mb4') {
            return false;
        }

        $cols = Db::query(
            "SELECT CHARACTER_SET_NAME AS cs
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND DATA_TYPE IN ('varchar','char','text','tinytext','mediumtext','longtext')
               AND CHARACTER_SET_NAME IS NOT NULL",
            [$table]
        );
        if (!is_array($cols)) {
            return false;
        }
        foreach ($cols as $col) {
            if (strtolower((string) ($col['cs'] ?? '')) !== 'utf8mb4') {
                return false;
            }
        }

        return true;
    }
}
