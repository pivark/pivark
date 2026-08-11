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

use app\common\model\Item;
use app\common\support\OpsLog;

/** 品项全文索引同步（Meilisearch items 索引） */
final class ItemSearchIndexService
{

    public function __construct(
        private readonly MeilisearchItemIndex $meiliItems,
        private readonly SearchItemRecordBuilder $itemRecords,
    ) {
    }

    public function driverAvailable(): bool
    {
        return $this->meiliItems->isAvailable();
    }

    public function syncById(int $id): void
    {
        if ($id < 1 || !$this->meiliItems->isAvailable()) {
            return;
        }
        $row = $this->itemRow($id);
        if ($row === null) {
            $this->remove($id);

            return;
        }
        $record = $this->itemRecords->fromRow($row);
        if ($record === null) {
            $this->remove($id);

            return;
        }
        try {
            $this->meiliItems->upsert($record);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('item search index upsert failed id=' . $id . ' ' . $e->getMessage());
        }
    }

    public function remove(int $id): void
    {
        if ($id < 1) {
            return;
        }
        try {
            $this->meiliItems->delete($id);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('item search index delete failed id=' . $id . ' ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, string> $attrFilters param_key => attr_value
     * @return array{ids:list<int>,total:int}
     */
    public function searchIds(string $keyword, int $limit = 24, array $attrFilters = []): array
    {
        return $this->meiliItems->searchIds($keyword, $limit, $attrFilters);
    }

    /**
     * @return ServiceResult
     */
    public function reindexAll(): ServiceResult
    {
        return $this->meiliItems->reindexAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function itemRow(int $id): ?array
    {
        $found = Item::where('id', $id)->find();
        if ($found instanceof Item) {
            return $found->toArray();
        }

        return is_array($found) ? $found : null;
    }
}
