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
use app\common\support\OpsLog;
use app\common\support\QueryLimit;
use app\common\support\DbTable;
use app\common\model\ProductParamDef;
use app\common\model\ItemAttrValue;

use app\common\model\Item;

use app\common\service\content\ContentSearchService;

use app\common\service\config\ConfigService;

use app\common\service\item\ItemService;

use think\facade\Db;

/** 品项独立 Meilisearch 索引 */

final class MeilisearchItemIndex
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
        private readonly ContentSearchService $contentSearch,
        private readonly SearchItemRecordBuilder $itemRecords,
        private readonly ConfigService $config,
        private readonly SmartSearchConfigService $smartSearch,
    ) {
    }

    public function isAvailable(): bool

    {

        if ($this->searchConfig->driver() !== SearchConfigService::DRIVER_MEILI) {

            return false;

        }

        $cfg = $this->searchConfig->meiliConfig();

        return $cfg['host'] !== '' && $this->client()->health();

    }

    /**

     * @param array<string, mixed> $context

     * @return array<string, string> param_key => value

     */

    public function extractAttrFilters(array $context): array

    {

        $out = [];

        foreach ($context as $k => $v) {

            $key = (string) $k;

            if (!str_starts_with($key, 'filter_')) {

                continue;

            }

            $attrKey = substr($key, 7);

            $val     = trim((string) $v);

            if ($attrKey === '' || $val === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $attrKey)) {

                continue;

            }

            $out[strtolower($attrKey)] = $val;

        }

        ksort($out);

        return $out;

    }

    /**

     * @param array<string, string> $attrFilters

     * @return array{ids:list<int>,total:int}

     */

    public function searchIds(string $keyword, int $limit = QueryLimit::MEILI_SEARCH_IDS, array $attrFilters = []): array

    {

        $keyword = $this->contentSearch->normalizeKeyword($keyword);

        $limit   = max(1, min($attrFilters !== [] ? 2000 : 500, $limit));

        if (!$this->isAvailable()) {

            return ['ids' => [], 'total' => 0];

        }

        if ($keyword === '' && $attrFilters === []) {

            return ['ids' => [], 'total' => 0];

        }

        $filter = $this->buildFilterExpression($attrFilters);

        $cfg = $this->searchConfig->meiliConfig();

        $res = $this->client()->search(

            $this->indexUid(),

            $keyword !== '' ? $keyword : '*',

            0,

            $limit,

            $filter,

            ['name', 'code', 'search_text'],

        );

        $ids = [];

        foreach ($res['hits'] as $hit) {

            $id = (int) ($hit['id'] ?? 0);

            if ($id > 0) {

                $ids[] = $id;

            }

        }

        return [

            'ids'   => $ids,

            'total' => max((int) $res['estimatedTotal'], count($ids)),

        ];

    }

    /** @param array<string, string> $attrFilters */

    private function buildFilterExpression(array $attrFilters): string

    {

        $parts = ['status = ' . $this->quoteFilterValue('active')];

        foreach ($attrFilters as $key => $val) {

            $field = $this->attrFieldName($key);

            if ($field === '') {

                continue;

            }

            $parts[] = $field . ' = ' . $this->quoteFilterValue($val);

        }

        return implode(' AND ', $parts);

    }

    private function quoteFilterValue(string $val): string

    {

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $val) . '"';

    }

    private function attrFieldName(string $paramKey): string

    {

        $paramKey = strtolower(trim($paramKey));

        return preg_match('/^[a-z0-9_]+$/', $paramKey) ? 'attr_' . $paramKey : '';

    }

    /** @param array<string, mixed> $record */

    public function upsert(array $record): void

    {

        if (!$this->isAvailable()) {

            return;

        }

        $uid = $this->indexUid();

        $client = $this->client();

        $client->ensureIndex($uid, 'id');

        $this->patchSettings($uid);

        $client->addDocuments($uid, [$record]);

    }

    public function delete(int $itemId): void

    {

        if ($itemId < 1 || !$this->isAvailable()) {

            return;

        }

        $this->client()->deleteDocuments($this->indexUid(), [$itemId]);

    }

    /**
     * @return ServiceResult
     */
    public function reindexAll(): ServiceResult
    {
        if (!$this->isAvailable()) {
            return ServiceResult::fail('Meilisearch 不可用或未启用 meili 驱动');
        }

        $uid    = $this->indexUid();

        $client = $this->client();

        $client->ensureIndex($uid, 'id');

        $this->patchSettings($uid);

        $client->deleteAllDocuments($uid);

        $count  = 0;

        $lastId = 0;

        while (true) {

            $rows = Item::where('status', ItemService::STATUS_ACTIVE)

                ->where('id', '>', $lastId)

                ->order('id', 'asc')

                ->limit(QueryLimit::MEILI_REINDEX_BATCH)

                ->select()

                ->toArray();

            if ($rows === []) {

                break;

            }

            $batch = [];

            foreach ($rows as $row) {

                $rec = $this->itemRecords->fromRow($row);

                if ($rec !== null) {

                    $batch[] = $rec;

                }

                $lastId = (int) ($row['id'] ?? $lastId);

            }

            if ($batch !== []) {

                $client->addDocuments($uid, $batch);

                $count += count($batch);

            }

        }

        return $count > 0
            ? ServiceResult::ok(['count' => $count], '品项索引 ' . $count . ' 条')
            : ServiceResult::fail('无可索引在售品项');
    }

    public function indexUid(): string

    {

        $idx = trim((string) $this->config->get(

            'search_meili_items_index',

            $this->smartSearch->defaultFor('search_meili_items_index'),

        ));

        return $idx !== '' ? $idx : 'items';

    }

    private function patchSettings(string $uid): void

    {

        $filterable = ['status', 'nav_id'];

        foreach ($this->filterableAttrKeys() as $key) {

            $filterable[] = $this->attrFieldName($key);

        }

        $this->client()->patchCustomSettings($uid, [

            'searchableAttributes' => ['name', 'code', 'search_text', 'slug'],

            'filterableAttributes' => array_values(array_unique($filterable)),

        ]);

    }

    /** @return list<string> */

    private function filterableAttrKeys(): array

    {

        static $cachedKeys = null;

        if ($cachedKeys !== null) {

            return $cachedKeys;

        }

        $list = [];

        if (DbTable::modelExists(ProductParamDef::class)) {
            $rows = ProductParamDef::where('filterable', 1)->column('param_key');
            foreach ($rows as $row) {
                $k = strtolower(trim((string) $row));
                if ($k !== '' && preg_match('/^[a-z0-9_]+$/', $k)) {
                    $list[] = $k;
                }
            }
        }

        if ($list === []) {

            try {

                $rows = ItemAttrValue::distinct(true)->limit(QueryLimit::ITEM_PARAM_KEYS)->column('param_key');

                foreach ($rows as $row) {

                    $k = strtolower(trim((string) $row));

                    if ($k !== '' && preg_match('/^[a-z0-9_]+$/', $k)) {

                        $list[] = $k;

                    }

                }

            } catch (\Throwable $e) { OpsLog::businessWarning('meilisearch_item_index_optional_failed', ['msg' => $e->getMessage()]); }

        }

        $cachedKeys = array_values(array_unique($list));

        return $cachedKeys;

    }

    private function client(): MeilisearchHttpClient

    {

        $cfg = $this->searchConfig->meiliConfig();

        return new MeilisearchHttpClient($cfg['host'], $cfg['key'], $cfg['timeout']);

    }

}

