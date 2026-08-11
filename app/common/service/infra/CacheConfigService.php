<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\support\ServiceResult;
use app\common\support\OpsLog;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\infra\MetaSqlCacheService;

use app\common\service\config\ConfigService;
use app\common\service\site\SiteModeService;
use think\App;
use think\facade\Cache;

final class CacheConfigService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly MetaSqlCacheService $metaSqlCacheService,
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
    ) {
    }

    public const DRIVER_FILE  = 'file';
    public const DRIVER_REDIS = 'redis';

    /** 本次请求是否因 Redis 不可用而回退到文件 */
    private static bool $redisFallback = false;

    public function bootstrapFromApp(App $app): void
    {
        $want = $this->configuredDriver();
        self::$redisFallback = $want === self::DRIVER_REDIS && !$this->extensionLoaded();
        $driver              = $this->resolveDriver();

        $fileStore = config('cache.stores.file');
        if (!is_array($fileStore)) {
            $fileStore = ['type' => 'File', 'path' => '', 'prefix' => '', 'expire' => 0];
        }

        $app->config->set([
            'default' => $driver,
            'stores'  => [
                'file'  => $fileStore,
                'redis' => $this->redisStoreConfig(),
            ],
        ], 'cache');
        $this->warnMultiNodeLocalCache();
    }

    public function redisFallbackUsed(): bool
    {
        return self::$redisFallback;
    }

    public function extensionLoaded(): bool
    {
        return extension_loaded('redis');
    }

    /**
     * @return self::DRIVER_FILE|self::DRIVER_REDIS
     */
    public function configuredDriver(): string
    {
        $raw = strtolower(trim((string) $this->configService->get('cache_driver', self::DRIVER_FILE)));

        return $raw === self::DRIVER_REDIS ? self::DRIVER_REDIS : self::DRIVER_FILE;
    }

    /**
     * 实际使用的驱动（Redis 不可用时为 file）
     *
     * @return self::DRIVER_FILE|self::DRIVER_REDIS
     */
    public function effectiveDriver(): string
    {
        if ($this->configuredDriver() !== self::DRIVER_REDIS) {
            return self::DRIVER_FILE;
        }

        return $this->extensionLoaded() ? self::DRIVER_REDIS : self::DRIVER_FILE;
    }

    /**
     * @return self::DRIVER_FILE|self::DRIVER_REDIS
     */
    public function resolveDriver(): string
    {
        return $this->configuredDriver() === self::DRIVER_REDIS && $this->extensionLoaded()
            ? self::DRIVER_REDIS
            : self::DRIVER_FILE;
    }

    public function normalizeDriverInput(string $raw): string
    {
        return strtolower(trim($raw)) === self::DRIVER_REDIS ? self::DRIVER_REDIS : self::DRIVER_FILE;
    }

    /**
     * @return array<string, mixed>
     */
    public function redisStoreConfig(?array $overrides = null): array
    {
        $host = trim((string) ($overrides['redis_cache_host'] ?? $this->configService->get('redis_cache_host', '127.0.0.1')));
        if ($host === '') {
            $host = '127.0.0.1';
        }
        $port = (int) ($overrides['redis_cache_port'] ?? $this->configService->get('redis_cache_port', 6379));
        if ($port < 1 || $port > 65535) {
            $port = 6379;
        }
        $select = (int) ($overrides['redis_cache_select'] ?? $this->configService->get('redis_cache_select', 0));
        if ($select < 0 || $select > 15) {
            $select = 0;
        }
        $prefix = trim((string) ($overrides['redis_cache_prefix'] ?? $this->configService->get('redis_cache_prefix', 'pv:')));
        if ($prefix === '') {
            $prefix = 'pv:';
        }

        return [
            'type'     => 'redis',
            'host'     => $host,
            'port'     => $port,
            'password' => (string) ($overrides['redis_cache_password'] ?? $this->configService->get('redis_cache_password', '')),
            'select'   => $select,
            'prefix'   => $prefix,
            'timeout'  => 2,
        ];
    }

    /**
     * @param array<string, mixed>|null $overrides 测试未保存的表单值
     * @return ServiceResult
     */
    public function testRedisConnection(?array $overrides = null): ServiceResult
    {
        if (!$this->extensionLoaded()) {
            return ServiceResult::fail('PHP 未安装 redis 扩展（phpredis），无法使用 Redis 缓存');
        }

        $cfg = $this->redisStoreConfig($overrides);
        try {
            $redis = new \Redis();
            $ok    = $redis->connect($cfg['host'], (int) $cfg['port'], (float) ($cfg['timeout'] ?? 2));
            if (!$ok) {
                return ServiceResult::fail('无法连接 Redis 服务');
            }
            if (($cfg['password'] ?? '') !== '') {
                if (!$redis->auth((string) $cfg['password'])) {
                    return ServiceResult::fail('Redis 认证失败，请检查密码');
                }
            }
            $redis->select((int) ($cfg['select'] ?? 0));
            $probeKey = (string) ($cfg['prefix'] ?? 'pv:') . 'cache_probe_' . bin2hex(random_bytes(4));
            $redis->set($probeKey, '1', 10);
            $redis->del($probeKey);

            return ServiceResult::ok(null, '连接成功（' . $cfg['host'] . ':' . $cfg['port'] . ' DB' . (int) $cfg['select'] . '）');
        } catch (\Throwable $e) {
            return ServiceResult::fail('连接失败：' . $e->getMessage());
        }
    }

    public function onConfigSaved(): void
    {
        $app = app();
        if ($app instanceof App) {
            $this->bootstrapFromApp($app);
        }
        try {
            $this->metaSqlCacheService->clearFrontMetaAllStores();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('cache_config_clear_front_meta_failed', ['msg' => $e->getMessage()]);
        }
        try {
            Cache::clear();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('cache_config_clear_failed', ['msg' => $e->getMessage()]);
        }
        try {
            $this->metaSqlCacheService->clearFrontMetaAllStores();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('cache_config_clear_front_meta_retry_failed', ['msg' => $e->getMessage()]);
        }
        $this->frontCacheInvalidator->invalidateAll(true);
    }

    public function isCacheConfigKey(string $key): bool
    {
        return $key === 'cache_driver'
            || str_starts_with($key, 'redis_cache_');
    }

    /**
     * 后台「缓存」页状态条：配置值 + 实际生效 + 现场探测（一眼判断 Redis 是否真开着）。
     *
     * @param array<string, mixed>|null $overrides 未保存表单值（测试连接后预览）
     * @return array{
     *   configured: string,
     *   effective: string,
     *   extension_loaded: bool,
     *   fallback: bool,
     *   probe_ok: bool|null,
     *   probe_msg: string,
     *   host: string,
     *   port: int,
     *   select: int,
     *   prefix: string,
     *   state: string,
     *   level: string,
     *   label: string,
     *   hint: string
     * }
     */
    public function adminStatus(?array $overrides = null): array
    {
        $configured = $this->configuredDriver();
        if (is_array($overrides) && array_key_exists('cache_driver', $overrides)) {
            $configured = $this->normalizeDriverInput((string) $overrides['cache_driver']);
        }

        $ext      = $this->extensionLoaded();
        $effective = ($configured === self::DRIVER_REDIS && $ext)
            ? self::DRIVER_REDIS
            : self::DRIVER_FILE;
        $fallback = $configured === self::DRIVER_REDIS && !$ext;

        $cfg = $this->redisStoreConfig($overrides);
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (int) ($cfg['port'] ?? 6379);
        $select = (int) ($cfg['select'] ?? 0);
        $prefix = (string) ($cfg['prefix'] ?? 'pv:');

        $probeOk  = null;
        $probeMsg = '';
        if ($configured === self::DRIVER_REDIS) {
            $probe = $this->testRedisConnection($overrides);
            $probeOk  = $probe->isOk();
            $probeMsg = $probe->message();
        }

        if ($configured === self::DRIVER_FILE) {
            $state = 'file';
            $level = 'info';
            $label = '本地文件缓存 · Redis 未启用';
            $hint  = '站点缓存写在本机 runtime；若已装 Redis，可在上方改选「Redis」并测试通过后保存。';
        } elseif ($fallback) {
            $state = 'redis_fallback';
            $level = 'warning';
            $label = '已选 Redis，但 PHP 无 redis 扩展 · 实际仍用本地文件';
            $hint  = '宝塔 → PHP → 安装扩展 → 勾选 redis → 重启 PHP，再点「刷新状态」或「测试连接」。';
        } elseif ($probeOk === false) {
            $state = 'redis_down';
            $level = 'error';
            $label = '已选 Redis，但连接失败';
            $hint  = $probeMsg !== ''
                ? $probeMsg
                : '请确认 Redis 服务已启动，且主机/端口/密码与宝塔一致。';
        } else {
            $state = 'redis_ok';
            $level = 'success';
            $label = 'Redis 已开启 · 连接正常';
            $hint  = sprintf('%s:%d · DB%d · 前缀 %s', $host, $port, $select, $prefix);
        }

        return [
            'configured'         => $configured,
            'effective'          => $effective,
            'extension_loaded'   => $ext,
            'fallback'           => $fallback,
            'probe_ok'           => $probeOk,
            'probe_msg'          => $probeMsg,
            'host'               => $host,
            'port'               => $port,
            'select'             => $select,
            'prefix'             => $prefix,
            'state'              => $state,
            'level'              => $level,
            'label'              => $label,
            'hint'               => $hint,
        ];
    }

    /** 多节点 + file 缓存时写 business 日志提醒运维切换 Redis */
    private function warnMultiNodeLocalCache(): void
    {
        if ($this->effectiveDriver() !== self::DRIVER_FILE) {
            return;
        }
        if (!filter_var(env('PIVARK_MULTI_NODE', false), FILTER_VALIDATE_BOOL)) {
            return;
        }
        try {
            \think\facade\Log::channel('business')->warning('cache_driver=file with PIVARK_MULTI_NODE=1; use redis for shared idempotency and front meta');
        } catch (\Throwable $e) {
            OpsLog::businessWarning('cache_config_multi_node_warn_failed', ['msg' => $e->getMessage()]);
        }
    }
}
