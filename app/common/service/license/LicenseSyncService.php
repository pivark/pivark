<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\license;

use app\common\service\config\ConfigService;
use app\common\service\plugin\extension\HostRuntimeProbe;
use app\common\service\release\CoreUpdateRemoteService;
use app\common\service\release\PivarkEditionService;
use app\common\service\site\SiteKeyService;
use app\common\support\AppTime;
use app\common\support\LocalFile;
use app\common\support\OpsLog;
use app\common\support\ProjectPaths;
use app\common\support\ServiceResult;

final class LicenseSyncService
{

    public function __construct(
        private readonly LicenseRemoteClientService $licenseRemoteClientService,
        private readonly SiteKeyService $siteKeyService,
        private readonly ConfigService $configService,
        private readonly CoreUpdateRemoteService $coreUpdateRemoteService,
        private readonly LicenseActivateService $licenseActivateService,
        private readonly PivarkEditionService $pivarkEditionService,
    ) {
    }

    private const CACHE_FILE = 'license_sync.cache.json';

    /**
     * @return ServiceResult
     */
    public function sync(bool $force = false): ServiceResult
    {
        if (HostRuntimeProbe::isAnyHostRuntimeActive()) {
            return ServiceResult::ok(null, '授权平台宿主无需同步');
        }
        if (!$this->licenseRemoteClientService->isConfigured()) {
            return ServiceResult::ok(null, '未连接授权平台');
        }

        try {
            if (!$force) {
                $cached = $this->readCache();
                if ($cached instanceof ServiceResult) {
                    return $cached;
                }
            }

            $this->siteKeyService->ensure();
            $remote = $this->licenseRemoteClientService->sync(
                $this->siteKeyService->get(),
                trim((string) $this->configService->get('site_url', '')),
                $this->coreUpdateRemoteService->currentVersion()
            );
            // soft-fail：连不上 / 业务失败均不撤销本机已授（废权只认到期或成功 sync 后的平台撤权）
            if ($remote === null) {
                return ServiceResult::ok([
                    'soft_fail'          => true,
                    'kept_entitlements'  => true,
                ], '无法连接授权平台，已保留本机授权');
            }
            if (!$remote->isOk()) {
                return ServiceResult::ok([
                    'soft_fail'         => true,
                    'kept_entitlements' => true,
                    'remote_msg'        => (string) ($remote->message() ?? ''),
                ], '同步未成功，已保留本机授权：' . (string) ($remote->message() ?? '请稍后重试'));
            }

            $data = is_array($remote->dataArray() ?? null) ? $remote->dataArray() : [];
            if (!empty($data['synced'])) {
                $applied = $this->licenseActivateService->applySyncPayload($data);
                if (!$applied->isOk()) {
                    return $applied;
                }
                $data = array_merge($data, is_array($applied->dataArray() ?? null) ? $applied->dataArray() : []);
            }

            $result = ServiceResult::ok($data, (string) ($remote->message() ?? 'ok'));
            $this->writeCache($result);

            return $result;
        } catch (\Throwable $e) {
            OpsLog::businessWarning('license_sync_failed', [
                'msg'   => $e->getMessage(),
                'force' => $force,
            ]);

            return ServiceResult::ok(null, '同步授权失败');
        }
    }

    public function maybeSync(): void
    {
        if ($this->pivarkEditionService->isDev() && !$this->licenseRemoteClientService->isConfigured()) {
            return;
        }
        $this->sync(false);
    }

    public function lastSyncAt(): string
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return '';
        }

        return AppTime::format('Y-m-d H:i:s', (int) filemtime($path));
    }

    private function readCache(): ?ServiceResult
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return null;
        }
        $ttl = max(60, (int) config('pivark.license_sync_interval', 300));
        if ((time() - (int) filemtime($path)) >= $ttl) {
            return null;
        }
        $parsed = json_decode((string) file_get_contents($path), true);
        if (!is_array($parsed)) {
            return null;
        }

        return $this->serviceResultFromCachePayload($parsed);
    }

    private function writeCache(ServiceResult $result): void
    {
        LocalFile::putContents(
            $this->cachePath(),
            json_encode([
                'ok'   => $result->isOk(),
                'msg'  => $result->message(),
                'data' => $result->data(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function serviceResultFromCachePayload(array $parsed): ServiceResult
    {
        if (array_key_exists('ok', $parsed)) {
            $msg = (string) ($parsed['msg'] ?? '');

            return ($parsed['ok'] ?? false)
                ? ServiceResult::ok($parsed['data'] ?? null, $msg)
                : ServiceResult::fail($msg !== '' ? $msg : '同步失败');
        }

        // legacy cache: code=0 + data 表示成功
        if ((int) ($parsed['code'] ?? 1) === 0 && array_key_exists('data', $parsed)) {
            return ServiceResult::ok($parsed['data'], (string) ($parsed['msg'] ?? 'ok'));
        }

        return ServiceResult::fail((string) ($parsed['msg'] ?? '同步失败'));
    }

    private function cachePath(): string
    {
        $dir = ProjectPaths::runtimeDir();
        if (!is_dir($dir)) {
            LocalFile::mkdirIfMissing($dir);
        }

        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }
}
