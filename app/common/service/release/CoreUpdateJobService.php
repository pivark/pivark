<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中台
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * 核心在线升级 — 分步任务状态读写（active_job.json）
 */
declare(strict_types=1);

namespace app\common\service\release;

use app\common\support\AppTime;
use app\common\support\ServiceResult;

use app\common\support\LocalFile;
use app\common\support\ProjectPaths;

final class CoreUpdateJobService
{

    private const WORK_DIR = 'data/runtime/core_update_work';
    private const JOB_FILE = 'active_job.json';
    private const JOB_TTL_SECONDS = 7200;

    /** @var list<string> */
    public const STEPS = ['prepare', 'download', 'backup', 'apply', 'migrate', 'finalize'];

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        $path = $this->jobPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        $job = json_decode($raw, true);

        return is_array($job) ? $job : null;
    }

    /** @return array<string, mixed>|null */
    public function load(string $jobId): ?array
    {
        $job = $this->read();
        if ($job === null || (string) ($job['job_id'] ?? '') !== $jobId) {
            return null;
        }

        return $job;
    }

    /** @param array<string, mixed> $job */
    public function write(array $job): bool
    {
        $dir = $this->absPath(self::WORK_DIR);
        if (!LocalFile::mkdirIfMissing($dir)) {
            return false;
        }
        $job['updated_at'] = AppTime::timestamp();
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $json !== false && LocalFile::putContents($this->jobPath(), $json);
    }

    public function clear(): void
    {
        LocalFile::unlinkQuiet($this->jobPath(), 'core_update_job');
    }

    public function generateId(): string
    {
        return 'core-' . AppTime::format('YmdHis') . '-' . bin2hex(random_bytes(4));
    }

    /** @param array<string, mixed> $job */
    public function isStale(array $job): bool
    {
        $updated = (int) ($job['updated_at'] ?? $job['started_at'] ?? 0);

        return $updated > 0 && (AppTime::timestamp() - $updated) > self::JOB_TTL_SECONDS;
    }

    /**
     * @param string|list<string> $expectedStep
     * @return array<string, mixed>
     */
    public function require(string $jobId, string|array $expectedStep): ServiceResult
    {
        $expected = is_array($expectedStep) ? $expectedStep : [$expectedStep];
        $job = $this->load($jobId);
        if ($job === null) {
            return ServiceResult::fail('升级任务不存在或已过期');
        }
        if ($this->isStale($job)) {
            return ServiceResult::fail('升级任务已超时，请重新开始');
        }
        $current = (string) ($job['step'] ?? '');
        if (!in_array($current, $expected, true)) {
            return ServiceResult::fail('步骤顺序错误，期望「' . implode('|', $expected) . '」，实际「' . $current . '」');
        }

        return ServiceResult::ok($job);
    }

    public function nextStep(string $step): string
    {
        return match ($step) {
            'prepared', 'downloading' => 'download',
            'downloaded'            => 'backup',
            'backed_up'             => 'apply',
            'applied', 'migrating'  => 'migrate',
            'migrated'              => 'finalize',
            default                 => '',
        };
    }

    /**
     * @return array{active:bool,job_id?:string,step?:string,next_step?:string,target?:string,download_bytes?:int,download_total?:int,download_pct?:int,migrate_pending?:int}
     */
    public function activePayload(): array
    {
        $job = $this->read();
        if ($job === null) {
            return ['active' => false];
        }

        $downloadBytes = (int) ($job['download_bytes'] ?? 0);
        $downloadTotal = (int) ($job['download_total'] ?? 0);

        return [
            'active'          => true,
            'job_id'          => (string) ($job['job_id'] ?? ''),
            'step'            => (string) ($job['step'] ?? ''),
            'next_step'       => $this->nextStep((string) ($job['step'] ?? '')),
            'target'          => (string) ($job['target_version'] ?? ''),
            'download_bytes'  => $downloadBytes,
            'download_total'  => $downloadTotal,
            'download_pct'    => $downloadTotal > 0 ? min(100, (int) round($downloadBytes * 100 / $downloadTotal)) : 0,
            'migrate_pending' => (int) ($job['migrate_pending'] ?? 0),
        ];
    }

    private function jobPath(): string
    {
        return $this->absPath(self::WORK_DIR) . DIRECTORY_SEPARATOR . self::JOB_FILE;
    }

    private function absPath(string $rel): string
    {
        return rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }
}
