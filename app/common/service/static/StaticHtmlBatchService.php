<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\static;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\service\static\StaticHtmlSkipSupport;
use app\common\service\static\StaticHtmlService;
use app\common\service\static\StaticHtmlDocumentQuery;
use app\common\service\static\StaticHtmlBatchValidator;
use app\common\service\static\StaticHtmlPathService;

use app\common\support\LocalFile;
use app\common\service\theme\ThemeService;
use app\common\service\site\SiteUrlModeService;
use app\common\service\seo\SeoStaticConfigService;
use app\common\model\Document;
use app\common\model\DocumentTag;
use app\common\model\SitePage;
use app\common\model\Tag;
use app\common\support\SiteUrl;

/** 静态 HTML 分批生成（后台 HTML 生成页 + cron 切片） */
class StaticHtmlBatchService
{

    public function __construct(
        private readonly StaticHtmlPathService $staticHtmlPathService,
        private readonly ThemeService $themeService,
        private readonly SiteUrlModeService $siteUrlModeService,
        private readonly SeoStaticConfigService $seoStaticConfigService,
        private readonly StaticHtmlBatchValidator $staticHtmlBatchValidator,
        private readonly StaticHtmlDocumentQuery $staticHtmlDocumentQuery,
        private readonly StaticHtmlService $staticHtmlService,
        private readonly StaticHtmlSkipSupport $staticHtmlSkipSupport,
    ) {
    }

    private const JOB_TTL         = 7200;

    /** @return array<string, mixed> */
    public function homeInfo(): array
    {
        $rel = $this->staticHtmlPathService->relativePathFromUrl(SiteUrl::home()) ?? 'index.html';
        $theme = $this->themeService->getCurrentTheme();

        return [
            'template'      => 'template/' . $theme . '/index.php',
            'url'           => SiteUrl::home(),
            'relative_path' => $rel,
            'absolute_path' => $this->staticHtmlPathService->absolutePath($rel),
            'is_static'     => $this->siteUrlModeService->isStatic(),
            'storage_hint'  => $this->seoStaticConfigService->storageHint(),
        ];
    }

    public function normalizeBatchSize(int $size): int
    {
        return $this->staticHtmlBatchValidator->normalizeBatchSize($size);
    }

    public function normalizeCronBatchSize(int $size): int
    {
        return $this->staticHtmlBatchValidator->normalizeCronBatchSize($size);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function shouldUseLazyDocs(array $params): bool
    {
        if ($this->staticHtmlBatchValidator->hasDocIdRange($params)) {
            return false;
        }

        return $this->staticHtmlDocumentQuery->countPublished($params) > StaticHtmlBatchValidator::LAZY_DOC_THRESHOLD;
    }

    /**
     * @param array<string, mixed> $params
     * @return ServiceResult
     */
    public function start(array $params): ServiceResult
    {
        if (!$this->staticHtmlService->enabled()) {
            return ServiceResult::fail('请先在 URL 配置中将链接模式设为「静态页面」');
        }

        $this->cleanupOldJobs();

        $type = $this->staticHtmlBatchValidator->normalizeBuildType((string) ($params['type'] ?? ''));
        if ($type === null) {
            return ServiceResult::fail('无效的生成类型');
        }

        $mode      = strtolower(trim((string) ($params['mode'] ?? 'all')));
        $batchSize = $this->normalizeBatchSize((int) ($params['batch_size'] ?? 20));
        $lazyDocs  = in_array($type, ['all', 'document'], true) && $this->shouldUseLazyDocs($params);
        $queue     = $this->buildQueue($type, $params, $lazyDocs);
        $docTotal  = $lazyDocs ? $this->staticHtmlDocumentQuery->countPublished($params) : 0;

        if ($queue === [] && $type !== 'home' && !$lazyDocs) {
            return ServiceResult::fail('没有符合条件的页面需要生成');
        }

        $startPhase = ($lazyDocs && $queue === []) ? 'docs' : 'queue';

        $job = [
            'id'             => bin2hex(random_bytes(8)),
            'type'           => $type,
            'mode'           => $mode,
            'batch_size'     => $batchSize,
            'queue_cursor'   => 0,
            'phase'          => $startPhase,
            'lazy_docs'      => $lazyDocs,
            'doc_cursor'     => 0,
            'docs_processed' => 0,
            'doc_params'     => $lazyDocs ? $params : [],
            'doc_total'      => $docTotal,
            'purge_done'     => $type !== 'all' || $mode !== 'all',
            'stats'          => ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []],
            'queue'          => $queue,
            'created_at'     => time(),
        ];

        $this->saveJob($job);

        return ServiceResult::ok([
                'job_id'     => $job['id'],
                'total'      => count($queue) + $docTotal,
                'batch_size' => $batchSize,
                'lazy_docs'  => $lazyDocs,
            ], '任务已创建');
    }

