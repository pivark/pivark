<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\model\Item;
use app\common\model\ItemVariant;
use app\common\service\plugin\extension\PluginOfficialProduct;
use app\common\service\product\ProductTabPersistRegistry;
use app\common\support\AppTime;
use app\common\support\ServiceResult;

/**
 * 文档「产品展示」Tab 持久化（自 ItemService 拆出 · L3）。
 */
final class ItemDocumentProductPersistService
{
    public function __construct(
        private readonly ItemAttrValueService $itemAttrValueService,
        private readonly ItemVariantService $itemVariantService,
    ) {
    }

    /**
     * 文档「产品展示」Tab：类型 + 参数值；无绑定品项时按文档标题自动建主品项
     *
     * @param array<string, mixed> $postSnapshot 产品 Tab POST 快照（扩展点 dispatch，L1 不解析插件字段）
     */
    public function persistTab(
        ItemService $items,
        int $documentId,
        int $itemId,
        string $itemType,
        array $attrs,
        string $documentTitle,
        string $itemName = '',
        string $itemCode = '',
        array $postSnapshot = [],
    ): ServiceResult {
        if ($documentId < 1) {
            return ServiceResult::fail('文档无效');
        }

        $itemType = trim($itemType);
        if (!isset($items->typeLabels()[$itemType])) {
            $itemType = ItemService::TYPE_PHYSICAL;
        }

        $clean = [];
        foreach ($attrs as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $clean[$key] = is_scalar($value) ? trim((string) $value) : '';
        }

        if ($itemId < 1) {
            $existing = $items->primaryItemRowForDocument($documentId);
            if (is_array($existing)) {
                $itemId = (int) ($existing['id'] ?? 0);
            }
        }
        if ($itemId < 1) {
            $created = $items->ensurePrimaryItemForDocument($documentId, $documentTitle, $itemType);
            if (!$created->isOk()) {
                return $created;
            }
            $data   = $created->dataArray();
            $itemId = (int) ($data['id'] ?? 0);
            if ($itemId < 1) {
                return ServiceResult::fail('创建品项失败');
            }
            $items->syncDocumentRefs($documentId, [$itemId]);
        }

        $itemCode = trim($itemCode);
        $documentTitle = trim($documentTitle);
        $itemName = trim($itemName);

        // 产品 Tab 参数为扁平键值；保留宿主嵌套 attrs（官方 catalog/文档快照等）
        $prevRow = Item::where('id', $itemId)->find();
        $prevAttrs = is_object($prevRow) && is_array($prevRow['attrs'] ?? null) ? $prevRow['attrs'] : [];
        $prevArr = is_object($prevRow) ? $prevRow->toArray() : [];
        $codeForStrip = $itemCode !== '' ? $itemCode : trim((string) ($prevArr['code'] ?? ''));
        $documentTitle = ItemService::stripTrailingItemCodeFromTitle($documentTitle, $codeForStrip);
        $itemName = ItemService::stripTrailingItemCodeFromTitle($itemName, $codeForStrip);
        // 系统插件：文档标题 = 货架展示名（运营改「文档标题」必须驱动品项/市场，不能被陈旧 product_item_name 盖回）
        if ($documentTitle !== '' && self::rowIsSystemPluginCatalogItem($prevArr)) {
            $itemName = $documentTitle;
        } elseif ($itemName === '') {
            $itemName = $documentTitle;
        }
        foreach ($prevAttrs as $key => $value) {
            if (!is_string($key) || $key === '' || array_key_exists($key, $clean)) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $value;
            }
        }

        // 宿主插件文档/资料：宿主 normalize_* ops（禁内核内嵌业务规范化）
        $docsPost = $postSnapshot['official_plugin_docs'] ?? null;
        if (is_array($docsPost)) {
            $normalizedDocs = PluginOfficialProduct::dispatch(
                'normalize_official_plugin_docs_post',
                ['post' => $docsPost, 'prev_attrs' => $prevAttrs],
                null,
            );
            if (is_array($normalizedDocs)) {
                $clean['official_plugin_docs'] = $normalizedDocs;
            }
        }

