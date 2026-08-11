<?php
/**
 * 元舟 PivArk — 开源版 核心在线升级（下载安装 zip → 校验 → 覆盖 → 迁移）
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\release;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\service\release\CoreUpdatePackageService;
use app\common\service\release\CoreUpdateMaintenanceService;
use app\common\service\release\CoreUpdateJobService;
use app\common\service\release\CoreUpdateRemoteService;

use app\common\service\infra\BackupService;
use app\common\service\infra\SchemaMigrationService;
use app\common\service\site\SiteCoreLicenseService;
use app\common\support\CoreUpdatePathRules;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

final class CoreUpdateApplyService
{

    public function __construct(
        private readonly CoreUpdateRemoteService $coreUpdateRemoteService,
        private readonly CoreUpdateJobService $coreUpdateJobService,
        private readonly CoreUpdateMaintenanceService $coreUpdateMaintenanceService,
        private readonly CoreUpdatePackageService $coreUpdatePackageService,
        private readonly SchemaMigrationService $schemaMigrationService,
        private readonly BackupService $backupService,
        private readonly SiteCoreLicenseService $siteCoreLicenseService,
    ) {
    }

    private const BACKUP_DIR = 'data/runtime/core_backups';
    private const BACKUP_KEEP = 3;
    private const DISK_BUFFER_BYTES = 104857600;

    /**
     * @return array{
     *   can_apply:bool,
     *   has_update:bool,
     *   current:string,
     *   latest:string,
     *   download_url:string,
     *   sha256:string,
     *   changelog_url:string,
     *   upgrade_license_required:bool,
     *   notify_only:bool,
     *   opensource_repo_url:string,
     *   upgrade_notice:string,
     *   reason:string,
     *   backups:list<array<string,mixed>>
     * }
     */
    public function meta(bool $refresh = false): array
    {
        $check = $this->coreUpdateRemoteService->check($refresh);
        $compat = app(SiteUpgradePreflightService::class)->report($check);
        $ladder = app(CoreUpdateLadderService::class)->planFromCheck($check);
        $canApply = !empty($compat['can_start_core_upgrade']);
        $canLadder = !empty($ladder['can_auto_ladder'])
            && (int) ($ladder['steps'] ?? 0) > 0
            && (
                !empty($check['min_version_blocked'])
                || (int) ($ladder['steps'] ?? 0) > 1
                || $canApply
            );
        $reason = $this->applyBlockReason($check, !empty($check['can_apply']));
        if (!$canApply && !empty($compat['blockers'][0])) {
            $reason = (string) $compat['blockers'][0];
        } elseif (!$canApply && trim((string) ($compat['summary'] ?? '')) !== '') {
            $reason = (string) $compat['summary'];
        }
        if (!$canApply && $canLadder && trim((string) ($ladder['summary'] ?? '')) !== '') {
            $reason = (string) $ladder['summary'];
        }

        return [
            'can_apply'                => $canApply,
            'can_auto_ladder'          => $canLadder,
            'has_update'               => !empty($check['has_update']),
            'notify_only'              => !empty($check['notify_only']) || (!empty($check['has_update']) && !$canApply && !$canLadder),
            'current'                  => (string) ($check['current'] ?? ''),
            'latest'                   => (string) ($check['latest'] ?? ''),
            'download_url'             => (string) ($check['download_url'] ?? ''),
            'sha256'                   => (string) ($check['sha256'] ?? ''),
            'signature'                => (string) ($check['signature'] ?? ''),
            'changelog_url'            => (string) ($check['changelog_url'] ?? ''),
            'upgrade_license_required' => !empty($check['upgrade_license_required']),
            'opensource_repo_url'      => (string) ($check['opensource_repo_url'] ?? ''),
            'upgrade_notice'           => (string) ($check['upgrade_notice'] ?? ''),
            'min_version'              => (string) ($check['min_version'] ?? ''),
            'min_version_blocked'      => !empty($check['min_version_blocked']),
            'urgent'                   => !empty($check['urgent']),
            'reason'                   => $reason,
            'backups'                  => $this->listBackups(),
            'compatibility'            => $compat,
            'ladder'                   => $ladder,
        ];
    }

    /**
     * 后台「系统升级」用户视角摘要（版本/阶梯；库变更仅随升级包 core-step/migrate）
     *
     * @param array<string, mixed>|null $coreMeta 已算好的 meta()，避免重复联检
     * @return array<string, mixed>
     */
    public function userPanel(?array $coreMeta = null): array
    {
        $coreMeta ??= $this->meta(false);
        $job        = $this->coreUpdateJobService->activePayload();
        $upgrading  = !empty($job['active']);
        $hasUpdate  = !empty($coreMeta['has_update']);
        $canApply   = !empty($coreMeta['can_apply']);
        $canLadder  = !empty($coreMeta['can_auto_ladder']);
        $latest     = trim((string) ($coreMeta['latest'] ?? ''));

        $status = 'latest';
        if ($upgrading) {
            $status = 'upgrading';
        } elseif ($hasUpdate && ($canApply || $canLadder)) {
            $status = 'available';
        } elseif ($hasUpdate) {
            $status = 'notify_only';
        }

        $headline = trim((string) ($coreMeta['upgrade_notice'] ?? ''));
        $compatSummary = trim((string) (($coreMeta['compatibility']['summary'] ?? '') ?: ''));
        $ladderSummary = trim((string) (($coreMeta['ladder']['summary'] ?? '') ?: ''));
        if ($hasUpdate && !$canApply && $canLadder && $ladderSummary !== '') {
            $headline = $ladderSummary;
        } elseif ($hasUpdate && !$canApply && $compatSummary !== '') {
            $headline = $compatSummary;
        } elseif ($headline === '') {
            $headline = match ($status) {
                'latest'      => '当前已是最新版本，暂无需操作。',
                'available'   => $canLadder && !$canApply
                    ? '发现新版本 v' . $latest . '，将按阶梯自动连升。'
                    : '发现新版本 v' . $latest . '，可一键升级。',
                'notify_only' => '发现新版本 v' . $latest . '，请按提示手动获取升级包。',
                'upgrading'   => '升级任务进行中，请勿关闭页面。',
                default       => '',
            };
        }

        $fullCheck = $this->coreUpdateRemoteService->check(false);

        return [
            'status'        => $status,
            'status_label'  => match ($status) {
                'latest'      => '已是最新',
                'available'   => '有新版本',
                'notify_only' => '需手动升级',
                'upgrading'   => '升级进行中',
                default       => '—',
            },
            'headline'      => $headline,
            'highlights'    => $this->releaseHighlights($fullCheck),
            'highlights_empty_hint' => '官方更新清单未提供本版文字说明，请查看完整更新日志。',
            'precautions'   => [
                '升级期间前台会短暂进入维护页；请勿关闭本页，失败将尝试回滚。',
                '系统会自动备份程序文件；建议您再导出一份数据库备份。',
                '不会修改 .env 与 data/ 中的站点业务数据。',
                '版本跨度较大时按官方阶梯一档一档连升，中途失败即停。',
            ],
            'scope'         => [
                '系统程序与后台界面（admin dist）',
                '数据库结构变更随升级包内脚本自动执行',
                '客户模板、上传文件、.env / data/ 不覆盖',
            ],
            'backup_hint'   => '除系统自动备份外，建议在服务器面板再导出一份数据库。',
            'changelog_url' => trim((string) ($fullCheck['changelog_url'] ?? '')),
            'published_at'  => trim((string) ($fullCheck['published_at'] ?? '')),
            'urgent'        => !empty($fullCheck['urgent']),
            'site_license'  => $this->siteCoreLicenseService->upgradeLicenseSummary(),
            'can_auto_ladder' => $canLadder,
            'ladder_steps'  => (int) (($coreMeta['ladder']['steps'] ?? 0) ?: 0),
        ];
    }

    /**
     * @param array<string, mixed> $check
     * @return list<string>
     */
    private function releaseHighlights(array $check): array
    {
        $latest  = trim((string) ($check['latest'] ?? ''));
        $release = [];
        $releases = is_array($check['releases'] ?? null) ? $check['releases'] : [];
        foreach ($releases as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($latest !== '' && trim((string) ($row['version'] ?? '')) === $latest) {
                $release = $row;
                break;
            }
        }
        if ($release === [] && isset($releases[0]) && is_array($releases[0])) {
            $release = $releases[0];
        }

        foreach (['highlights', 'notes', 'summary'] as $field) {
            $raw = $release[$field] ?? null;
            if (is_array($raw)) {
                $lines = array_values(array_filter(array_map(
                    static fn ($v): string => trim((string) $v),
                    $raw
                )));
                if ($lines !== []) {
                    return $lines;
                }
            }
            if (is_string($raw) && trim($raw) !== '') {
                $lines = preg_split('/\r?\n+/', trim($raw)) ?: [];
                $lines = array_values(array_filter(array_map('trim', $lines)));

                return $lines !== [] ? $lines : [trim($raw)];
            }
        }

        return [];
    }

    /**
     * @return ServiceResult
     */
    public function apply(bool $refreshManifest = true, string $targetVersion = ''): ServiceResult
    {
        $prep = $this->stepPrepare($refreshManifest, $targetVersion);
        if (!$prep->isOk()) {
            return $prep;
        }
        $jobId = (string) ($prep->dataArray()['job_id'] ?? '');
        foreach (['download', 'backup', 'apply'] as $step) {
            if ($step === 'download') {
                do {
                    $result = $this->stepDownload($jobId);
                    if (!$result->isOk()) {
                        $this->stepAbort($jobId);

                        return $result;
                    }
                } while (empty($result->dataArray()['done']));
                continue;
            }
            $result = match ($step) {
                'backup' => $this->stepBackup($jobId),
                'apply'  => $this->stepApply($jobId),
                default  => ServiceResult::fail('未知升级步骤'),
            };
            if (!$result->isOk()) {
                $this->stepAbort($jobId);

                return $result;
            }
        }
        do {
            $result = $this->stepMigrate($jobId);
            if (!$result->isOk()) {
                $this->stepAbort($jobId);

                return $result;
            }
        } while (empty($result->dataArray()['done']));
        $result = $this->stepFinalize($jobId);
        if (!$result->isOk()) {
            $this->stepAbort($jobId);
        }

        return $result ?? ServiceResult::fail('升级未完成');
    }

    /**
     * @return ServiceResult
     */
    public function stepStatus(): ServiceResult
    {
        $payload = $this->coreUpdateJobService->activePayload();
        $payload['maintenance'] = $this->coreUpdateMaintenanceService->isActive();
        if (empty($payload['active'])) {
            $payload['active'] = false;
        } else {
            $payload['steps'] = CoreUpdateJobService::STEPS;
        }

        return ServiceResult::ok($payload, 'ok');
    }

    /**
     * @return ServiceResult
     */
    public function stepPrepare(bool $refreshManifest = true, string $targetVersion = ''): ServiceResult
    {
        $existing = $this->coreUpdateJobService->read();
        if ($existing !== null && !$this->coreUpdateJobService->isStale($existing)) {
            return ServiceResult::fail('已有进行中的核心升级任务，请先完成或中止');
        }
        if ($existing !== null) {
            $this->stepAbort((string) ($existing['job_id'] ?? ''));
        }

        $check = $this->coreUpdateRemoteService->check($refreshManifest);
        if (empty($check['has_update'])) {
            return ServiceResult::fail('当前已是最新版本，无需升级');
        }

        $targetVersion = trim($targetVersion);
        $ladder = app(CoreUpdateLadderService::class)->planFromCheck($check);
        if ($targetVersion === '' && !empty($check['min_version_blocked'])) {
            $targetVersion = trim((string) (($ladder['next']['version'] ?? '') ?: ''));
        }

        $releaseOverride = [];
        if ($targetVersion !== '') {
            $releaseOverride = $this->findReleaseRow($check, $targetVersion);
            if ($releaseOverride === []) {
                return ServiceResult::fail('更新清单中找不到目标版本 V' . $targetVersion);
            }
            $dl = $this->resolveReleaseDownloadUrl($check, $releaseOverride);
            if ($dl === '') {
                return ServiceResult::fail('目标版本 V' . $targetVersion . ' 缺少下载地址');
            }
            // 用目标档覆盖 check 展示字段，供联检与任务写入
            $check['latest'] = $targetVersion;
            $check['download_url'] = $dl;
            $check['sha256'] = strtolower(trim((string) ($releaseOverride['sha256'] ?? '')));
            $check['signature'] = trim((string) ($releaseOverride['signature'] ?? ''));
            $check['min_version'] = trim((string) ($releaseOverride['min_version'] ?? ''));
            $check['min_version_blocked'] = false;
            $check['can_apply'] = $this->siteCoreLicenseService->allowsOnlineCoreApply() && $dl !== '';
            $check['has_update'] = true;
        } elseif (!empty($check['min_version_blocked'])) {
            return ServiceResult::fail((string) ($check['upgrade_notice'] ?? '当前版本低于最低升级阶梯，且清单无可达中间版本'));
        }

        if (empty($check['can_apply']) && $releaseOverride === []) {
            return ServiceResult::fail($this->applyBlockReason($check, false));
        }
        if (empty($check['can_apply']) && $releaseOverride !== [] && !$this->siteCoreLicenseService->allowsOnlineCoreApply()) {
            return ServiceResult::fail($this->applyBlockReason($check, false));
        }

        $compat = app(SiteUpgradePreflightService::class)->report($check);
        if (empty($compat['can_start_core_upgrade'])) {
            $msg = trim((string) ($compat['blockers'][0] ?? $compat['summary'] ?? ''));

            return ServiceResult::fail($msg !== '' ? $msg : '已装插件与目标核心不兼容，已阻止升级');
        }

        $downloadUrl = trim((string) ($check['download_url'] ?? ''));
        if ($downloadUrl === '') {
            return ServiceResult::fail('更新清单缺少下载地址');
        }

        $packageBytes = $this->coreUpdatePackageService->estimatePackageBytes($downloadUrl);
        $disk = $this->checkDiskSpace($packageBytes);
        if (empty($disk['ok'])) {
            return ServiceResult::fail(sprintf(
                '磁盘空间不足：需要约 %s，当前可用 %s',
                $disk['need_human'] ?? '',
                $disk['free_human'] ?? '',
            ));
        }

        $lock = $this->coreUpdateMaintenanceService->engage('core_update');
        if (!$lock->isOk()) {
            return $lock;
        }

        $migrationStatus = $this->schemaMigrationService->status();
        $pendingMigrations = (int) ($migrationStatus['pending_count'] ?? 0);

        $dbBackup = $this->backupService->create(['database']);
        if (!$dbBackup->isOk()) {
            $this->coreUpdateMaintenanceService->disengage();

            return ServiceResult::fail('升级前数据库备份失败：' . (string) ($dbBackup->message() ?? ''));
        }

        $jobId = $this->coreUpdateJobService->generateId();
        $job = [
            'job_id'          => $jobId,
            'step'            => 'prepared',
            'current_version' => (string) ($check['current'] ?? ''),
            'target_version'  => (string) ($check['latest'] ?? ''),
            'download_url'    => $downloadUrl,
            'sha256'          => strtolower(trim((string) ($check['sha256'] ?? ''))),
            'signature'       => trim((string) ($check['signature'] ?? '')),
            'package_path'    => '',
            'package_part'    => '',
            'download_bytes'  => 0,
            'download_total'  => $packageBytes,
            'work_root'       => '',
            'backup_path'     => '',
            'db_backup_file'  => (string) ($dbBackup['file'] ?? ''),
            'files_copied'    => 0,
            'migrate_pending' => $pendingMigrations,
            'started_at'      => AppTime::timestamp(),
            'updated_at'      => AppTime::timestamp(),
        ];
        if (!$this->coreUpdateJobService->write($job)) {
            $this->coreUpdateMaintenanceService->disengage();

            return ServiceResult::fail('无法写入升级任务状态');
        }

        return ServiceResult::ok([
                'job_id'              => $jobId,
                'step'                => 'prepared',
                'steps'               => CoreUpdateJobService::STEPS,
                'target'              => $job['target_version'],
                'disk'                => $disk,
                'package_bytes'       => $packageBytes,
                'pending_migrations'  => $pendingMigrations,
                'db_backup'           => basename((string) ($dbBackup['file'] ?? '')),
            ], '准备完成，已进入维护模式');
    }

    /**
     * 按阶梯自动连升：每一档完整 apply，中途失败即停。
     *
     * @return ServiceResult
     */
    public function applyLadder(bool $refreshManifest = true): ServiceResult
    {
        $applied = [];
        $max = 20;
        for ($i = 0; $i < $max; ++$i) {
            $this->coreUpdateRemoteService->clearCheckMemo();
            $check = $this->coreUpdateRemoteService->check($i === 0 ? $refreshManifest : true);
            if (empty($check['has_update'])) {
                break;
            }
            $ladder = app(CoreUpdateLadderService::class)->planFromCheck($check);
            $next = is_array($ladder['next'] ?? null) ? $ladder['next'] : null;
            if ($next === null) {
                if ($applied === []) {
                    return ServiceResult::fail(trim((string) ($ladder['summary'] ?? '无法规划核心升级阶梯')));
                }
                break;
            }
            $target = trim((string) ($next['version'] ?? ''));
            if ($target === '') {
                return ServiceResult::fail('阶梯下一步版本无效');
            }
            $one = $this->apply(false, $target);
            if (!$one->isOk()) {
                return ServiceResult::fail(
                    '阶梯升到 V' . $target . ' 失败：' . (string) ($one->message() ?? ''),
                    data: [
                        'applied'   => $applied,
                        'failed_at' => $target,
                        'ladder'    => $ladder,
                    ],
                );
            }
            $applied[] = $target;
            $this->coreUpdateRemoteService->clearCheckMemo();
        }

        if ($applied === []) {
            return ServiceResult::fail('没有可应用的核心升级阶梯');
        }

        return ServiceResult::ok([
            'applied' => $applied,
            'steps'   => count($applied),
            'current' => $this->coreUpdateRemoteService->currentVersion(),
        ], '已按阶梯升级 ' . count($applied) . ' 档：V' . implode(' → V', $applied));
    }

    /**
     * @param array<string, mixed> $check
     * @return array<string, mixed>
     */
    private function findReleaseRow(array $check, string $version): array
    {
        $version = trim($version);
        foreach (is_array($check['releases'] ?? null) ? $check['releases'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['version'] ?? '')) === $version) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $check
     * @param array<string, mixed> $release
     */
    private function resolveReleaseDownloadUrl(array $check, array $release): string
    {
        $url = trim((string) ($release['download_url'] ?? ''));
        if ($url !== '') {
            return $this->coreUpdateRemoteService->resolveDownloadUrl($url);
        }
        if (trim((string) ($check['latest'] ?? '')) === trim((string) ($release['version'] ?? ''))) {
            return trim((string) ($check['download_url'] ?? ''));
        }

        return '';
    }

    /**
     * @return ServiceResult
     */
    public function stepDownload(string $jobId): ServiceResult
    {
        @set_time_limit(120);
        $job = $this->requireJob($jobId, ['prepared', 'downloading']);
        if ($job instanceof ServiceResult) {
            return $job;
        }

        $downloadUrl = (string) ($job['download_url'] ?? '');
        $local = $this->coreUpdatePackageService->resolveLocalPackagePath($downloadUrl);
        if ($local !== '' && is_file($local)) {
            $job['step'] = 'downloaded';
            $job['package_path'] = $local;
            $job['download_bytes'] = (int) filesize($local);
            $job['download_total'] = (int) filesize($local);
            $this->coreUpdateJobService->write($job);

            return $this->coreUpdatePackageService->finalizeDownloadedPackage($job, $jobId);
        }

        $part = (string) ($job['package_part'] ?? '');
        if ($part === '') {
            $part = $this->coreUpdatePackageService->workDirAbs('package-' . $jobId . '.part');
            $job['package_part'] = $part;
            $job['step'] = 'downloading';
            if ((int) ($job['download_total'] ?? 0) < 1) {
                $job['download_total'] = $this->coreUpdatePackageService->estimatePackageBytes($downloadUrl);
            }
            $this->coreUpdateJobService->write($job);
        }

        $offset = is_file($part) ? (int) filesize($part) : 0;
        $chunk = $this->coreUpdatePackageService->downloadChunk($downloadUrl, $part, $offset, CoreUpdatePackageService::DOWNLOAD_CHUNK_BYTES);
        if (empty($chunk['ok'])) {
            return ServiceResult::fail((string) ($chunk->message() ?? '下载安装包失败'));
        }

        $job['download_bytes'] = is_file($part) ? (int) filesize($part) : 0;
        // 只抬高、不压低：分块回传的 total 若误用“当前文件大小”会把 77MB 包压成 2MB
        $chunkTotal = (int) ($chunk['total'] ?? 0);
        if ($chunkTotal > (int) ($job['download_total'] ?? 0)) {
            $job['download_total'] = $chunkTotal;
        }
        $job['updated_at'] = AppTime::timestamp();
        $this->coreUpdateJobService->write($job);

        $total = (int) ($job['download_total'] ?? 0);
        $done = !empty($chunk['complete']) || ($total > 0 && $job['download_bytes'] >= $total);
        if (!$done) {
            $pct = $total > 0 ? min(99, (int) round($job['download_bytes'] * 100 / $total)) : 0;

            return ServiceResult::ok([
                    'job_id'         => $jobId,
                    'step'           => 'downloading',
                    'done'           => false,
                    'download_bytes' => $job['download_bytes'],
                    'download_total' => $total,
                    'download_pct'   => $pct,
                ], '下载中 ' . $pct . '%');
        }

        $final = $this->coreUpdatePackageService->workDirAbs('package-' . $jobId . '.zip');
        if (is_file($final)) {
            LocalFile::unlinkQuiet($final, 'core_update_package');
        }
        if (!LocalFile::renameQuiet($part, $final, 'core_update_package')) {
            if (!LocalFile::copyQuiet($part, $final, 'core_update_package')) {
                return ServiceResult::fail('安装包落盘失败');
            }
            LocalFile::unlinkQuiet($part, 'core_update_package_part');
        }

        $job['step'] = 'downloaded';
        $job['package_path'] = $final;
        $job['download_bytes'] = (int) filesize($final);
        $job['download_total'] = (int) filesize($final);
        $this->coreUpdateJobService->write($job);

        return $this->coreUpdatePackageService->finalizeDownloadedPackage($job, $jobId);
    }

    /**
     * @return ServiceResult
     */
    public function stepBackup(string $jobId): ServiceResult
    {
        @set_time_limit(600);
        $job = $this->requireJob($jobId, 'downloaded');
        if ($job instanceof ServiceResult) {
            return $job;
        }

        $backupPath = $this->createBackup((string) ($job['current_version'] ?? ''));
        if ($backupPath === null) {
            return ServiceResult::fail('升级前备份失败，已中止');
        }

        $job['step'] = 'backed_up';
        $job['backup_path'] = $backupPath;
        $job['updated_at'] = AppTime::timestamp();
        $this->coreUpdateJobService->write($job);

        return ServiceResult::ok([
                'job_id' => $jobId,
                'step'   => 'backed_up',
                'backup' => basename($backupPath),
            ], '核心文件已备份');
    }

    /**
     * @return ServiceResult
     */
    public function stepApply(string $jobId): ServiceResult
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '1024M');
        $job = $this->requireJob($jobId, 'backed_up');
        if ($job instanceof ServiceResult) {
            return $job;
        }

        $package = (string) ($job['package_path'] ?? '');
        $backupPath = (string) ($job['backup_path'] ?? '');
        $workRoot = (string) ($job['work_root'] ?? '');
        if ($workRoot === '' || !is_dir($workRoot)) {
            $workRoot = $this->prepareWorkDir();
            $job['work_root'] = $workRoot;
        }

        try {
            if (!$this->coreUpdatePackageService->extractPackage($package, $workRoot)) {
                throw new \RuntimeException('解压安装包失败');
            }
            $copied = $this->coreUpdatePackageService->applyExtractedTree($workRoot);
            $this->bumpVersionConstants((string) ($job['target_version'] ?? ''));
            $job['step'] = 'applied';
            $job['files_copied'] = $copied;
            $job['updated_at'] = AppTime::timestamp();
            $this->coreUpdateJobService->write($job);

            return ServiceResult::ok([
                    'job_id'       => $jobId,
                    'step'         => 'applied',
                    'files_copied' => $copied,
                    'version'      => (string) ($job['target_version'] ?? ''),
                ], '核心文件已覆盖');
        } catch (\Throwable $e) {
            if ($backupPath !== '' && is_file($backupPath)) {
                $this->restoreFromBackup($backupPath);
            }

            return ServiceResult::fail('覆盖文件失败并已回滚：' . $e->getMessage());
        }
    }

    /**
     * @return ServiceResult
     */
    public function stepMigrate(string $jobId): ServiceResult
    {
        @set_time_limit(120);
        $job = $this->requireJob($jobId, ['applied', 'migrating']);
        if ($job instanceof ServiceResult) {
            return $job;
        }

        // 库脚本只从升级包解压工作区跑，不写进客户 app/database/migrations
        $this->bindUpgradeMigrationSsotFromWorkRoot((string) ($job['work_root'] ?? ''));

        $migrate = $this->schemaMigrationService->runNext(false);
        if (!$migrate->isOk()) {
            return ServiceResult::fail((string) ($migrate->message() ?? '数据库迁移失败'));
        }

        $pending = (int) ($migrate->dataArray()['pending'] ?? 0);
        $done = !empty($migrate->dataArray()['done']);
        $job['migrate_pending'] = $pending;
        $job['step'] = $done ? 'migrated' : 'migrating';
        $job['updated_at'] = AppTime::timestamp();
        $this->coreUpdateJobService->write($job);

        return ServiceResult::ok([
                'job_id'     => $jobId,
                'step'       => $job['step'],
                'done'       => $done,
                'pending'    => $pending,
                'name'       => (string) ($migrate->dataArray()['name'] ?? ''),
                'migrations' => (int) ($migrate->dataArray()['ran'] ?? 0),
            ], $done
                ? '数据库迁移已全部完成'
                : ((string) ($migrate->message() ?? '迁移中') . '，剩余 ' . $pending . ' 项'));
    }

    /**
     * @return ServiceResult
     */
    public function stepFinalize(string $jobId): ServiceResult
    {
        $job = $this->requireJob($jobId, 'migrated');
        if ($job instanceof ServiceResult) {
            return $job;
        }

        $packagePath = (string) ($job['package_path'] ?? '');
        $pruned = 0;
        if ($packagePath !== '' && is_file($packagePath)) {
            $pruned = $this->coreUpdatePackageService->pruneAfterUpgrade($packagePath);
        } else {
            $pruned = $this->coreUpdatePackageService->pruneAfterUpgrade('');
        }

        $this->clearRuntimeCaches();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $this->pruneBackups();
        $this->cleanupJobArtifacts($job);
        $this->coreUpdateMaintenanceService->disengage();
        $this->coreUpdateJobService->clear();

        $target = (string) ($job['target_version'] ?? '');

        return ServiceResult::ok([
                'job_id'        => $jobId,
                'step'          => 'done',
                'version'       => $target,
                'files_copied'  => (int) ($job['files_copied'] ?? 0),
                'files_pruned'  => $pruned,
                'reload_admin'  => true,
            ], '核心已升级至 v' . $target);
    }

    /**
     * @return ServiceResult
     */
    public function restoreBackup(string $backupName): ServiceResult
    {
        @set_time_limit(600);
        $backupName = basename(trim($backupName));
        if ($backupName === '' || !preg_match('/^pivark-core-.+\\.zip$/i', $backupName)) {
            return ServiceResult::fail('备份文件名无效');
        }
        $backupPath = $this->absPath(self::BACKUP_DIR) . DIRECTORY_SEPARATOR . $backupName;
        if (!is_file($backupPath)) {
            return ServiceResult::fail('备份文件不存在');
        }

        $lock = $this->coreUpdateMaintenanceService->engage('core_restore');
        if (!$lock->isOk()) {
            return $lock;
        }

        try {
            $this->restoreFromBackup($backupPath);
            $this->clearRuntimeCaches();
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return ServiceResult::ok(['backup' => $backupName, 'reload_admin' => true], '已从备份 ' . $backupName . ' 恢复核心文件');
        } catch (\Throwable $e) {
            return ServiceResult::fail('恢复失败：' . $e->getMessage());
        } finally {
            $this->coreUpdateMaintenanceService->disengage();
        }
    }

    /**
     * 清除孤儿维护锁或超时任务（后台 bootstrap 调用）
     *
     * @return ServiceResult
     */
    public function healStaleState(): ServiceResult
    {
        if (!$this->coreUpdateMaintenanceService->isActive()) {
            return ServiceResult::ok(['healed' => false], 'ok');
        }

        $job = $this->coreUpdateJobService->read();
        if ($job !== null && !$this->coreUpdateJobService->isStale($job)) {
            return ServiceResult::ok(['healed' => false, 'active_job' => true, 'step' => (string) ($job['step'] ?? '')], 'ok');
        }

        if ($job !== null) {
            $this->stepAbort((string) ($job['job_id'] ?? ''));

            return ServiceResult::ok(['healed' => true, 'reason' => 'stale_job'], '已清理超时升级任务并退出维护模式');
        }

        $this->coreUpdateMaintenanceService->disengage();

        return ServiceResult::ok(['healed' => true, 'reason' => 'orphan_lock'], '已清除孤儿维护锁');
    }

    /**
     * @return ServiceResult
     */
    public function dryRun(bool $refreshManifest = true): ServiceResult
    {
        $check = $this->coreUpdateRemoteService->check($refreshManifest);
        $downloadUrl = trim((string) ($check['download_url'] ?? ''));
        $packageBytes = $downloadUrl !== '' ? $this->coreUpdatePackageService->estimatePackageBytes($downloadUrl) : 0;
        $disk = $this->checkDiskSpace($packageBytes);
        $migration = $this->schemaMigrationService->status();
        $nextMigration = $this->schemaMigrationService->runNext(true);

        return ServiceResult::ok([
                'check'               => $check,
                'disk'                => $disk,
                'package_bytes'       => $packageBytes,
                'pending_migrations'  => (int) ($migration['pending_count'] ?? 0),
                'next_migration'      => (string) ($nextMigration->dataArray()['next'] ?? ($nextMigration->dataArray()['name'] ?? '')),
                'apply_prefixes'      => CoreUpdatePathRules::coreUpdateApplyPrefixes(),
                'config_whitelist'    => CoreUpdatePathRules::coreUpdateConfigFiles(),
            ], 'dry-run 完成（未写入文件）');
    }

    /**
     * @return ServiceResult
     */
    public function stepAbort(string $jobId = ''): ServiceResult
    {
        $job = $jobId !== '' ? $this->coreUpdateJobService->load($jobId) : $this->coreUpdateJobService->read();
        if ($job !== null) {
            $backupPath = (string) ($job['backup_path'] ?? '');
            $step = (string) ($job['step'] ?? '');
            if ($backupPath !== '' && is_file($backupPath) && in_array($step, ['applied', 'migrated'], true)) {
                $this->restoreFromBackup($backupPath);
            }
            $this->cleanupJobArtifacts($job);
        }
        $this->coreUpdateMaintenanceService->disengage();
        $this->coreUpdateJobService->clear();

        return ServiceResult::ok(null, '已中止升级并退出维护模式');
    }

    /**
     * @param array<string, mixed> $check
     */
    private function applyBlockReason(array $check, bool $canApply): string
    {
        if ($canApply) {
            return '';
        }
        if (empty($check['has_update'])) {
            return '当前已是最新版本';
        }
        if (!empty($check['upgrade_notice'])) {
            return (string) $check['upgrade_notice'];
        }
        if (!empty($check['upgrade_license_required'])) {
            return '当前未开通域名授权在线升级。请下载免费升级包手动升级（保留 .env 与 data/），或购买基础版及以上授权后使用「系统升级」一键升级';
        }

        return '更新清单不可用或缺少下载地址';
    }

    private function createBackup(string $currentVersion): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            return null;
        }
        $dir = $this->absPath(self::BACKUP_DIR);
        if (!LocalFile::mkdirIfMissing($dir)) {
            return null;
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'pivark-core-' . preg_replace('/[^a-z0-9._-]+/i', '-', $currentVersion) . '-' . AppTime::format('YmdHis') . '.zip';
        $zip  = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $root = rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR;
        foreach (CoreUpdatePathRules::coreUpdateApplyPrefixes() as $prefix) {
            $abs = $root . str_replace('/', DIRECTORY_SEPARATOR, rtrim($prefix, '/'));
            if (!file_exists($abs)) {
                continue;
            }
            $this->addToZip($zip, $abs, rtrim($prefix, '/'));
        }
        foreach (CoreUpdatePathRules::coreUpdateConfigFiles() as $rel) {
            $abs = $root . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($abs)) {
                $zip->addFile($abs, $rel);
            }
        }

        $zip->close();

        return is_file($file) ? $file : null;
    }

    private function addToZip(ZipArchive $zip, string $absPath, string $zipPrefix): void
    {
        $projectRoot = rtrim(str_replace('\\', '/', ProjectPaths::root()), '/') . '/';
        if (is_file($absPath)) {
            $rel = str_replace('\\', '/', $absPath);
            if (str_starts_with($rel, $projectRoot)) {
                $rel = substr($rel, strlen($projectRoot));
            } else {
                $rel = $zipPrefix;
            }
            if (!CoreUpdatePathRules::shouldSkipCoreUpdatePath($rel)) {
                $zip->addFile($absPath, str_replace('\\', '/', $rel));
            }

            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absPath, \FilesystemIterator::SKIP_DOTS)
        );
        $base = rtrim(str_replace('\\', '/', $absPath), '/') . '/';
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $pathname = str_replace('\\', '/', $item->getPathname());
            $rel = str_starts_with($pathname, $projectRoot)
                ? substr($pathname, strlen($projectRoot))
                : ($zipPrefix . '/' . substr($pathname, strlen($base)));
            $rel = str_replace('\\', '/', $rel);
            if (CoreUpdatePathRules::shouldSkipCoreUpdatePath($rel)) {
                continue;
            }
            $zip->addFile($item->getPathname(), $rel);
        }
    }

    private function restoreFromBackup(string $backupFile): void
    {
        if (!is_file($backupFile) || !class_exists(ZipArchive::class)) {
            return;
        }
        $work = $this->prepareWorkDir() . DIRECTORY_SEPARATOR . 'rollback';
        $this->removeDirectory($work);
        LocalFile::mkdirIfMissing($work);
        $zip = new ZipArchive();
        if ($zip->open($backupFile) !== true) {
            return;
        }
        $zip->extractTo($work);
        $zip->close();
        $this->coreUpdatePackageService->applyExtractedTree($work);
        $this->removeDirectory($work);
    }

    private function bumpVersionConstants(string $version): void
    {
        $version = trim($version);
        if ($version === '') {
            return;
        }
        $path = ProjectPaths::root() . 'config/database.php';
        if (!is_readable($path)) {
            return;
        }
        $content = (string) file_get_contents($path);
        $content = (string) preg_replace(
            "/define\\('PIVARK_VERSION',\\s*'[^']*'\\);/",
            "define('PIVARK_VERSION', '" . addslashes($version) . "');",
            $content,
            1
        );
        $content = (string) preg_replace(
            "/define\\('PIVARK_RELEASE',\\s*'[^']*'\\);/",
            "define('PIVARK_RELEASE', '" . AppTime::format('Ymd') . "');",
            $content,
            1
        );
        LocalFile::putContents($path, $content);
    }

    private function clearRuntimeCaches(): void
    {
        $cache = ProjectPaths::runtimeDir() . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($cache)) {
            return;
        }
        foreach (glob($cache . DIRECTORY_SEPARATOR . '*') ?: [] as $entry) {
            if (is_dir($entry)) {
                LocalFile::removeDirRecursive($entry, 'core_update_cache');
            } else {
                LocalFile::unlinkQuiet($entry, 'core_update_cache');
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function listBackups(): array
    {
        $dir = $this->absPath(self::BACKUP_DIR);
        if (!is_dir($dir)) {
            return [];
        }
        $rows = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'pivark-core-*.zip') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $rows[] = [
                'name' => basename($file),
                'size' => (int) filesize($file),
                'mtime' => AppTime::format('Y-m-d H:i:s', (int) filemtime($file)),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['mtime'] ?? ''), (string) ($a['mtime'] ?? '')));

        return $rows;
    }

    private function pruneBackups(): void
    {
        $dir = $this->absPath(self::BACKUP_DIR);
        $files = glob($dir . DIRECTORY_SEPARATOR . 'pivark-core-*.zip') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, self::BACKUP_KEEP) as $old) {
            LocalFile::unlinkQuiet($old, 'core_update_backup_prune');
        }
    }

    private function prepareWorkDir(): string
    {
        $dir = $this->coreUpdatePackageService->workDirAbs('extract-' . AppTime::format('YmdHis'));
        $this->removeDirectory($dir);
        LocalFile::mkdirIfMissing($dir);

        return $dir;
    }

    private function absPath(string $rel): string
    {
        return rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    private function removeDirectory(string $path): void
    {
        LocalFile::removeDirRecursive($path, 'core_update_work');
    }

    /**
     * @param string|list<string> $expectedStep
     * @return array<string, mixed>|ServiceResult
     */
    private function requireJob(string $jobId, string|array $expectedStep): array|ServiceResult
    {
        $result = $this->coreUpdateJobService->require($jobId, $expectedStep);
        if ($result->failed()) {
            $extra = $result->extra();
            if (!empty($extra['stale'])) {
                $this->stepAbort($jobId);
            }

            return $result;
        }
        $job = $result->dataArray();
        if ($job === []) {
            return ServiceResult::fail('升级任务数据无效');
        }

        return $job;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function cleanupJobArtifacts(array $job): void
    {
        $package = (string) ($job['package_path'] ?? '');
        $workRoot = $this->coreUpdatePackageService->workDirAbs();
        if ($package !== '' && str_starts_with($package, $workRoot)) {
            LocalFile::unlinkQuiet($package, 'core_update_package');
        }
        $workRoot = (string) ($job['work_root'] ?? '');
        if ($workRoot !== '' && is_dir($workRoot)) {
            $this->removeDirectory($workRoot);
        }
    }

    /**
     * 升级包内库脚本：只绑定解压工作区，不落客户 app/database/migrations。
     */
    private function bindUpgradeMigrationSsotFromWorkRoot(string $workRoot): void
    {
        if (\defined('PIVARK_MIGRATION_SSOT')) {
            return;
        }
        $workRoot = rtrim(str_replace('\\', '/', $workRoot), '/');
        if ($workRoot === '') {
            return;
        }
        $candidates = [
            $workRoot . '/upgrade/migrations/_migration_bootstrap.php',
            $workRoot . '/upgrade/migrations/_migration.php',
            $workRoot . '/app/database/migrations/_migration_bootstrap.php',
            $workRoot . '/app/database/migrations/_migration.php',
        ];
        foreach ($candidates as $boot) {
            if (is_readable($boot)) {
                \define('PIVARK_MIGRATION_SSOT', $boot);

                return;
            }
        }
    }

    /**
     * @return array{ok:bool,free_bytes:int,required_bytes:int,package_bytes:int}
     */
    private function checkDiskSpace(int $packageBytes): array
    {
        $packageBytes = max(1, $packageBytes);
        $required = $packageBytes * 3 + self::DISK_BUFFER_BYTES;
        $path = $this->absPath('data/runtime');
        $free = @disk_free_space($path);
        if ($free === false) {
            return [
                'ok'              => true,
                'free_bytes'      => 0,
                'required_bytes'  => $required,
                'package_bytes'   => $packageBytes,
            ];
        }

        return [
            'ok'              => $free >= $required,
            'free_bytes'      => (int) $free,
            'required_bytes'  => $required,
            'package_bytes'   => $packageBytes,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