    /**
     * @return ServiceResult
     */
    public function step(string $jobId): ServiceResult
    {
        $job = $this->loadJob($jobId);
        if ($job === null) {
            return ServiceResult::fail('任务不存在或已过期，请重新开始');
        }

        /** @var array{written:int,skipped:int,deleted:int,errors:list<string>} $stats */
        $stats = &$job['stats'];
        $writtenBefore = (int) ($stats['written'] ?? 0);

        if (($job['type'] ?? '') === 'all' && ($job['mode'] ?? 'all') === 'all' && !($job['purge_done'] ?? false)) {
            $this->staticHtmlService->purgeAll($stats);
            $job['purge_done'] = true;
        }

        $queue       = is_array($job['queue'] ?? null) ? $job['queue'] : [];
        $queueLen    = count($queue);
        $batchSize   = max(1, (int) ($job['batch_size'] ?? 20));
        $lazyDocs    = !empty($job['lazy_docs']);
        $docTotal    = (int) ($job['doc_total'] ?? 0);
        $totalUnits  = $queueLen + ($lazyDocs ? $docTotal : 0);
        $phase       = (string) ($job['phase'] ?? 'queue');
        $queueCursor = (int) ($job['queue_cursor'] ?? 0);

        if ($phase === 'queue' && $queueLen === 0 && $lazyDocs) {
            $job['phase'] = 'docs';
            $phase       = 'docs';
        }

        if ($phase === 'queue') {
            $deadline = microtime(true) + StaticHtmlBatchValidator::STEP_TIME_BUDGET_SEC;
            $endCap   = min($queueCursor + $batchSize, $queueLen);
            $i        = $queueCursor;
            for (; $i < $endCap; $i++) {
                if ($i > $queueCursor && microtime(true) >= $deadline) {
                    break;
                }
                $item = $queue[$i] ?? null;
                if (!is_array($item)) {
                    continue;
                }
                try {
                    $this->staticHtmlService->runWorkItem($item, $stats);
                } catch (\Throwable $e) {
                    $stats['errors'][] = $e->getMessage();
                }
            }
            $job['queue_cursor'] = $i;
            if ($i >= $queueLen) {
                $job['phase'] = $lazyDocs ? 'docs' : 'done';
            }
        }

        if (($job['phase'] ?? '') === 'docs') {
            $deadline  = microtime(true) + StaticHtmlBatchValidator::STEP_TIME_BUDGET_SEC;
            $docParams = is_array($job['doc_params'] ?? null) ? $job['doc_params'] : [];
            $docCursor = (int) ($job['doc_cursor'] ?? 0);
            $ids       = $this->staticHtmlDocumentQuery->idsAfterCursor($docCursor, $batchSize, $docParams);
            $nDone     = 0;
            foreach ($ids as $id) {
                if ($nDone > 0 && microtime(true) >= $deadline) {
                    break;
                }
                try {
                    $this->staticHtmlService->runWorkItem(['t' => 'doc', 'id' => $id], $stats);
                } catch (\Throwable $e) {
                    $stats['errors'][] = $e->getMessage();
                }
                $docCursor = $id;
                $nDone++;
            }
            $job['doc_cursor']     = $docCursor;
            $job['docs_processed'] = (int) ($job['docs_processed'] ?? 0) + $nDone;
            // 本批一个都没取到 → 文档耗尽；取到但未跑完 → 下一拍接着 cursor
            if ($ids === []) {
                $job['phase'] = 'done';
            }
        }

        $done = ($job['phase'] ?? '') === 'done';

        $processed = min((int) ($job['queue_cursor'] ?? 0), $queueLen)
            + ($lazyDocs ? (int) ($job['docs_processed'] ?? 0) : max(0, (int) ($job['queue_cursor'] ?? 0) - $queueLen));

        if (!$lazyDocs) {
            $processed = (int) ($job['queue_cursor'] ?? 0);
        }

        $this->saveJob($job);

        $writtenNow = (int) ($stats['written'] ?? 0) - $writtenBefore;
        $msg        = sprintf(
            '进度 %d / %d，本次写入 %d 个文件',
            min($processed, max(1, $totalUnits)),
            max(1, $totalUnits),
            max(0, $writtenNow)
        );
        if ($done) {
            $this->deleteJob($jobId);
            $msg = sprintf(
                '生成完成：共处理 %d 项，写入 %d，跳过 %d（未改跳过 %s）',
                max($processed, $totalUnits),
                (int) ($stats['written'] ?? 0),
                (int) ($stats['skipped'] ?? 0),
                $this->staticHtmlSkipSupport->enabled() ? '开' : '关'
            );
            if (!empty($stats['errors'])) {
                $msg .= '；' . implode('；', array_slice($stats['errors'], 0, 2));
            }
        }

        return ServiceResult::ok([
                'done'   => $done,
                'cursor' => min($processed, max(1, $totalUnits)),
                'total'  => max(1, $totalUnits),
                'stats'  => $stats,
                'phase'  => (string) ($job['phase'] ?? ''),
            ], $msg);
    }

