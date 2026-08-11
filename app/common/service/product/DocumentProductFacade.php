<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\item\ItemService;
use app\common\support\ServiceResult;

/** 文档编辑器 / 前台读侧的产品中心门面 */
final class DocumentProductFacade
{
    private function itemService(): ItemService
    {
        return app(ItemService::class);
    }

    public function enabled(): bool
    {
        return ProductCenterGateService::publicSurfaceOpen();
    }

    public function publicApiOpen(): bool
    {
        return $this->enabled();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function enrichPublicRead(array $row): array
    {
        return ProductPublicApiService::enrichItemRead($row);
    }

    /** @return list<int> */
    public function itemIdsForDocument(int $documentId): array
    {
        return $this->itemService()->itemIdsForDocument($documentId);
    }

    /** @return list<array<string, mixed>> */
    public function listParamGroups(): array
    {
        if (!ProductCenterGateService::entitled()) {
            return [];
        }

        return ProductService::listParamGroupsWithDefs();
    }

    /** @return list<int> */
    public function paramGroupIdsForDocument(int $documentId): array
    {
        if (!ProductCenterGateService::entitled()) {
            return [];
        }

        return ProductService::paramGroupIdsForDocument($documentId);
    }

    /** @param list<int> $groupIds */
    public function syncDocumentParamGroupRefs(int $documentId, array $groupIds): void
    {
        if (!ProductCenterGateService::entitled()) {
            return;
        }
        ProductService::syncDocumentParamGroupRefs($documentId, $groupIds);
    }

    public function layoutModeForDocument(int $documentId): string
    {
        if (!ProductCenterGateService::entitled()) {
            return 'single';
        }

        return ProductService::layoutModeForDocument($documentId);
    }

    public function syncDocumentProductSettings(int $documentId, string $layoutMode, string $accessorySectionLabel = ''): void
    {
        if (!ProductCenterGateService::entitled()) {
            return;
        }
        ProductService::syncDocumentProductSettings(
            $documentId,
            $layoutMode,
            $accessorySectionLabel,
        );
    }

    /** @return list<array<string, mixed>> */
    public function listParamDefs(): array
    {
        if (!ProductCenterGateService::entitled()) {
            return [];
        }

        return ProductService::listParamDefs();
    }

    /**
     * @return array<string, mixed>
     */
    public function documentEditorSpaPayload(int $documentId): array
    {
        if (!ProductCenterGateService::entitled()) {
            return [];
        }

        return [
            'param_groups'           => $this->listParamGroups(),
            'param_defs'             => $this->listParamDefs(),
            'param_group_id'         => $documentId > 0
                ? (int) ($this->paramGroupIdsForDocument($documentId)[0] ?? 0)
                : 0,
            'param_group_ids'        => $documentId > 0
                ? array_slice($this->paramGroupIdsForDocument($documentId), 0, 1)
                : [],
            'layout_mode'            => $documentId > 0
                ? $this->layoutModeForDocument($documentId)
                : 'single',
            'item_type_labels'       => $this->itemService()->typeLabels(),
            'item_type_descriptions' => $this->itemService()->typeDescriptions(),
        ];
    }

    /**
     * 关展示面时返回原始行（不做 enrich）；开则走 enrichPublicRead。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function enrichItemReadIfOpen(array $row): array
    {
        if (!$this->publicApiOpen()) {
            return $row;
        }

        return $this->enrichPublicRead($row);
    }

    public function compareGate(): ?ServiceResult
    {
        return ProductCenterGateService::requirePublicApi();
    }

    /**
     * @param list<int> $ids
     * @return array{param_defs:list<array<string,mixed>>,compare_rows:list<array<string,mixed>>,items:list<array<string,mixed>>}
     */
    public function compareItems(array $ids): array
    {
        return ProductPublicApiService::compareItems($ids);
    }
}
