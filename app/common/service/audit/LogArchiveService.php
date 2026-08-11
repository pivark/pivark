<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\audit;

use app\common\model\AuditLog;
use app\common\model\LogsArchive;
use app\common\support\AppTime;
use app\common\support\DbTable;
use think\facade\Db;

/** 操作日志归档（P3：热表瘦身，归档表可迁冷存储） */
final class LogArchiveService
{

    public function archiveTableExists(): bool
    {
        return DbTable::modelExists(LogsArchive::class);
    }

    /**
     * 将早于 $days 天的日志批量迁入 logs_archive 后从 logs 删除。
     *
     * @return array{archived:int,deleted:int}
     */
    public function archiveOlderThanDays(int $days, int $batchLimit = 5000): array
    {
        if (!$this->archiveTableExists() || $days < 7) {
            return ['archived' => 0, 'deleted' => 0];
        }
        $batchLimit = max(100, min(20000, $batchLimit));
        $since      = AppTime::format('Y-m-d H:i:s', time() - $days * 86400);

        $ids = AuditLog::where('created_at', '<', $since)
            ->order('id', 'asc')
            ->limit($batchLimit)
            ->column('id');
        $ids = array_values(array_map('intval', $ids ?: []));
        if ($ids === []) {
            return ['archived' => 0, 'deleted' => 0];
        }

        $archivedAt = AppTime::now();
        Db::startTrans();
        try {
            $records = AuditLog::whereIn('id', $ids)->select();
            $payload = [];
            foreach ($records as $row) {
                $data                = $row->toArray();
                $data['archived_at'] = $archivedAt;
                $payload[]           = $data;
            }
            if ($payload !== []) {
                (new LogsArchive())->insertAll($payload);
            }
            $deleted = (int) AuditLog::whereIn('id', $ids)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return ['archived' => count($ids), 'deleted' => $deleted];
    }
}
