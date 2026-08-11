<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\license;

use app\common\model\Role;
use app\common\service\config\ConfigService;
use app\common\service\license\LicenseRemoteClientService;
use app\common\service\member\MemberService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\extension\HostRuntimeProbe;
use app\common\service\release\CoreUpdateRemoteService;
use app\common\service\release\PivarkEditionService;
use app\common\service\site\SiteCoreLicenseService;
use app\common\service\site\SiteKeyService;
use app\common\support\InstallGate;
use app\common\support\LocalFile;
use app\common\support\OpsLog;
use app\common\support\ProjectPaths;
use think\facade\Db;

final class LicenseHeartbeatService
{

    public function __construct(
        private readonly LicenseRemoteClientService $licenseRemoteClientService,
        private readonly SiteKeyService $siteKeyService,
        private readonly SiteCoreLicenseService $siteCoreLicenseService,
        private readonly EntitlementService $entitlementService,
        private readonly PivarkEditionService $pivarkEditionService,
        private readonly ConfigService $configService,
        private readonly CoreUpdateRemoteService $coreUpdateRemoteService,
    ) {
    }

    private const CACHE_FILE = 'license_heartbeat.sent';

    private static bool $deferredScheduled = false;

    public function maybeSend(): void
    {
        $this->send(false);
    }

    /** 响应发送后再尝试心跳，避免阻塞后台页面（仅 PHP-FPM 有效） */
    public function maybeSendDeferred(): void
    {
        if (self::$deferredScheduled) {
            return;
        }
        self::$deferredScheduled = true;
        register_shutdown_function(function (): void {
            \app\common\support\HttpResponseFinish::finishIfPossible();
            $this->maybeSend();
        });
    }

    /** 安装完成时立即登记，不受节流限制 */
    public function sendImmediately(string $reason = 'register'): void
    {
        $this->send(true, $reason);
    }

    private function send(bool $force, string $reason = 'heartbeat'): void
    {
        if (!$this->licenseRemoteClientService->isConfigured() || HostRuntimeProbe::isAnyHostRuntimeActive()) {
            return;
        }

        try {
            if (!$force) {
                $path = $this->cachePath();
                $ttl  = max(300, (int) config('pivark.license_heartbeat_interval', 21600));
                if (is_file($path) && (time() - (int) filemtime($path)) < $ttl) {
                    return;
                }
            }

            $this->siteKeyService->ensure();
            $snapshot = $this->buildSnapshot($reason);
            $this->licenseRemoteClientService->heartbeat(
                $this->siteKeyService->get(),
                (string) ($snapshot['site_url'] ?? ''),
                (string) ($snapshot['core_version'] ?? ''),
                is_array($snapshot['plugins'] ?? null) ? $snapshot['plugins'] : [],
                $snapshot
            );

            LocalFile::putContents($this->cachePath(), (string) time());
        } catch (\Throwable $e) {
            OpsLog::businessWarning('license_heartbeat_failed', [
                'msg'    => $e->getMessage(),
                'reason' => $reason,
                'force'  => $force,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(string $reason = 'heartbeat'): array
    {
        $lock = InstallGate::readLockData();
        $core = $this->siteCoreLicenseService->status();
        $plugins = [];
        foreach ($this->entitlementService->listEntitledAdmin() as $row) {
            $id = trim((string) ($row['identifier'] ?? ''));
            if ($id !== '') {
                $plugins[] = $id;
            }
        }

        return [
            'reason'               => $reason,
            'edition'              => $this->pivarkEditionService->edition(),
            'site_name'            => trim((string) $this->configService->get('site_name', '')),
            'site_url'             => trim((string) $this->configService->get('site_url', '')),
            'core_version'         => $this->coreUpdateRemoteService->currentVersion(),
            'release_version'      => trim((string) ($lock['release_version'] ?? '')),
            'installed_at'         => trim((string) ($lock['installed_at'] ?? '')),
            'plugins'              => $plugins,
            'core_license'         => $core,
            'core_tier'            => (string) ($core['tier'] ?? ''),
            'core_features'        => is_array($core['features'] ?? null) ? $core['features'] : [],
            'last_admin_login_at'  => $this->latestAdminLoginAt(),
        ];
    }

    /** 后台运维角色用户中最近一次登录（不含纯前台会员） */
    private function latestAdminLoginAt(): string
    {
        try {
            $roleIds = Role::where('status', 1)
                ->where('code', '<>', MemberService::ROLE_CODE)
                ->column('id');
            if ($roleIds === []) {
                return '';
            }
            $val = Db::name('users')->alias('u')
                ->whereIn('u.id', static function ($sub) use ($roleIds): void {
                    $sub->name('user_roles')->whereIn('role_id', $roleIds)->field('user_id');
                })
                ->whereNotNull('u.last_login_time')
                ->where('u.last_login_time', '<>', '')
                ->max('u.last_login_time');

            return trim((string) $val);
        } catch (\Throwable) {
            return '';
        }
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
