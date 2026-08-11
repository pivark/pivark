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

use app\common\support\QueryLimit;
use app\common\support\DbTable;
use app\common\support\OpsLog;
use app\common\model\StaticBuildQueue;

use app\common\service\config\ConfigService;
use app\common\service\document\DocumentPublicService;
use app\common\service\seo\SeoStaticConfigService;
use app\common\service\tag\TagService;
use think\facade\Log;

/**
 * 静态构建队列：发布解耦、多 cron/worker 并行 drain，可水平扩展。
 */
final class StaticBuildQueueService
{

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly ConfigService $config,
        private readonly StaticHtmlService $staticHtml,
        private readonly SeoStaticConfigService $seoStatic,
        private readonly TagService $tags,
        private readonly DocumentPublicService $documents,
        private readonly StaticHtmlDocumentQuery $documentQuery,
    ) {
    }

    public function tableExists(): bool
    {
        return DbTable::modelExists(StaticBuildQueue::class);
    }

    public function asyncBuildEnabled(): bool
    {
        return $this->tableExists()
            && (string) $this->config->get('static_async_build', '1') === '1';
    }

    public function defaultDrainBatch(): int
    {
        $n = (int) $this->config->get('static_queue_drain_batch', '500');

        return max(50, min(2000, $n > 0 ? $n : 500));
    }

    /**
     * @param array{t:string,id?:int,page?:int,k?:string} $item
     */
    public function enqueueWork(array $item, int $priority = 10): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $key = $this->workKey($item);
        if ($key === '') {
            return;
        }
        $now     = AppTime::now();
        $payload = json_encode($item, JSON_UNESCAPED_UNICODE);
        try {
            $row = StaticBuildQueue::where('work_key', $key)->find();
            if ($row) {
                StaticBuildQueue::where('id', (int) $row['id'])->update([
                    'payload'    => $payload,
                    'priority'   => max((int) ($row['priority'] ?? 0), $priority),
                    'attempts'   => 0,
                    'last_error' => null,
                    'updated_at' => $now,
                ]);
            } else {
                StaticBuildQueue::insert([
                    'work_key'   => $key,
                    'payload'    => $payload,
                    'priority'   => $priority,
                    'attempts'   => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('static_build_queue enqueue failed ' . $key . ' ' . $e->getMessage());
        }
    }

    public function enqueueAfterArticleChange(int $documentId, string $scene = 'publish'): void
    {
        if ($documentId < 1 || !$this->staticHtml->enabled()) {
            return;
        }
        $this->enqueueWork(['t' => 'doc', 'id' => $documentId], 10);

        $flags      = $this->seoStatic->syncFlags();
        $usePublish = $scene !== 'edit';
        if ($usePublish ? $flags['publish_home'] : $flags['edit_home']) {
            $this->enqueueWork(['t' => 'home'], 90);
        }
        if ($usePublish ? $flags['publish_channel'] : $flags['edit_channel']) {
            foreach ($this->tags->getTagsForDocument($documentId) as $tag) {
                $tagId = (int) ($tag['id'] ?? 0);
                if ($tagId > 0) {
                    $this->enqueueTag($tagId);
                }
            }
        }
        if ($usePublish ? $flags['publish_adjacent'] : $flags['edit_adjacent']) {
            $adj = $this->documents->getAdjacentPublic($documentId);
            foreach (['prev', 'next'] as $k) {
                $row = $adj[$k] ?? null;
                if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                    $this->enqueueWork(['t' => 'doc', 'id' => (int) $row['id']], 12);
                }
            }
        }
    }

    public function enqueueTag(int $tagId): void
    {
        if ($tagId < 1) {
            return;
        }
        $row = \app\common\model\Tag::where('id', $tagId)->where('status', 1)->find()?->toArray();
        if (!$row) {
            return;
        }
        foreach ($this->staticHtml->tagPageNumbers($row) as $page) {
            $this->enqueueWork(['t' => 'tag', 'id' => $tagId, 'page' => $page], 70);
        }
    }

    /**
     * 全量播种：按 ID 游标把已发布文档入队（首发 / 重建，避免 PHP 内存爆）。
     *
     * @return array{enqueued:int,next_cursor:int,done:bool}
     */
    public function seedDocuments(int $cursor = 0, int $limit = QueryLimit::ADMIN_UNBOUNDED): array
    {
        if (!$this->tableExists()) {
            return ['enqueued' => 0, 'next_cursor' => 0, 'done' => true];
        }
        $limit  = max(100, min(20000, $limit));
        $ids    = $this->documentQuery->idsAfterCursor($cursor, $limit, []);
        $count  = 0;
        $lastId = $cursor;
        foreach ($ids as $id) {
            $this->enqueueWork(['t' => 'doc', 'id' => $id], 10);
            $count++;
            $lastId = $id;
        }

        return [
            'enqueued'     => $count,
            'next_cursor'  => $lastId,
            'done'         => $ids === [],
        ];
    }

    /**
     * @return array{processed:int,failed:int,remaining:int,written:int,skipped:int}
     */
    public function drain(int $limit = 0): array
    {
        if (!$this->tableExists()) {
            return ['processed' => 0, 'failed' => 0, 'remaining' => 0, 'written' => 0, 'skipped' => 0];
        }
        $limit = $limit > 0 ? min(2000, $limit) : $this->defaultDrainBatch();
        $rows  = StaticBuildQueue::where('attempts', '<', self::MAX_ATTEMPTS)
            ->order('priority', 'desc')
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        $ok    = 0;
        $fail  = 0;
        foreach ($rows as $row) {
            $qid = (int) ($row['id'] ?? 0);
            if ($qid < 1) {
                continue;
            }
            // 乐观认领：CAS 抬 attempts，失败则已被其他 worker 抢走
            $prevAttempts = (int) ($row['attempts'] ?? 0);
            $claimed      = (int) StaticBuildQueue::where('id', $qid)
                ->where('attempts', $prevAttempts)
                ->update([
                    'attempts'   => $prevAttempts + 1,
                    'updated_at' => AppTime::now(),
                ]);
            if ($claimed < 1) {
                continue;
            }
            $attempts = $prevAttempts + 1;
            $item     = json_decode((string) ($row['payload'] ?? ''), true);
            if (!is_array($item)) {
                StaticBuildQueue::where('id', $qid)->delete();
                continue;
            }
            try {
                $this->staticHtml->runWorkItem($item, $stats);
                StaticBuildQueue::where('id', $qid)->delete();
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    StaticBuildQueue::where('id', $qid)->delete();
                    Log::error('static_build_queue drop ' . (string) ($row['work_key'] ?? '') . ' ' . $e->getMessage());
                } else {
                    StaticBuildQueue::where('id', $qid)->update([
                        'last_error' => mb_substr($e->getMessage(), 0, 255),
                        'updated_at' => AppTime::now(),
                    ]);
                }
            }
        }

        $remaining = (int) StaticBuildQueue::count();

        return [
            'processed' => $ok,
            'failed'    => $fail,
            'remaining' => $remaining,
            'written'   => (int) ($stats['written'] ?? 0),
            'skipped'   => (int) ($stats['skipped'] ?? 0),
        ];
    }

    /**
     * 全量重建入队（purge manifest + 框架页 + 文档 ID 切片；余量由 cron static_build_seed_slice 续跑）。
     */
    public function scheduleFullRebuild(int $docBatchLimit = 5000): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        $this->staticHtml->purgeAll($stats);
        $stateFile = \app\common\support\ProjectPaths::runtimeDir() . 'static_build_seed_state.json';
        \app\common\support\LocalFile::unlinkIfExists($stateFile);
        $this->seedFramework();
        $res = $this->seedDocuments(0, $docBatchLimit);
        if (empty($res['done'])) {
            $dir = dirname($stateFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($stateFile, json_encode([
                'framework_done' => true,
                'doc_cursor'     => (int) ($res['next_cursor'] ?? 0),
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /** 首页/栏目/标签列表等框架页（全量重建第一步） */
    public function seedFramework(): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $this->enqueueWork(['t' => 'home'], 90);
        foreach (\app\common\model\SitePage::where('status', 1)->column('id') as $id) {
            $this->enqueueWork(['t' => 'page', 'id' => (int) $id], 60);
        }
        $this->enqueueWork(['t' => 'sys', 'k' => 'documents'], 80);
        $this->enqueueWork(['t' => 'sys', 'k' => 'tags'], 80);
        foreach (\app\common\model\Tag::where('status', 1)->select()->toArray() as $row) {
            $tagId = (int) ($row['id'] ?? 0);
            if ($tagId > 0) {
                $this->enqueueTag($tagId);
            }
        }
    }

    public function pendingCount(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        try {
            return (int) StaticBuildQueue::count();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('static_build_queue_count_failed', ['msg' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * @param array{t:string,id?:int,page?:int,k?:string} $item
     */
    private function workKey(array $item): string
    {
        $type = (string) ($item['t'] ?? '');
        return match ($type) {
            'home' => 'home',
            'sys'  => 'sys:' . (string) ($item['k'] ?? ''),
            'page' => 'page:' . (int) ($item['id'] ?? 0),
            'tag'  => 'tag:' . (int) ($item['id'] ?? 0) . ':' . max(1, (int) ($item['page'] ?? 1)),
            'doc'  => 'doc:' . (int) ($item['id'] ?? 0),
            default => '',
        };
    }
}
