<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\ServiceResult;

use app\common\model\Document;
use think\facade\Cache;

/** 全站 documents.search_text 分批重建（含 document-addon 块） */
final class DocumentSearchTextBackfillService
{

    public function __construct(
        private readonly DocumentSearchEnrichService $enrich,
        private readonly SearchIndexService $searchIndex,
        private readonly SearchTextExtractor $textExtractor,
        private readonly DocumentAddonSearchRegistry $addonRegistry,
    ) {
    }

    private const CACHE_KEY = 'document_search_backfill_state';
    private const DEFAULT_BATCH = 200;

    /** @param array{last_id?:int,reason?:string,updated?:int,scanned?:int,started_at?:int} $extra */
    public function scheduleFullRebuild(string $reason = '', array $extra = []): void
    {
        $state = [
            'last_id'     => 0,
            'reason'      => trim($reason),
            'updated'     => 0,
            'scanned'     => 0,
            'started_at'  => time(),
            'finished_at' => 0,
        ];
        foreach ($extra as $k => $v) {
            $state[$k] = $v;
        }
        Cache::set(self::CACHE_KEY, $state, 86400 * 7);
    }

    /**
     * @return ServiceResult
     */
    public function processBatch(int $lastId = 0, int $limit = self::DEFAULT_BATCH, bool $useCache = true): ServiceResult
    {
        $limit = min(max($limit, 1), 500);
        $state = $useCache ? (Cache::get(self::CACHE_KEY) ?: null) : null;
        if (is_array($state) && $lastId < 1) {
            $lastId = (int) ($state['last_id'] ?? 0);
        }

        $batchUpdated = 0;
        $batchScanned = 0;
        /** @var array<int, string> $prevById */
        $prevById = [];
        $rows         = Document::whereNull('deleted_at')
            ->where('id', '>', $lastId)
            ->order('id', 'asc')
            ->limit($limit)
            ->field('id,search_text')
            ->select()
            ->toArray();

        if ($rows === []) {
            if (is_array($state)) {
                $state['finished_at'] = time();
                Cache::set(self::CACHE_KEY, $state, 3600);
            }

            return ServiceResult::ok(['done' => true, 'last_id' => $lastId, 'scanned' => (int) ($state['scanned'] ?? 0), 'updated' => (int) ($state['updated'] ?? 0), 'batch_updated' => 0, 'reason' => (string) ($state['reason'] ?? ''), 'started_at' => (int) ($state['started_at'] ?? 0), 'finished_at' => time()], '全文重建已完成');
        }

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $lastId = $id;
            $batchScanned++;
            $prevById[$id] = (string) ($row['search_text'] ?? '');
        }

        $ids = array_keys($prevById);
        foreach ($ids as $id) {
            $this->enrich->rebuildForDocument($id);
        }

        $afterMap = Document::whereIn('id', $ids)->column('search_text', 'id');

        foreach ($ids as $id) {
            $next = (string) ($afterMap[$id] ?? '');
            if ($next !== ($prevById[$id] ?? '')) {
                $batchUpdated++;
            }
        }

        $totalScanned = $batchScanned;
        $totalUpdated = $batchUpdated;
        if (is_array($state)) {
            $totalScanned = (int) ($state['scanned'] ?? 0) + $batchScanned;
            $totalUpdated = (int) ($state['updated'] ?? 0) + $batchUpdated;
            $state['last_id'] = $lastId;
            $state['scanned'] = $totalScanned;
            $state['updated'] = $totalUpdated;
            Cache::set(self::CACHE_KEY, $state, 86400 * 7);
        }

        return ServiceResult::ok(['done' => false, 'last_id' => $lastId, 'scanned' => $totalScanned, 'updated' => $totalUpdated, 'batch_updated' => $batchUpdated, 'reason' => is_array($state) ? (string) ($state['reason'] ?? '') : '', 'started_at' => is_array($state) ? (int) ($state['started_at'] ?? 0) : 0, 'finished_at' => 0], "已处理至文档 #{$lastId}，本批更新 {$batchUpdated} 条");
    }

    /** @return array{active:bool,state:array<string,mixed>|null} */
    public function status(): array
    {
        $state = Cache::get(self::CACHE_KEY);

        return [
            'active' => is_array($state) && empty($state['finished_at']),
            'state'  => is_array($state) ? $state : null,
        ];
    }

    public function clearSchedule(): void
    {
        Cache::delete(self::CACHE_KEY);
    }

    /** @return ServiceResult */
    public function finishWithOptionalReindex(bool $reindexMeili): ServiceResult
    {
        $this->clearSchedule();
        if (!$reindexMeili) {
            return ServiceResult::ok(null, '重建任务已清除');
        }
        $res = $this->searchIndex->reindexAll();
        $msg = '全文重建完成：' . (string) $res->message();

        return $res->isOk()
            ? ServiceResult::ok($res->data(), $msg)
            : ServiceResult::fail($msg);
    }

    /**
     * @return ServiceResult
     */
    public function previewForAdmin(int $documentId): ServiceResult
    {
        if ($documentId < 1) {
            return ServiceResult::fail('参数错误');
        }
        $row = $this->documentRow(Document::where('id', $documentId)->whereNull('deleted_at')->find());
        if ($row === null) {
            return ServiceResult::fail('文档不存在');
        }
        $sections = $this->addonRegistry->sectionsForDocument($documentId);
        $preview  = $this->textExtractor->forDocument($documentId, $row);
        $max      = 4000;
        if (mb_strlen($preview) > $max) {
            $preview = mb_substr($preview, 0, $max) . '
…（已截断）';
        }

        return ServiceResult::ok(['sections' => $sections, 'preview' => $preview, 'chars' => mb_strlen((string) ($row['search_text'] ?? ''))], '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentRow(mixed $result): ?array
    {
        return $result instanceof Document ? $result->toArray() : null;
    }
}