    /**
     * 定时任务：每次只生成一批，状态持久化（避免 rebuildAll 一次吃光内存/超时）。
     *
     * @param array<string, mixed> $payload
     * @return ServiceResult
     */
    public function cronSlice(array $payload = []): ServiceResult
    {
        if (!$this->staticHtmlService->enabled()) {
            return ServiceResult::ok(null, 'SKIP:非静态模式');
        }

        $batchSize = $this->normalizeCronBatchSize((int) ($payload['batch'] ?? 200));
        $state     = $this->loadCronState();
        $purge     = !empty($payload['purge']) || empty($state);

        if ($purge) {
            $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
            $this->staticHtmlService->purgeAll($stats);
            $state = [
                'phase'          => 'queue',
                'queue_cursor'   => 0,
                'doc_cursor'     => 0,
                'docs_processed' => 0,
                'purge_done'     => true,
            ];
        }

        $params = [
            'type'       => 'all',
            'mode'       => trim((string) ($payload['mode'] ?? 'time')) !== '' ? (string) $payload['mode'] : 'time',
            'since'      => (string) ($payload['since'] ?? AppTime::format('Y-m-d H:i:s', time() - 86400)),
            'batch_size' => $batchSize,
        ];
        if (($params['mode'] ?? '') === 'all') {
            unset($params['since']);
        }

        $lazyDocs = $this->shouldUseLazyDocs($params);
        if ($purge || empty($state['queue'])) {
            $state['queue']      = $this->buildQueue('all', $params, $lazyDocs);
            $state['lazy_docs']  = $lazyDocs;
            $state['doc_params'] = $params;
            $state['doc_total']  = $lazyDocs ? $this->staticHtmlDocumentQuery->countPublished($params) : 0;
            $state['phase']      = 'queue';
            $state['queue_cursor'] = 0;
            $state['doc_cursor'] = 0;
            $state['docs_processed'] = 0;
            $queueLen = count($state['queue']);
            if ($queueLen === 0 && $lazyDocs) {
                $state['phase'] = 'docs';
            }
        }

        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        $phase = (string) ($state['phase'] ?? 'queue');
        $queue = is_array($state['queue'] ?? null) ? $state['queue'] : [];
        $queueLen = count($queue);

        if ($phase === 'queue') {
            $qc  = (int) ($state['queue_cursor'] ?? 0);
            $end = min($qc + $batchSize, $queueLen);
            for ($i = $qc; $i < $end; $i++) {
                $item = $queue[$i] ?? null;
                if (is_array($item)) {
                    $this->staticHtmlService->runWorkItem($item, $stats);
                }
            }
            $state['queue_cursor'] = $end;
            if ($end >= $queueLen) {
                $state['phase'] = $lazyDocs ? 'docs' : 'done';
            }
        } elseif ($phase === 'docs') {
            $docParams = is_array($state['doc_params'] ?? null) ? $state['doc_params'] : $params;
            $docCursor = (int) ($state['doc_cursor'] ?? 0);
            $ids       = $this->staticHtmlDocumentQuery->idsAfterCursor($docCursor, $batchSize, $docParams);
            foreach ($ids as $id) {
                $this->staticHtmlService->runWorkItem(['t' => 'doc', 'id' => $id], $stats);
                $state['doc_cursor'] = $id;
            }
            $state['docs_processed'] = (int) ($state['docs_processed'] ?? 0) + count($ids);
            if ($ids === []) {
                $state['phase'] = 'done';
            }
        }

        $finished = ($state['phase'] ?? '') === 'done';
        if ($finished) {
            $this->clearCronState();
        } else {
            $this->saveCronState($state);
        }

        return ServiceResult::ok(null, sprintf(
                '静态切片：写入 %d，跳过 %d，阶段 %s%s',
                (int) ($stats['written'] ?? 0),
                (int) ($stats['skipped'] ?? 0),
                (string) ($state['phase'] ?? ''),
                $finished ? '（本轮全量结束）' : ''
            ));
    }

