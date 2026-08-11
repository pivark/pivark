<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;
use app\common\support\OpsLog;

use app\common\service\infra\CacheConfigService;
use think\facade\Cache;
use think\facade\Db;

final class DistributedLockService
{

    public function __construct(
        private readonly CacheConfigService $cacheConfigService,
    ) {
    }

    private const KEY_PREFIX = 'pv_lock:';

    /** @var array<string, string> 本进程持有的 DB 锁名 */
    private static array $dbLocks = [];

    /** @var array<string, string> 本进程持有的 Redis 锁 token（用于安全释放） */
    private static array $redisTokens = [];

    private const REDIS_RELEASE_LUA = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
  return redis.call('del', KEYS[1])
end
return 0
LUA;

    public function acquire(string $name, int $ttlSeconds = 60): bool
    {
        $name = $this->normalizeName($name);
        if ($name === '') {
            return false;
        }
        $ttlSeconds = max(5, min(21600, $ttlSeconds));

        if ($this->useRedis()) {
            return $this->acquireRedis($name, $ttlSeconds);
        }

        return $this->acquireDb($name, $ttlSeconds);
    }

    public function release(string $name): void
    {
        $name = $this->normalizeName($name);
        if ($name === '') {
            return;
        }

        if ($this->useRedis()) {
            $this->releaseRedis($name);

            return;
        }

        $this->releaseDb($name);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T|null 未获锁时 null
     */
    public function using(string $name, int $ttlSeconds, callable $callback): mixed
    {
        if (!$this->acquire($name, $ttlSeconds)) {
            return null;
        }
        try {
            return $callback();
        } finally {
            $this->release($name);
        }
    }

    private function useRedis(): bool
    {
        return $this->cacheConfigService->effectiveDriver() === CacheConfigService::DRIVER_REDIS;
    }

    private function acquireRedis(string $name, int $ttlSeconds): bool
    {
        try {
            $store = Cache::store('redis');
            $handler = $store->handler();
            if ($handler instanceof \Redis) {
                $token = bin2hex(random_bytes(8));
                $ok = $handler->set(self::KEY_PREFIX . $name, $token, ['NX', 'EX' => $ttlSeconds]);
                if ($ok === true) {
                    self::$redisTokens[$name] = $token;

                    return true;
                }

                return false;
            }
        } catch (\Throwable $e) {
            OpsLog::businessWarning('distributed_lock_redis_acquire_failed', ['msg' => $e->getMessage()]);
        }

        return $this->acquireDb($name, $ttlSeconds);
    }

    private function releaseRedis(string $name): void
    {
        $token = self::$redisTokens[$name] ?? null;
        unset(self::$redisTokens[$name]);
        if ($token === null || $token === '') {
            return;
        }
        try {
            $store   = Cache::store('redis');
            $handler = $store->handler();
            if ($handler instanceof \Redis) {
                $handler->eval(self::REDIS_RELEASE_LUA, [self::KEY_PREFIX . $name, $token], 1);

                return;
            }
            // 无原生 Redis 句柄时无法原子验 token，宁可留锁至 TTL 过期也不误删
        } catch (\Throwable $e) {
            OpsLog::businessWarning('distributed_lock_redis_release_failed', ['msg' => $e->getMessage()]);
        }
    }

    private function acquireDb(string $name, int $ttlSeconds): bool
    {
        if (isset(self::$dbLocks[$name])) {
            return true;
        }
        $lockName = 'pv_' . substr(md5($name), 0, 24);
        try {
            $row = $this->fetchSqlRow('SELECT GET_LOCK(?, ?) AS got', [$lockName, $ttlSeconds]);
            $got = (int) ($row['got'] ?? 0);
            if ($got === 1) {
                self::$dbLocks[$name] = $lockName;

                return true;
            }
        } catch (\Throwable $e) {
            OpsLog::businessWarning('distributed_lock_db_acquire_failed', ['msg' => $e->getMessage()]);
        }

        return false;
    }

    private function releaseDb(string $name): void
    {
        $lockName = self::$dbLocks[$name] ?? ('pv_' . substr(md5($name), 0, 24));
        try {
            $this->execSql('SELECT RELEASE_LOCK(?)', [$lockName]);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('distributed_lock_db_release_failed', ['msg' => $e->getMessage()]);
        }
        unset(self::$dbLocks[$name]);
    }

    /**
     * @param list<mixed> $bind
     * @return array<string, mixed>
     */
    private function fetchSqlRow(string $sql, array $bind): array
    {
        $conn = Db::connect();
        if (!$conn instanceof \think\db\PDOConnection) {
            return [];
        }
        $pdo = $conn->getPdo();
        if (!$pdo instanceof \PDO) {
            return [];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /** @param list<mixed> $bind */
    private function execSql(string $sql, array $bind): void
    {
        $conn = Db::connect();
        if (!$conn instanceof \think\db\PDOConnection) {
            return;
        }
        $pdo = $conn->getPdo();
        if (!$pdo instanceof \PDO) {
            return;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        return preg_replace('/[^a-zA-Z0-9:_\-.]/', '_', $name) ?? '';
    }
}
