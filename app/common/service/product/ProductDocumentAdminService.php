<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\weapp\WeappItemGateway;
use app\common\support\ServiceResult;

use app\common\service\item\ItemPublicGateway;
use app\common\service\item\ItemService;
use app\common\model\DocumentItemRef;
use app\common\model\Item;

/** 后台：按文档反查关联品项 */
final class ProductDocumentAdminService
{
    /**
     * @return ServiceResult
     */
    public static function itemsForDocument(int $documentId): ServiceResult
    {
        if (!ProductCenterGateService::entitled()) {
            return ServiceResult::fail('产品中心未授权');
        }
        if ($documentId < 1) {
            return ServiceResult::fail('document_id 无效');
        }
        $ids = ProductService::itemIdsForDocument($documentId);
        $defs = ProductService::listParamDefsForDocument($documentId);
        $list = [];
        foreach ($ids as $itemId) {
            $row = Item::where('id', $itemId)->find();
            if (!$row) {
                continue;
            }
            $rowArr = is_array($row) ? $row : $row->toArray();
            $slug = (string) ($rowArr['slug'] ?? '');
            $public = $slug !== '' ? app(ItemPublicGateway::class)->findPublicBySlug($slug) : null;
            $ref = DocumentItemRef::where(['document_id' => $documentId, 'item_id' => $itemId])
                ->find();
            $attrs = is_array($rowArr['attrs'] ?? null) ? $rowArr['attrs'] : [];
            $list[] = [
                'id'                  => $itemId,
                'code'                => (string) ($rowArr['code'] ?? ''),
                'name'                => (string) ($rowArr['name'] ?? ''),
                'summary'             => (string) ($rowArr['summary'] ?? ''),
                'status'              => (string) ($rowArr['status'] ?? ''),
                'item_type'           => (string) ($rowArr['item_type'] ?? ''),
                'item_type_text'      => app(ItemService::class)->typeLabels()[(string) ($rowArr['item_type'] ?? '')] ?? '',
                'role'                => (string) ($ref['role'] ?? 'related'),
                'sort'                => (int) ($ref['sort'] ?? 0),
                'primary_document_id' => (int) ($rowArr['primary_document_id'] ?? 0),
                'cover_url'           => trim((string) ($public['litpic'] ?? '')),
                'page_url'            => $public['page_url'] ?? '',
                'attrs'               => $attrs,
                'attrs_summary_text'  => ProductService::attrsSummaryText($attrs, $defs),
                'is_primary'          => (int) ($rowArr['primary_document_id'] ?? 0) === $documentId ? 1 : 0,
            ];
        }

        $primaryRow = app(ItemService::class)->primaryItemRowForDocument($documentId);
        $primaryItem = null;
        if (is_array($primaryRow)) {
            $primaryId = (int) ($primaryRow['id'] ?? 0);
            foreach ($list as $entry) {
                if ((int) ($entry['id'] ?? 0) === $primaryId) {
                    $primaryItem = $entry;
                    break;
                }
            }
            if ($primaryItem === null && $primaryId > 0) {
                $attrs = is_array($primaryRow['attrs'] ?? null) ? $primaryRow['attrs'] : [];
                $slug  = (string) ($primaryRow['slug'] ?? '');
                $public = $slug !== '' ? app(ItemPublicGateway::class)->findPublicBySlug($slug) : null;
                $primaryItem = [
                    'id'                  => $primaryId,
                    'code'                => (string) ($primaryRow['code'] ?? ''),
                    'name'                => (string) ($primaryRow['name'] ?? ''),
                    'summary'             => (string) ($primaryRow['summary'] ?? ''),
                    'status'              => (string) ($primaryRow['status'] ?? ''),
                    'item_type'           => (string) ($primaryRow['item_type'] ?? ''),
                    'item_type_text'      => app(ItemService::class)->typeLabels()[(string) ($primaryRow['item_type'] ?? '')] ?? '',
                    'role'                => 'primary',
                    'sort'                => 0,
                    'primary_document_id' => (int) ($primaryRow['primary_document_id'] ?? 0),
                    'cover_url'           => trim((string) ($public['litpic'] ?? '')),
                    'page_url'            => $public['page_url'] ?? '',
                    'attrs'               => $attrs,
                    'attrs_summary_text'  => ProductService::attrsSummaryText($attrs, $defs),
                    'is_primary'          => 1,
                ];
            }
        }

        $layoutMode = ProductService::layoutModeForDocument($documentId);

        return ServiceResult::ok([
            'list'                    => $list,
            'primary_item'            => $primaryItem,
            'layout_mode'             => $layoutMode,
            'accessory_section_label' => ProductService::accessorySectionLabelForDocument($documentId),
            'accessory_items'         => self::accessoryRowsForDocument($documentId, $primaryItem),
            'related_documents'       => class_exists(DocumentRelatedRefService::class)
                ? DocumentRelatedRefService::listAdminForDocument($documentId)
                : [],
        ]);
    }

    /**
     * @param array<string, mixed>|null $primaryItem
     * @return list<array<string, mixed>>
     */
    private static function accessoryRowsForDocument(int $documentId, ?array $primaryItem): array
    {
        if (!class_exists(ProductItemRelationService::class) || !ProductItemRelationService::isAvailable()) {
            return [];
        }
        $parentId = (int) ($primaryItem['id'] ?? 0);
        if ($parentId < 1) {
            $primaryRow = app(ItemService::class)->primaryItemRowForDocument($documentId);
            $parentId   = is_array($primaryRow) ? (int) ($primaryRow['id'] ?? 0) : 0;
        }
        if ($parentId < 1) {
            return [];
        }
        $result = ProductItemRelationService::listForParentAdmin($parentId);
        if (!$result->isOk()) {
            return [];
        }
        $data = $result->dataArray();
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['list']) && is_array($data['list'])) {
            return $data['list'];
        }

        return array_is_list($data) ? $data : [];
    }

    /**
     * @return ServiceResult
     */
    public static function documentsForItem(int $itemId): ServiceResult
    {
        if (!ProductCenterGateService::entitled()) {
            return ServiceResult::fail('产品中心未授权');
        }
        if ($itemId < 1) {
            return ServiceResult::fail('item_id 无效');
        }

        $list = ProductPublicApiService::documentRefsForItem($itemId);
        return ServiceResult::list($list, count($list));
    }
}
