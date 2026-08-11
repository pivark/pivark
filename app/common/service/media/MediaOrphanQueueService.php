<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 媒体孤儿增量队列：引用归零时入队，清理时只消费队列（极快，不扫全盘）
 */
declare(strict_types=1);

namespace app\common\service\media;

use app\common\support\AppTime;
use app\common\support\OpsLog;
use app\common\service\media\MediaAssetRefService;
use app\common\service\media\MediaLibraryPathService;
use app\common\support\DbTable;
use app\common\model\MediaOrphanQueue;

class MediaOrphanQueueService
{

    public function __construct(
        private readonly MediaLibraryPathService $mediaLibraryPathService,
    ) {
    }

    private const MAX_PURGE_BATCH = 5000;

    private static ?bool $tableReady = null;

    public function tableExists(): bool
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }
        self::$tableReady = DbTable::modelExists(MediaOrphanQueue::class);

        return self::$tableReady;
    }

    public function isEnabled(): bool
    {
        return $this->tableExists();
    }

    public function enqueue(string $pathOrUrl, int $mediaAssetId = 0, string $reason = 'ref_zero'): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $path = app(MediaAssetRefService::class)->normalizePath($pathOrUrl);
        if ($path === '') {
            return;
        }

        $kind   = $this->mediaLibraryPathService->kindFromPathPublic($path);
        $reason = mb_substr(trim($reason), 0, 32) ?: 'ref_zero';
        $now    = AppTime::now();

        try {
            $exists = MediaOrphanQueue::where('path', $path)->find();
            if ($exists) {
                MediaOrphanQueue::where('id', (int) $exists['id'])->update([
                    'media_asset_id' => max(0, $mediaAssetId),
                    'kind'           => $kind,
                    'reason'         => $reason,
                    'created_at'     => $now,
                ]);
            } else {
                MediaOrphanQueue::insert([
                    'path'           => $path,
                    'media_asset_id' => max(0, $mediaAssetId),
                    'kind'           => $kind,
                    'reason'         => $reason,
                    'created_at'     => $now,
                ]);
            }
        } catch (\Throwable $e) {
            OpsLog::businessWarning('media_orphan_queue_enqueue_failed', [
                'path'   => $pathOrUrl,
                'reason' => $reason,
                'msg'    => $e->getMessage(),
            ]);
        }
    }

    public function remove(string $pathOrUrl): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $path = app(MediaAssetRefService::class)->normalizePath($pathOrUrl);
        if ($path === '') {
            return;
        }
        try {
            MediaOrphanQueue::where('path', $path)->delete();
        } catch (\Throwable $e) { OpsLog::businessWarning('media_orphan_queue_optional_failed', ['msg' => $e->getMessage()]); }
    }

    /**
     * @return list<string>
     */
    public function pathsForPurge(string $kind, string $folder = '', int $limit = self::MAX_PURGE_BATCH): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $limit = max(1, min(self::MAX_PURGE_BATCH, $limit));
        $query = MediaOrphanQueue::order('id', 'asc')->limit($limit);
        if ($folder !== '') {
            $prefix = 'uploads/' . trim(str_replace('\\', '/', $folder), '/') . '/';
            $query->whereLike('path', $prefix . '%');
        }

        $rows = $query->field('path,kind')->select()->toArray();
        if (!is_array($rows)) {
            return [];
        }

        $paths = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = (string) ($row['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $rowKind  = (string) ($row['kind'] ?? '');
            $pathKind = $rowKind !== '' && $rowKind !== 'auto'
                ? $rowKind
                : $this->mediaLibraryPathService->kindFromPathPublic($path);
            if ($kind === 'software' && $pathKind !== 'software') {
                continue;
            }
            if ($kind === 'image' && $pathKind === 'software') {
                continue;
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    public function countPending(string $kind = 'image', string $folder = ''): int
    {
        return count($this->pathsForPurge($kind, $folder, self::MAX_PURGE_BATCH));
    }

    public function pruneStale(int $olderThanDays = 90): int
    {
        if (!$this->isEnabled() || $olderThanDays < 1) {
            return 0;
        }
        $cutoff = AppTime::format('Y-m-d H:i:s', time() - $olderThanDays * 86400);

        try {
            return (int) MediaOrphanQueue::where('created_at', '<', $cutoff)
                ->delete();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('media_orphan_queue_prune_failed', ['msg' => $e->getMessage()]);

            return 0;
        }
    }
}
