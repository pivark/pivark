<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\security;

/** 插件 install/uninstall/upgrade SQL 白名单校验 */
final class PluginSqlGuard
{
    /** @var list<string> */
    private const GLOBAL_BLOCKED = [
        '/^\s*DROP\s+DATABASE\b/i',
        '/^\s*CREATE\s+DATABASE\b/i',
        '/^\s*ALTER\s+DATABASE\b/i',
        '/^\s*USE\s+/i',
        '/^\s*GRANT\b/i',
        '/^\s*REVOKE\b/i',
        '/^\s*FLUSH\b/i',
        '/^\s*SHUTDOWN\b/i',
        '/^\s*SET\s+GLOBAL\b/i',
    ];

    /** @var list<string> 允许 weapp_{id}s 命名的插件 identifier */
    private const PLURAL_TABLE_IDENTIFIERS = [
        'comment', // weapp_comments
    ];

    public function assertSafe(string $stmt, string $identifier): void
    {
        $stmt = trim($stmt);
        if ($stmt === '' || preg_match('/^SELECT\s/i', $stmt)) {
            return;
        }

        foreach (self::GLOBAL_BLOCKED as $pattern) {
            if (preg_match($pattern, $stmt)) {
                throw new \InvalidArgumentException('插件 SQL 含禁止语句：' . mb_substr($stmt, 0, 80));
            }
        }

        if (preg_match('/^\s*(DELETE|UPDATE|REPLACE|INSERT)\s+/i', $stmt)) {
            foreach ($this->extractDmlTableNames($stmt) as $table) {
                if (!$this->isAllowedPluginTable($table, $identifier)) {
                    throw new \InvalidArgumentException('插件 SQL 禁止操作非插件表：' . $table);
                }
            }
        }

        if (preg_match('/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE\s*\()\b/i', $stmt)) {
            throw new \InvalidArgumentException('插件 SQL 含禁止语句：' . mb_substr($stmt, 0, 80));
        }

        if (preg_match('/^\s*(DROP|TRUNCATE|ALTER)\s+TABLE\b/i', $stmt)) {
            foreach ($this->extractTableNames($stmt) as $table) {
                if (!$this->isAllowedPluginTable($table, $identifier)) {
                    throw new \InvalidArgumentException('插件 SQL 禁止操作非插件表：' . $table);
                }
            }
        }
    }

    private function isAllowedPluginTable(string $table, string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }

        $table = strtolower(trim($table, '` '));
        if ($table === '') {
            return false;
        }

        $pfx = strtolower((string) config('database.connections.mysql.prefix', 'pv_'));
        if ($pfx !== '' && str_starts_with($table, $pfx)) {
            $table = substr($table, strlen($pfx));
        }

        if (!str_starts_with($table, 'weapp_')) {
            return false;
        }

        $pluginPrefix = 'weapp_' . str_replace('-', '_', $identifier);
        if (!str_starts_with($table, $pluginPrefix)) {
            return false;
        }

        $rest = substr($table, strlen($pluginPrefix));
        if ($rest === '') {
            return true;
        }

        // weapp_{id}_*
        if ($rest[0] === '_') {
            return true;
        }

        // 仅白名单历史复数表 weapp_{id}s（禁止任意 id + 's' 放行）
        return $rest === 's' && in_array($identifier, self::PLURAL_TABLE_IDENTIFIERS, true);
    }

    /**
     * @return list<string>
     */
    private function extractTableNames(string $stmt): array
    {
        if (!preg_match('/^\s*(?:DROP|TRUNCATE|ALTER)\s+TABLE\s+(?:IF\s+EXISTS\s+)?(.+)$/is', $stmt, $m)) {
            return [];
        }

        $segment = trim($m[1]);
        $parts   = preg_split('/\s+(?:ON|RENAME|DROP|ADD|MODIFY|CHANGE|ALGORITHM|LOCK)\b/i', $segment) ?: [$segment];
        $segment = trim((string) $parts[0], " \t\n\r\0\x0B;");

        $tables = [];
        foreach (preg_split('/\s*,\s*/', $segment) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^`([^`]+)`$/', $part, $quoted)) {
                $tables[] = $quoted[1];
                continue;
            }
            $nameParts = preg_split('/\s+/', $part) ?: [$part];
            $tables[] = (string) $nameParts[0];
        }

        return array_values(array_filter($tables, static fn (string $name): bool => $name !== ''));
    }

    /**
     * @return list<string>
     */
    private function extractDmlTableNames(string $stmt): array
    {
        if (preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+(`[^`]+`|[a-zA-Z0-9_]+)/i', $stmt, $m)) {
            return [$this->normalizeTableToken((string) $m[1])];
        }
        if (preg_match('/^\s*UPDATE\s+(`[^`]+`|[a-zA-Z0-9_]+)/i', $stmt, $m)) {
            return [$this->normalizeTableToken((string) $m[1])];
        }
        if (preg_match('/^\s*DELETE\s+FROM\s+(`[^`]+`|[a-zA-Z0-9_]+)/i', $stmt, $m)) {
            return [$this->normalizeTableToken((string) $m[1])];
        }
        if (preg_match('/^\s*REPLACE\s+(?:INTO\s+)?(`[^`]+`|[a-zA-Z0-9_]+)/i', $stmt, $m)) {
            return [$this->normalizeTableToken((string) $m[1])];
        }

        return [];
    }

    private function normalizeTableToken(string $token): string
    {
        $token = trim($token, '` ');

        return strtolower($token);
    }
}
