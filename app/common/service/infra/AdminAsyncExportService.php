<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 后台 CSV 等大导出异步任务（Session 分步，对齐 StaticHtmlBatchService 模式）
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\enum\ApiErrorCode;

use app\common\support\ServiceResult;

use think\facade\Session;

use app\common\service\export\ExportImportService;

class AdminAsyncExportService
{

    public function __construct(
        private readonly ExportImportService $exportImport,
    ) {
    }

    private const SESSION_KEY = 'pv_admin_async_export';
    private const JOB_TTL   = 7200;
    private const CHUNK_ROWS = 500;

    /**
     * @param callable(int,int):array{rows:list<list<mixed>>,done:bool,total:int} $fetchChunk
     * @return ServiceResult
     */
    public function start(string $kind, string $basename, array $headers, callable $fetchChunk): ServiceResult
    {
        $this->cleanupOldJobs();
        $probe = $fetchChunk(0, 1);
        $total = max(0, (int) ($probe['total'] ?? 0));
        if ($total < 1 && ($probe['rows'] ?? []) === []) {
            return ServiceResult::fail('没有可导出的数据');
        }
        if ($total > 0 && $total <= self::CHUNK_ROWS) {
            return ServiceResult::fail('sync', ApiErrorCode::VALIDATION, ['sync' => true, 'total' => $total]);
        }

        $job = [
            'id'         => bin2hex(random_bytes(8)),
            'kind'       => $kind,
            'basename'   => $basename,
            'headers'    => $headers,
            'cursor'     => 0,
            'total'      => $total,
            'lines'      => [],
            'created_at' => time(),
        ];
        $this->saveJob($job);

        return ServiceResult::ok([
                'job_id' => $job['id'],
                'total'  => $total,
            ], '导出任务已创建');
    }

    /**
     * @return ServiceResult
     */
    public function step(string $jobId, callable $fetchChunk): ServiceResult
    {
        $job = $this->loadJob($jobId);
        if ($job === null) {
            return ServiceResult::fail('任务不存在或已过期');
        }

        $cursor = (int) ($job['cursor'] ?? 0);
        $page   = (int) floor($cursor / self::CHUNK_ROWS) + 1;
        $chunk  = $fetchChunk($page, self::CHUNK_ROWS);
        $rows   = is_array($chunk['rows'] ?? null) ? $chunk['rows'] : [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $job['lines'][] = $row;
            }
        }
        $job['cursor'] = $cursor + count($rows);
        $total         = max((int) ($job['total'] ?? 0), (int) ($chunk['total'] ?? 0));
        $job['total']  = $total;
        $done          = !empty($chunk['done']) || $job['cursor'] >= $total || $rows === [];

        if ($done) {
            $pack = $this->exportImport->packCsv(
                (string) ($job['basename'] ?? 'export'),
                is_array($job['headers'] ?? null) ? $job['headers'] : [],
                is_array($job['lines'] ?? null) ? $job['lines'] : [],
            );
            $job['filename'] = $pack['filename'];
            $job['content']  = $pack['content'];
            $job['ready']    = true;
        }

        $this->saveJob($job);

        $msg = sprintf('已处理 %d / %d 行', (int) $job['cursor'], $total);
        if ($done) {
            $msg = '导出完成，可下载';
        }

        return ServiceResult::ok([
                'done'     => $done,
                'cursor'   => (int) $job['cursor'],
                'total'    => $total,
                'download' => $done ? 1 : 0,
            ], $msg);
    }

    /**
     * @return array{filename:string,content:string}|null
     */
    public function consumeDownload(string $jobId): ?array
    {
        $job = $this->loadJob($jobId);
        if ($job === null || empty($job['ready'])) {
            return null;
        }
        $out = [
            'filename' => (string) ($job['filename'] ?? 'export.csv'),
            'content'  => (string) ($job['content'] ?? ''),
        ];
        $this->deleteJob($jobId);

        return $out;
    }

    /** @param array<string, mixed> $job */
    private function saveJob(array $job): void
    {
        $all = Session::get(self::SESSION_KEY, []);
        if (!is_array($all)) {
            $all = [];
        }
        $all[(string) ($job['id'] ?? '')] = $job;
        Session::set(self::SESSION_KEY, $all);
    }

    /** @return array<string, mixed>|null */
    private function loadJob(string $jobId): ?array
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            return null;
        }
        $all = Session::get(self::SESSION_KEY, []);
        if (!is_array($all) || !isset($all[$jobId]) || !is_array($all[$jobId])) {
            return null;
        }
        $job = $all[$jobId];
        if (time() - (int) ($job['created_at'] ?? 0) > self::JOB_TTL) {
            $this->deleteJob($jobId);

            return null;
        }

        return $job;
    }

    private function deleteJob(string $jobId): void
    {
        $all = Session::get(self::SESSION_KEY, []);
        if (is_array($all)) {
            unset($all[$jobId]);
            Session::set(self::SESSION_KEY, $all);
        }
    }

    private function cleanupOldJobs(): void
    {
        $all = Session::get(self::SESSION_KEY, []);
        if (!is_array($all)) {
            return;
        }
        $now = time();
        foreach ($all as $id => $job) {
            if (!is_array($job) || $now - (int) ($job['created_at'] ?? 0) > self::JOB_TTL) {
                unset($all[$id]);
            }
        }
        Session::set(self::SESSION_KEY, $all);
    }
}
