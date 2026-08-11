<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);


namespace app\common\service\search;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\model\Item;

use app\common\service\item\ItemPublicUrlService;

use app\common\service\item\ItemService;

/** 品项 Meilisearch 索引记录 */

final class SearchItemRecordBuilder

{

    public function __construct(

        private readonly ItemPublicUrlService $itemPublicUrlService,

    ) {

    }

    /**

     * @return array<string, mixed>|null

     */

    public function fromId(int $id): ?array

    {

        if ($id < 1) {

            return null;

        }

        $row = $this->itemRow(Item::where('id', $id)->find());

        return $row !== null ? $this->fromRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function itemRow(mixed $found): ?array
    {
        if ($found instanceof Item) {
            return $found->toArray();
        }

        return is_array($found) ? $found : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public function fromRow(array $row): ?array

    {

        $id = (int) ($row['id'] ?? 0);

        if ($id < 1) {

            return null;

        }

        $status = (string) ($row['status'] ?? '');

        if ($status !== ItemService::STATUS_ACTIVE) {

            return null;

        }

        $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];

        $parts = [

            (string) ($row['name'] ?? ''),

            (string) ($row['code'] ?? ''),

            (string) ($row['slug'] ?? ''),

        ];

        $hostLines = \app\common\service\plugin\extension\PluginOfficialProduct::dispatch(
            'item_attrs_search_plain_lines',
            ['attrs' => $attrs],
            [],
        );
        if (is_array($hostLines)) {
            foreach ($hostLines as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $parts[] = $line;
                }
            }
        }

        foreach ($attrs as $k => $v) {
            if (is_array($v)) {
                continue;
            }

            $parts[] = (string) $k . ' ' . (string) $v;

        }

        $record = [

            'id'          => $id,

            'name'        => (string) ($row['name'] ?? ''),

            'code'        => (string) ($row['code'] ?? ''),

            'slug'        => (string) ($row['slug'] ?? ''),

            'search_text' => trim(implode(' ', array_filter($parts, static fn (string $s): bool => $s !== ''))),

            'status'      => $status,

            // 真分类挂靠
            'nav_id'      => max(0, (int) ($row['nav_id'] ?? 0)),

            'url'         => $this->itemPublicUrlService->productItemPage((string) ($row['slug'] ?? '')),

        ];

        foreach ($attrs as $k => $v) {

            if (is_array($v)) {

                continue;

            }

            $key = strtolower(trim((string) $k));

            if ($key === '' || !preg_match('/^[a-z0-9_]+$/', $key)) {

                continue;

            }

            $val = trim((string) $v);

            if ($val !== '') {

                $record['attr_' . $key] = $val;

            }

        }

        return $record;

    }

}

