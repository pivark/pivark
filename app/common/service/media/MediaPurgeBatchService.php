<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 素材库无效资源分批清理（runtime 文件任务，避免 HTTP 超时与内存暴涨）
 */
declare(strict_types=1);

namespace app\common\service\media;

use app\common\support\ServiceResult;
use app\common\service\media\MediaLibraryService;
use app\common\service\media\MediaAssetRefService;

use app\common\support\LocalFile;
use app\common\support\ProjectPaths;

class MediaPurgeBatchService
{

    public function __construct(
        private readonly MediaAssetRefService $mediaAssetRefService,
    ) {
    }

    private const JOB_TTL    = 7200;
    private const BATCH_SIZE = 50;

    private function library(): MediaLibraryService
    {
        return app(MediaLibraryService::class);
    }

    /**
     * @param array{kind?:string,folder?:string} $params
     * @return ServiceResult
     */
    public function start(array $params): ServiceResult
    {
        if (!$this->mediaAssetRefService->isEnabled()) {
            return ServiceResult::fail('未启用影子引用表，海量文档下无法安全批量清理。请先执行 migrate_media_asset_refs.php 与 migrate_media_refs_backfill.php。');
        }

        $this->cleanupOldJobs();

        $params['mode'] = $this->library()->resolvePurgeScanMode($params['mode'] ?? null);

        $paths = is_array($params['paths'] ?? null) ? array_values($params['paths']) : null;
        if ($paths === null) {
            $scan = $this->library()->scanInvalidResourcesInternal($params);
            if (!empty($scan['blocked'])) {
                return ServiceResult::fail((string) ($scan['block_reason'] ?? '无法启动清理任务'));
            }
            $paths = is_array($scan['paths'] ?? null) ? $scan['paths'] : [];
        }

        if ($paths === []) {
            return ServiceResult::fail('未发现可清理的无效资源');
        }

        $job = [
            'id'         => bin2hex(random_bytes(8)),
            'kind'       => ($params['kind'] ?? 'image') === 'software' ? 'software' : 'image',
            'folder'     => trim(str_replace('\\', '/', (string) ($params['folder'] ?? '')), '/'),
            'mode'       => (string) ($params['mode'] ?? $this->library()->defaultPurgeScanMode()),
            'paths'      => $paths,
            'cursor'     => 0,
            'deleted'    => 0,
            'failed'     => [],
            'created_at' => time(),
        ];
        $this->saveJob($job);

        return ServiceResult::ok([
                'job_id'     => $job['id'],
                'total'      => count($paths),
                'batch_size' => self::BATCH_SIZE,
            ], '清理任务已创建');
    }

    /**
     * @return ServiceResult
     */
    public function step(string $jobId): ServiceResult
    {
        $job = $this->loadJob($jobId);
        if ($job === null) {
            return ServiceResult::fail('任务不存在或已过期，请重新扫描');
        }

        $paths  = is_array($job['paths'] ?? null) ? $job['paths'] : [];
        $total  = count($paths);
        $cursor = (int) ($job['cursor'] ?? 0);
        $kind   = (string) ($job['kind'] ?? 'image');
        $slice  = array_slice($paths, $cursor, self::BATCH_SIZE);

        foreach ($slice as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            if (!$this->mediaLibraryService->isUnusedUploadPathFast($path)) {
                continue;
            }
            $res = $this->mediaLibraryService->deleteFile($path, $kind);
            if ($res->isOk()) {
                $job['deleted'] = (int) ($job['deleted'] ?? 0) + 1;
            } else {
                $failed = is_array($job['failed'] ?? null) ? $job['failed'] : [];
                $failed[] = $path;
                $job['failed'] = $failed;
            }
        }

        $job['cursor'] = $cursor + count($slice);
        $done          = (int) $job['cursor'] >= $total || $slice === [];

        if ($done) {
            $this->deleteJob($jobId);
        } else {
            $this->saveJob($job);
        }

        $deleted = (int) ($job['deleted'] ?? 0);
        $failedN = count(is_array($job['failed'] ?? null) ? $job['failed'] : []);
        $msg     = sprintf('已处理 %d / %d，已删除 %d 个', (int) $job['cursor'], $total, $deleted);
        if ($done) {
            $msg = $failedN > 0
                ? sprintf('清理完成：已删除 %d 个，%d 个失败', $deleted, $failedN)
                : sprintf('清理完成：已删除 %d 个无效资源', $deleted);
        }

        return ServiceResult::ok([
                'done'    => $done,
                'cursor'  => (int) $job['cursor'],
                'total'   => $total,
                'deleted' => $deleted,
                'failed'  => $failedN,
            ], $msg);
    }

    private function jobDir(): string
    {
        $base = defined('RUNTIME_PATH') ? RUNTIME_PATH : ProjectPaths::runtimeDir();
        $dir  = rtrim($base, '/\\') . '/media_purge_batch';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /** @param array<string, mixed> $job */
    private function saveJob(array $job): void
    {
        $id = (string) ($job['id'] ?? '');
        if ($id === '' || !preg_match('/^[a-f0-9]{16}$/', $id)) {
            return;
        }
        file_put_contents($this->jobDir() . '/' . $id . '.json', json_encode($job, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed>|null */
    private function loadJob(string $jobId): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $jobId)) {
            return null;
        }
        $file = $this->jobDir() . '/' . $jobId . '.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }
        if (time() - (int) ($data['created_at'] ?? 0) > self::JOB_TTL) {
            $this->deleteJob($jobId);

            return null;
        }

        return $data;
    }

    private function deleteJob(string $jobId): void
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $jobId)) {
            return;
        }
        $file = $this->jobDir() . '/' . $jobId . '.json';
        if (is_file($file)) {
            LocalFile::unlinkIfExists($file);
        }
    }

    private function cleanupOldJobs(): void
    {
        $dir = $this->jobDir();
        $now = time();
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if ($now - (int) filemtime($file) > self::JOB_TTL) {
                LocalFile::unlinkIfExists($file);
            }
        }
    }
}
