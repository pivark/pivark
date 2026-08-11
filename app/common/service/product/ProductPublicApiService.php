<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\weapp\WeappDocumentGateway;
use app\common\service\item\ItemPublicGateway;
use app\common\service\item\ItemPublicViewService;
use app\common\model\Document;
use app\common\model\DocumentItemRef;
use app\common\model\Item;

/** 前台 / 开放 API：品项详情增强、对比、统一列表 */
final class ProductPublicApiService
{
    /**
     * 公开读增强入口（含 ItemPublicView 基础 enrich；关展示面时仅基础 enrich）。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function enrichItemRead(array $row): array
    {
        $row = app(ItemPublicViewService::class)->enrichRow($row, true);
        if (!ProductCenterGateService::publicSurfaceOpen()) {
            return $row;
        }

        return self::enrichRead($row);
    }

    /**
     * @param array<string, mixed> $row findPublicBySlug 结果（已基础 enrich）
     * @return array<string, mixed>
     */
    public static function enrichRead(array $row): array
    {
        $itemId = (int) ($row['id'] ?? 0);
        if ($itemId < 1) {
            return $row;
        }
        $row['document_refs'] = self::documentRefsForItem($itemId);
        $row['documents']     = self::documentsForItem($itemId);
        $row['accessory_items'] = class_exists(ProductItemRelationService::class) && ProductItemRelationService::isAvailable()
            ? ProductItemRelationService::listPublicForParent($itemId, 24)
            : [];
        $row['related_items'] = app(ItemPublicGateway::class)->listRelatedPublic($itemId, 8);
        $row['flags_display'] = self::flagsDisplay(is_array($row['flags'] ?? null) ? $row['flags'] : []);

        $cover = trim((string) ($row['cover_url'] ?? ''));
        if ($cover !== '') {
            $row['cover_url'] = ProductCoverService::resolveUrl($cover);
        }

        return $row;
    }

    /**
     * @param list<int> $ids
     * @return array{param_defs:list<array<string,mixed>>,items:list<array<string,mixed>>}
     */
    public static function compareItems(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $ids = array_slice($ids, 0, 12);
        $items = app(ItemPublicGateway::class)->listPublicByIds($ids);
        $defs  = ProductService::listParamDefs();
        $rows  = [];
        foreach ($defs as $def) {
            $key = (string) ($def['param_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $values = [];
            foreach ($items as $item) {
                $attrs = is_array($item['attrs'] ?? null) ? $item['attrs'] : [];
                $values[(string) ($item['id'] ?? 0)] = trim((string) ($attrs[$key] ?? ''));
            }
            $rows[] = [
                'param_key' => $key,
                'label'     => (string) ($def['label'] ?? $key),
                'values'    => $values,
            ];
        }

        return ['param_defs' => $defs, 'compare_rows' => $rows, 'items' => $items];
    }

    /** @return list<array{document_id:int,role:string,sort:int,title:string,url:string}> */
    public static function documentRefsForItem(int $itemId): array
    {
        if ($itemId < 1) {
            return [];
        }
        $refs = DocumentItemRef::where('item_id', $itemId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $out = [];
        foreach ($refs as $ref) {
            $docId = (int) ($ref['document_id'] ?? 0);
            if ($docId < 1) {
                continue;
            }
            $docRow = self::publishedDocumentRow($docId);
            if ($docRow === null) {
                continue;
            }
            $out[] = [
                'document_id' => $docId,
                'role'        => (string) ($ref['role'] ?? 'related'),
                'sort'        => (int) ($ref['sort'] ?? 0),
                'title'       => (string) ($docRow['title'] ?? ''),
                'url'         => app(WeappDocumentGateway::class)->documentFormatPublicUrl($docRow),
            ];
        }

        return $out;
    }

    /** @return list<array{id:int,title:string,url:string,role:string}> */
    public static function documentsForItem(int $itemId): array
    {
        $out = [];
        foreach (self::documentRefsForItem($itemId) as $ref) {
            $out[] = [
                'id'    => (int) $ref['document_id'],
                'title' => (string) $ref['title'],
                'url'   => (string) $ref['url'],
                'role'  => (string) $ref['role'],
            ];
        }
        $primaryId = (int) Item::where('id', $itemId)->value('primary_document_id');
        if ($primaryId > 0 && !in_array($primaryId, array_column($out, 'id'), true)) {
            $docRow = self::publishedDocumentRow($primaryId);
            if ($docRow !== null) {
                array_unshift($out, [
                    'id'    => $primaryId,
                    'title' => (string) ($docRow['title'] ?? ''),
                    'url'   => app(WeappDocumentGateway::class)->documentFormatPublicUrl($docRow),
                    'role'  => 'primary',
                ]);
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function publishedDocumentRow(int $docId): ?array
    {
        if ($docId < 1) {
            return null;
        }
        $doc = Document::where('id', $docId)->where('status', 1)->find();
        if ($doc === null) {
            return null;
        }

        return $doc->toArray();
    }

    /** @param array<string, mixed> $flags */
    private static function flagsDisplay(array $flags): array
    {
        $map = [
            'sellable'       => '可售',
            'purchasable'    => '可采',
            'manufacturable' => '可制',
        ];
        $out = [];
        foreach ($map as $key => $label) {
            if (!empty($flags[$key])) {
                $out[] = ['key' => $key, 'label' => $label];
            }
        }

        return $out;
    }

}
