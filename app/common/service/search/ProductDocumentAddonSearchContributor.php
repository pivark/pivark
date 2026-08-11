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

use app\common\contract\DocumentAddonSearchContributorInterface;
use app\common\model\Item;
use app\common\service\product\DocumentProductFacade;
use app\common\service\item\ItemService;

/** 产品展示：关联品项 + 配件辅件 */
final class ProductDocumentAddonSearchContributor implements DocumentAddonSearchContributorInterface
{

    public function __construct(
        private readonly DocumentAddonPlainTextCollector $plainTextCollector,
        private readonly DocumentProductFacade $documentProducts,
    ) {
    }

    public function searchSections(int $documentId): array
    {
        if ($documentId < 1 || !$this->documentProducts->enabled()) {
            return [];
        }
        $ids = $this->documentProducts->itemIdsForDocument($documentId);
        if ($ids === []) {
            return [];
        }
        $lines = [];
        $rows  = Item::whereIn('id', $ids)->field('code,name,attrs')->select()->toArray();
        foreach ($rows as $row) {
            $lines[] = trim((string) ($row['code'] ?? '') . ' ' . (string) ($row['name'] ?? ''));
            $attrs = $row['attrs'] ?? null;
            if (is_string($attrs)) {
                $attrs = json_decode($attrs, true);
            }
            if (is_array($attrs)) {
                $hostLines = \app\common\service\plugin\extension\PluginOfficialProduct::dispatch(
                    'item_attrs_search_plain_lines',
                    ['attrs' => $attrs],
                    [],
                );
                if (is_array($hostLines)) {
                    foreach ($hostLines as $line) {
                        $line = trim((string) $line);
                        if ($line !== '') {
                            $lines[] = $line;
                        }
                    }
                }
                foreach ($attrs as $val) {
                    if (is_array($val)) {
                        continue;
                    }
                    $val = trim((string) $val);
                    if ($val !== '') {
                        $lines[] = $val;
                    }
                }
            }
        }
        if (class_exists(\app\common\service\product\ProductItemRelationService::class)
            && \app\common\service\product\ProductItemRelationService::isAvailable()) {
            foreach ($ids as $parentId) {
                foreach (\app\common\service\product\ProductItemRelationService::listPublicForParent((int) $parentId, 48) as $acc) {
                    if (!is_array($acc)) {
                        continue;
                    }
                    $lines[] = trim(
                        (string) ($acc['code'] ?? '') . ' '
                        . (string) ($acc['name'] ?? '') . ' '
                        . (string) ($acc['relation_type_text'] ?? '')
                        . ' ' . (string) ($acc['relation_note'] ?? ''),
                    );
                }
            }
        }
        $lines = $this->plainTextCollector->linesFromPayload($lines, 2);
        if ($lines === []) {
            return [];
        }

        return [['label' => '关联产品', 'text' => implode("\n", $lines)]];
    }

    public function searchAttachments(int $documentId): array
    {
        unset($documentId);

        return [];
    }
}