        $profilePost = $postSnapshot['official_plugin_profile'] ?? null;
        if (is_array($profilePost)) {
            $normalizedProfile = PluginOfficialProduct::dispatch(
                'normalize_official_plugin_profile_post',
                ['post' => $profilePost, 'prev_attrs' => $prevAttrs],
                null,
            );
            if (is_array($normalizedProfile) && is_array($normalizedProfile['official_catalog'] ?? null)) {
                $clean['official_catalog'] = $normalizedProfile['official_catalog'];
            }
        }

        $update = [
            'item_type'  => $itemType,
            'attrs'      => $clean,
            'updated_at' => AppTime::now(),
        ];
        if ($itemName !== '') {
            $update['name'] = mb_substr($itemName, 0, 200);
        }
        // 简介已写入 attrs.official_catalog.summary（无 items.summary 列）
        if ($itemCode !== '') {
            $existingCode = Item::where('code', $itemCode)->where('id', '<>', $itemId)->count();
            if ($existingCode > 0) {
                $itemCode = mb_substr($itemCode . '-' . substr(md5((string) $itemId), 0, 4), 0, 64);
            }
            $update['code'] = $itemCode;
        }

        $before = $this->dispatchProductTabBeforeExtensions(
            $documentId,
            $itemId,
            $itemType,
            'single',
            $postSnapshot,
        );
        if ($before !== null && !$before->isOk()) {
            return ServiceResult::fail($before->message());
        }

        try {
            Item::updateById($itemId, $update);
            $this->itemAttrValueService->syncFromAttrs($itemId, $clean);
        } catch (\Throwable $e) {
            return ServiceResult::fail('产品参数保存失败：' . $e->getMessage());
        }
        $this->syncFilterFacetsAfterAttrs($itemId);

        // 文档产品 Tab 直更品项也要走 after_save（刷新 catalog / Feed），否则市场名不跟品项
        $fresh = Item::where('id', $itemId)->find();
        if (is_object($fresh)) {
            $this->dispatchItemAfterSaveExtensions(
                $itemId,
                false,
                $fresh->toArray(),
                (string) ($fresh['status'] ?? ''),
            );
        }

        $ext = $this->dispatchProductTabExtensions($documentId, $itemId, $itemType, 'single', $postSnapshot);
        if ($ext !== null && !$ext->isOk()) {
            return ServiceResult::fail($ext->message());
        }

