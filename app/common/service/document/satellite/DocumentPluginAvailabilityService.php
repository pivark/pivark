<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;

use app\common\service\item\ItemRelationPublicService;
use app\common\service\item\ItemService;
use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\plugin\registry\PluginExtensionRegistry;
use app\common\service\product\DocumentProductFacade;
use app\common\service\product\OfferBridgeFacade;

/** 文档详情页 · 各 weapp 插件区块是否有前台可展示数据（供模板 {pv:if} 条件渲染） */
final class DocumentPluginAvailabilityService
{

    public function __construct(
        private readonly DocumentPluginGateDeps $gate,
    ) {
    }

    private function itemService(): ItemService
    {
        return app(ItemService::class);
    }

    /**
     * @return array<string, int|string>
     */
    public function templateVars(int $documentId, string $contentHtml = '', string $summary = ''): array
    {
        $flags = $this->flags($documentId);
        $highlight = $this->extractSidebarHighlight($contentHtml, $summary);
        $nav = 0;
        foreach ($flags as $key => $val) {
            if (!str_starts_with((string) $key, 'document_has_')) {
                continue;
            }
            if (in_array($key, ['document_has_product_related', 'document_has_related'], true)) {
                continue;
            }
            if ((int) $val === 1) {
                $nav = 1;
                break;
            }
        }

        return array_merge($flags, $highlight, [
            'document_has_plugin_nav' => $nav,
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function flags(int $documentId): array
    {
        if ($documentId < 1) {
            return $this->emptyFlags();
        }

        $out = [
            'document_has_product'             => $this->hasProduct($documentId) ? 1 : 0,
            'document_has_product_related'     => $this->hasProductRelated($documentId) ? 1 : 0,
            'document_has_product_accessories' => $this->hasProductAccessories($documentId) ? 1 : 0,
            'document_has_offer'               => $this->hasOffer($documentId) ? 1 : 0,
            'document_has_related'             => $this->hasRelatedDocuments($documentId) ? 1 : 0,
        ];
        foreach (app(PluginExtensionRegistry::class)->documentAvailabilitySlots() as $slot) {
            $out['document_has_' . $slot] = DocumentAddonBridgeAccess::hasDocumentAvailability($documentId, $slot) ? 1 : 0;
        }

        return $out;
    }

    /**
     * @return array{document_sidebar_highlight_title: string, document_sidebar_highlight_text: string}
     */
    public function extractSidebarHighlight(string $contentHtml, string $summary = ''): array
    {
        $title = '';
        $text  = '';
        if ($contentHtml !== ''
            && preg_match('/<h3[^>]*>(.*?)<\/h3>\s*(?:<p[^>]*>(.*?)<\/p>)?/is', $contentHtml, $m)) {
            $title = trim(html_entity_decode(strip_tags((string) ($m[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text  = trim(html_entity_decode(strip_tags((string) ($m[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($text === '' && $summary !== '') {
            $text = mb_strlen($summary) > 120 ? mb_substr($summary, 0, 120) . '…' : $summary;
        }

        return [
            'document_sidebar_highlight_title' => $title,
            'document_sidebar_highlight_text'  => $text,
        ];
    }

    /** @return array<string, int> */
    private function emptyFlags(): array
    {
        $out = [
            'document_has_product'             => 0,
            'document_has_product_related'     => 0,
            'document_has_product_accessories' => 0,
            'document_has_offer'               => 0,
            'document_has_related'             => 0,
        ];
        foreach (app(PluginExtensionRegistry::class)->documentAvailabilitySlots() as $slot) {
            $out['document_has_' . $slot] = 0;
        }

        return $out;
    }

    private function hasProduct(int $documentId): bool
    {
        return app(DocumentProductFacade::class)->itemIdsForDocument($documentId) !== [];
    }

    private function hasProductRelated(int $documentId): bool
    {
        $itemIds = app(DocumentProductFacade::class)->itemIdsForDocument($documentId);
        if ($itemIds === []) {
            return false;
        }

        return $this->itemService()->listRelatedPublic((int) $itemIds[0], 1) !== [];
    }

    private function hasProductAccessories(int $documentId): bool
    {
        return app(ItemRelationPublicService::class)->hasForDocument($documentId);
    }

    private function hasOffer(int $documentId): bool
    {
        return app(OfferBridgeFacade::class)->hasOffersForDocument($documentId);
    }

    private function hasRelatedDocuments(int $documentId): bool
    {
        return app(\app\common\service\document\DocumentPublicService::class)
            ->getRelatedPublic($documentId, 1) !== [];
    }
}