    private function cronStateFile(): string
    {
        return \app\common\support\ProjectPaths::runtimeDir() . 'static_html_cron_state.json';
    }

    /** @return array<string, mixed> */
    private function loadCronState(): array
    {
        $file = $this->cronStateFile();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $state */
    private function saveCronState(array $state): void
    {
        $file = $this->cronStateFile();
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    private function clearCronState(): void
    {
        $file = $this->cronStateFile();
        if (is_file($file)) {
            LocalFile::unlinkIfExists($file);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function buildQueue(string $type, array $params, bool $lazyDocs = false): array
    {
        return match ($type) {
            'home'     => [['t' => 'home']],
            'tag'      => $this->buildTagQueue($params),
            'document' => $this->buildDocumentQueue($params),
            'all'      => $this->buildAllQueue($params, $lazyDocs),
            default    => [],
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function buildAllQueue(array $params, bool $lazyDocs = false): array
    {
        $mode = strtolower(trim((string) ($params['mode'] ?? 'all')));

        if ($mode === 'time' || $mode === 'id') {
            if ($lazyDocs) {
                return [];
            }

            return $this->buildDocumentQueue(array_merge($params, ['type' => 'document']));
        }

        $queue = [['t' => 'home']];
        foreach (SitePage::where('status', 1)->column('id') as $id) {
            $queue[] = ['t' => 'page', 'id' => (int) $id];
        }
        $queue[] = ['t' => 'sys', 'k' => 'documents'];
        $queue[] = ['t' => 'sys', 'k' => 'tags'];
        foreach (Tag::where('status', 1)->select()->toArray() as $row) {
            $tagId = (int) ($row['id'] ?? 0);
            foreach ($this->staticHtmlService->tagPageNumbers($row) as $page) {
                $queue[] = ['t' => 'tag', 'id' => $tagId, 'page' => $page];
            }
        }
        if (!$lazyDocs) {
            foreach ($this->documentIds($params) as $id) {
                $queue[] = ['t' => 'doc', 'id' => $id];
            }
        }

        return $queue;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function buildTagQueue(array $params): array
    {
        $tagId = (int) ($params['tag_id'] ?? 0);
        $rows  = $tagId > 0
            ? (Tag::where('id', $tagId)->where('status', 1)->select()->toArray() ?: [])
            : Tag::where('status', 1)->select()->toArray();

        $queue = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            foreach ($this->staticHtmlService->tagPageNumbers($row) as $page) {
                $queue[] = ['t' => 'tag', 'id' => $id, 'page' => $page];
            }
        }

        return $queue;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function buildDocumentQueue(array $params): array
    {
        $queue = [];
        foreach ($this->documentIds($params) as $id) {
            $queue[] = ['t' => 'doc', 'id' => $id];
        }

        return $queue;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<int>
     */
    public function documentIds(array $params): array
    {
        $cursor = 0;
        $all    = [];
        while (true) {
            $chunk = $this->staticHtmlDocumentQuery->idsAfterCursor($cursor, 500, $params);
            if ($chunk === []) {
                break;
            }
            foreach ($chunk as $id) {
                $all[]  = $id;
                $cursor = $id;
            }
            if (count($chunk) < 500) {
                break;
            }
        }

        return $all;
    }

    private function jobDir(): string
    {
        $base = defined('RUNTIME_PATH') ? RUNTIME_PATH : \app\common\support\ProjectPaths::runtimeDir();
        $dir  = rtrim($base, '/\\') . '/static_html_batch';
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

        return is_array($data) ? $data : null;
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