        return ServiceResult::ok(['id' => $itemId], 'ok');
    }

    /**
     * 文档产品 Tab · 多规格：1 品项 + 多行 item_variants（spec_map 存参数值）
     *
     * @param list<array<string, mixed>> $variants
     * @param array<string, mixed> $postSnapshot
     */
    public function persistMultiSpec(
        ItemService $items,
        int $documentId,
        int $itemId,
        string $itemType,
        array $variants,
        string $documentTitle,
        array $postSnapshot = [],
    ): ServiceResult {
        if ($documentId < 1) {
            return ServiceResult::fail('文档无效');
        }

        $itemType = trim($itemType);
        if (!isset($items->typeLabels()[$itemType])) {
            $itemType = ItemService::TYPE_PHYSICAL;
        }

        if ($itemId < 1) {
            $existing = $items->primaryItemRowForDocument($documentId);
            if (is_array($existing)) {
                $itemId = (int) ($existing['id'] ?? 0);
            }
        }
        if ($itemId < 1) {
            $created = $items->ensurePrimaryItemForDocument($documentId, $documentTitle, $itemType);
            if (!$created->isOk()) {
                return $created;
            }
            $itemId = (int) ($created->dataArray()['id'] ?? 0);
            if ($itemId < 1) {
                return ServiceResult::fail('创建品项失败');
            }
            $items->syncDocumentRefs($documentId, [$itemId]);
        }

        // ITEM-SSOT：多规格也必须写回品项名（规格行与展示名无关；漏写会导致列表/市场永不跟）
        $nameChanged = $this->syncItemNameFromDocumentProductTab(
            $itemId,
            $itemType,
            $documentTitle,
            $postSnapshot,
        );

        $before = $this->dispatchProductTabBeforeExtensions(
            $documentId,
            $itemId,
            $itemType,
            'multi_spec',
            $postSnapshot !== [] ? $postSnapshot : ['product_variants' => $variants, 'product_layout_mode' => 'multi_spec'],
        );
        if ($before !== null && !$before->isOk()) {
            return ServiceResult::fail($before->message());
        }

        $rows = [];
        $itemRow  = Item::where('id', $itemId)->find()?->toArray();
        $itemCode = is_array($itemRow) ? trim((string) ($itemRow['code'] ?? '')) : '';
        $seq      = 0;
        foreach ($variants as $row) {
            if (!is_array($row)) {
                continue;
            }
            $seq++;
            $code = trim((string) ($row['variant_code'] ?? ''));
            $specLabel = trim((string) ($row['spec_label'] ?? ''));
            $specMap = $row['spec_map'] ?? [];
            if (!is_array($specMap)) {
                $specMap = [];
            }
            if ($code === '') {
                $code = $this->itemVariantService->suggestVariantCode(
                    $itemId,
                    $specLabel,
                    $seq,
                    $itemCode,
                    $specMap,
                );
            }
            if ($code === '') {
                continue;
            }
            $cleanMap = [];
            foreach ($specMap as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $cleanMap[$key] = is_scalar($value) ? trim((string) $value) : '';
            }
            $entry = [
                'id'            => (int) ($row['id'] ?? 0),
                'variant_code'  => $code,
                'spec_label'    => trim((string) ($row['spec_label'] ?? $code)),
                'spec_map'      => $cleanMap,
                'sort'          => (int) ($row['sort'] ?? 0),
                'is_default'    => !empty($row['is_default']) ? 1 : 0,
            ];
            $rows[] = $entry;
        }

        if ($rows === []) {
            if ($variants !== []) {
                return ServiceResult::fail('规格数据格式无效，请刷新页面后重试');
            }
            // 无规格变更时仍要刷资料/改名投影（勿早退丢掉 name / docs）
            $this->applyOfficialPluginDocsFromPost($itemId, $postSnapshot);
            if ($nameChanged) {
                $this->dispatchItemAfterSaveIfNeeded($itemId, $postSnapshot);
            }
            $extEmpty = $this->dispatchProductTabExtensions(
                $documentId,
                $itemId,
                $itemType,
                'multi_spec',
                $postSnapshot !== [] ? $postSnapshot : ['product_variants' => $variants, 'product_layout_mode' => 'multi_spec'],
            );
            if ($extEmpty !== null && !$extEmpty->isOk()) {
                return ServiceResult::fail($extEmpty->message());
            }

            return ServiceResult::ok(['id' => $itemId], 'ok');
        }

        $keptIds = [];
        $hasDefault = false;
        foreach ($rows as $index => $row) {
            if (!$hasDefault && $index === 0) {
                $row['is_default'] = 1;
            }
            if (!empty($row['is_default'])) {
                $hasDefault = true;
            }
            $saved = $this->itemVariantService->saveAdmin([
                'id'            => $row['id'],
                'item_id'       => $itemId,
                'variant_code'  => $row['variant_code'],
                'spec_label'    => $row['spec_label'],
                'spec_map'      => $row['spec_map'],
                'sort'          => $row['sort'],
                'is_default'    => $row['is_default'],
                'status'        => ItemVariantService::STATUS_ACTIVE,
            ]);
            if (!$saved->isOk()) {
                return $saved;
            }
            $newId = (int) ($saved->dataArray()['id'] ?? $row['id']);
            if ($newId > 0) {
                $keptIds[] = $newId;
            }
        }

        if ($this->itemVariantService->tableExists()) {
            $existing = ItemVariant::where('item_id', $itemId)->column('id');
            foreach ($existing as $vid) {
                $vid = (int) $vid;
                if ($vid > 0 && !in_array($vid, $keptIds, true)) {
                    $this->itemVariantService->deleteAdmin($vid);
                }
            }
        }

        $this->applyOfficialPluginDocsFromPost($itemId, $postSnapshot);
        if ($nameChanged) {
            $this->dispatchItemAfterSaveIfNeeded($itemId, $postSnapshot);
        }

        $ext = $this->dispatchProductTabExtensions(
            $documentId,
            $itemId,
            $itemType,
            'multi_spec',
            $postSnapshot !== [] ? $postSnapshot : ['product_variants' => $variants, 'product_layout_mode' => 'multi_spec'],
        );
        if ($ext !== null && !$ext->isOk()) {
            return ServiceResult::fail($ext->message());
        }

        return ServiceResult::ok(['id' => $itemId], 'ok');
    }

    /**
     * @param array<string, mixed> $postSnapshot
     */
    private function dispatchProductTabBeforeExtensions(
        int $documentId,
        int $itemId,
        string $itemType,
        string $layoutMode,
        array $postSnapshot,
    ): ?ServiceResult {
        if ($itemId < 1) {
            return null;
        }

        return app(ProductTabPersistRegistry::class)->dispatchBeforePersist([
            'document_id' => $documentId,
            'item_id'     => $itemId,
            'item_type'   => $itemType,
            'layout_mode' => $layoutMode,
            'post'        => $postSnapshot,
        ]);
    }

    /**
     * @param array<string, mixed> $postSnapshot
     */
    private function dispatchProductTabExtensions(
        int $documentId,
        int $itemId,
        string $itemType,
        string $layoutMode,
        array $postSnapshot,
    ): ?ServiceResult {
        if ($itemId < 1) {
            return null;
        }

        return app(ProductTabPersistRegistry::class)->dispatchAfterPersist([
            'document_id' => $documentId,
            'item_id'     => $itemId,
            'item_type'   => $itemType,
            'layout_mode' => $layoutMode,
            'post'        => $postSnapshot,
        ]);
    }

    /**
     * 多规格路径：对齐 persistTab 的命名规则，写回 items.name。
     *
     * @param array<string, mixed> $postSnapshot
     * @return bool 名称是否相对库内旧值发生变化
     */
    private function syncItemNameFromDocumentProductTab(
        int $itemId,
        string $itemType,
        string $documentTitle,
        array $postSnapshot,
    ): bool {
        if ($itemId < 1) {
            return false;
        }
        $prevArr = Item::where('id', $itemId)->find()?->toArray() ?? [];
        if ($prevArr === []) {
            return false;
        }
        $prevName = trim((string) ($prevArr['name'] ?? ''));
        $itemName = trim((string) ($postSnapshot['product_item_name'] ?? ''));
        $documentTitle = trim($documentTitle);
        $codeForStrip = trim((string) ($postSnapshot['product_item_code'] ?? $prevArr['code'] ?? ''));
        $documentTitle = ItemService::stripTrailingItemCodeFromTitle($documentTitle, $codeForStrip);
        $itemName = ItemService::stripTrailingItemCodeFromTitle($itemName, $codeForStrip);
        // 系统插件：文档标题 = 货架展示名（与单规格路径一致）
        if ($documentTitle !== '' && self::rowIsSystemPluginCatalogItem($prevArr)) {
            $itemName = $documentTitle;
        } elseif ($itemName === '') {
            $itemName = $documentTitle;
        }

        $update = [
            'item_type'  => $itemType,
            'updated_at' => AppTime::now(),
        ];
        if ($itemName !== '') {
            $update['name'] = mb_substr($itemName, 0, 200);
        }
        Item::where('id', $itemId)->update($update);

        return $itemName !== '' && $itemName !== $prevName;
    }

    /**
     * 仅改名且无官方资料 POST 时，applyOfficialPluginDocsFromPost 会早退；补一次 after_save 刷 catalog。
     *
     * @param array<string, mixed> $postSnapshot
     */
    private function dispatchItemAfterSaveIfNeeded(int $itemId, array $postSnapshot): void
    {
        if ($itemId < 1) {
            return;
        }
        $docsPost = $postSnapshot['official_plugin_docs'] ?? null;
        $profilePost = $postSnapshot['official_plugin_profile'] ?? null;
        if (is_array($docsPost) || is_array($profilePost)) {
            // applyOfficialPluginDocsFromPost 已 dispatch
            return;
        }
        $fresh = Item::where('id', $itemId)->find();
        if (!is_object($fresh)) {
            return;
        }
        $this->dispatchItemAfterSaveExtensions(
            $itemId,
            false,
            $fresh->toArray(),
            (string) ($fresh['status'] ?? ''),
        );
    }

    /** attrs/EAV 变更后重建 ItemFilterFacet（失败不回滚主写） */
    private function syncFilterFacetsAfterAttrs(int $itemId): void
    {
        if ($itemId < 1) {
            return;
        }
        try {
            app(ItemFilterFacetService::class)->syncAfterItemChange($itemId);
        } catch (\Throwable) {
            // facet 物化为派生层，主写已成功
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function dispatchItemAfterSaveExtensions(
        int $itemId,
        bool $isNew,
        array $row,
        string $prevStatus,
    ): void {
        if ($itemId < 1) {
            return;
        }
        app(ItemPersistRegistry::class)->dispatchAfterSave([
            'item_id'     => $itemId,
            'is_new'      => $isNew ? 1 : 0,
            'prev_status' => $prevStatus,
            'row'         => $row,
        ]);
    }

    /**
     * 多规格路径不改 attrs；单独回写官方插件文档快照。
     *
     * @param array<string, mixed> $postSnapshot
     */
    private function applyOfficialPluginDocsFromPost(int $itemId, array $postSnapshot): void
    {
        if ($itemId < 1) {
            return;
        }
        $docsPost = $postSnapshot['official_plugin_docs'] ?? null;
        $profilePost = $postSnapshot['official_plugin_profile'] ?? null;
        if (!is_array($docsPost) && !is_array($profilePost)) {
            return;
        }
        $row = Item::where('id', $itemId)->find();
        if (!is_object($row)) {
            return;
        }
        $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
        $update = [
            'attrs'      => $attrs,
            'updated_at' => AppTime::now(),
        ];
        if (is_array($docsPost)) {
            $normalizedDocs = PluginOfficialProduct::dispatch(
                'normalize_official_plugin_docs_post',
                ['post' => $docsPost, 'prev_attrs' => $attrs],
                null,
            );
            if (is_array($normalizedDocs)) {
                $attrs['official_plugin_docs'] = $normalizedDocs;
                $update['attrs'] = $attrs;
            }
        }
        if (is_array($profilePost)) {
            $normalizedProfile = PluginOfficialProduct::dispatch(
                'normalize_official_plugin_profile_post',
                ['post' => $profilePost, 'prev_attrs' => $attrs],
                null,
            );
            if (is_array($normalizedProfile) && is_array($normalizedProfile['official_catalog'] ?? null)) {
                $attrs['official_catalog'] = $normalizedProfile['official_catalog'];
                $update['attrs'] = $attrs;
            }
        }
        Item::updateById($itemId, $update);
        $fresh = Item::where('id', $itemId)->find();
        if (is_object($fresh)) {
            $this->dispatchItemAfterSaveExtensions(
                $itemId,
                false,
                $fresh->toArray(),
                (string) ($fresh['status'] ?? ''),
            );
        }
    }

    /**
     * 系统插件产线品项：文档标题驱动展示名（ITEM-SSOT）。
     *
     * @param array<string, mixed> $row
     */
    private static function rowIsSystemPluginCatalogItem(array $row): bool
    {
        $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
        $catalog = is_array($attrs['official_catalog'] ?? null) ? $attrs['official_catalog'] : [];
        $line = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string) ($catalog['line'] ?? '')))) ?? '';
        if ($line === 'plugin') {
            return true;
        }
        $ident = preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) ($catalog['plugin_identifier'] ?? ''))),
        ) ?? '';
        if ($ident !== '') {
            return true;
        }
        $code = strtolower(trim((string) ($row['code'] ?? '')));
        $prefix = strtolower(trim((string) PluginOfficialProduct::dispatch(
            'virtual_code_prefix',
            [],
            '',
        )));

        return $prefix !== '' && str_starts_with($code, $prefix);
    }
}
