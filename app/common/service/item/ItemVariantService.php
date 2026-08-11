<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);


namespace app\common\service\item;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\support\AppTime;

use app\common\support\ServiceResult;

use app\common\model\Item;
use app\common\model\ItemVariant;
use app\common\service\product\OfferBridgeFacade;
use app\common\service\product\ProductConfigService;
use app\common\support\DbTable;
use think\facade\Db;

/** 品项规格 Variant（AD-021 · Core 可订货单元 SSOT） */
final class ItemVariantService
{

    public const STATUS_ACTIVE       = 'active';
    public const STATUS_DISCONTINUED = 'discontinued';

    public function tableExists(): bool
    {
        return DbTable::modelExists(ItemVariant::class);
    }

    /**
     * 保存品项后确保至少有一个默认规格。
     * 新建时按产品中心订货号规则生成；已有默认行不覆盖 variant_code（手改优先）。
     */
    public function ensureDefaultForItem(int $itemId, string $itemCode, string $itemName, string $itemStatus): void
    {
        if ($itemId < 1 || !$this->tableExists()) {
            return;
        }

        $itemCode = trim($itemCode);
        if ($itemCode === '') {
            return;
        }

        $specLabel   = trim($itemName) !== '' ? mb_substr(trim($itemName), 0, 255) : $itemCode;
        $variantCode = $this->suggestVariantCode($itemId, $specLabel, 1, $itemCode);
        $status      = $itemStatus === ItemService::STATUS_DISCONTINUED
            ? self::STATUS_DISCONTINUED
            : self::STATUS_ACTIVE;
        $now         = AppTime::now();

        $default = ItemVariant::where('item_id', $itemId)->where('is_default', 1)->find();
        if ($default === null) {
            ItemVariant::insert([
                'item_id'      => $itemId,
                'variant_code' => $variantCode,
                'spec_label'   => $specLabel,
                'spec_map'     => null,
                'is_default'   => 1,
                'status'       => $status,
                'sort'         => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            return;
        }

        // 已有默认订货号：同步状态；绝不回写 variant_code。
        // 展示名：仅空/等于型号占位/已等于品项名时回写；已手改或多规格业务标签（如授权时长）不覆盖。
        $defaultId     = (int) ($default['id'] ?? 0);
        $existingLabel = trim((string) ($default['spec_label'] ?? ''));
        $payload       = [
            'status'     => $status,
            'updated_at' => $now,
        ];
        if ($existingLabel === '' || $existingLabel === $itemCode || $existingLabel === $specLabel) {
            $payload['spec_label'] = $specLabel;
        }
        ItemVariant::where('id', $defaultId)->update($payload);
    }

    public function deleteForItem(int $itemId): void
    {
        if ($itemId < 1 || !$this->tableExists()) {
            return;
        }
        ItemVariant::where('item_id', $itemId)->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPublicByItemId(int $itemId, bool $activeOnly = true): array
    {
        if ($itemId < 1 || !$this->tableExists()) {
            return [];
        }
        $query = ItemVariant::where('item_id', $itemId)
            ->order('is_default', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'asc');
        if ($activeOnly) {
            $query->where('status', self::STATUS_ACTIVE);
        }
        $out = [];
        foreach ($query->select()->toArray() as $row) {
            $out[] = $this->formatPublicRow($row);
        }

        return $out;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function listPublicGroupedByItemIds(array $itemIds, bool $activeOnly = true): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        if ($itemIds === [] || !$this->tableExists()) {
            return [];
        }
        $query = ItemVariant::whereIn('item_id', $itemIds)
            ->order('is_default', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'asc');
        if ($activeOnly) {
            $query->where('status', self::STATUS_ACTIVE);
        }
        $grouped = [];
        foreach ($query->select()->toArray() as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($itemId < 1) {
                continue;
            }
            $grouped[$itemId][] = $this->formatPublicRow($row);
        }

        return $grouped;
    }

    public function countByItemId(int $itemId, bool $activeOnly = false): int
    {
        if ($itemId < 1 || !$this->tableExists()) {
            return 0;
        }
        $query = ItemVariant::where('item_id', $itemId);
        if ($activeOnly) {
            $query->where('status', self::STATUS_ACTIVE);
        }

        return (int) $query->count();
    }

    /**
     * 后台品项列表：批量规格数 + 预览标签（避免逐行 count / 展开前无提示）
     *
     * @param list<int> $itemIds
     * @return array<int, array{count:int,labels:list<string>,more:int}>
     */
    public function adminListMetaByItemIds(array $itemIds, int $maxLabels = 3): array
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($itemIds === [] || !$this->tableExists()) {
            return [];
        }
        $maxLabels = max(1, min(5, $maxLabels));
        $grouped = $this->listPublicGroupedByItemIds($itemIds, false);
        $out     = [];
        foreach ($grouped as $itemId => $variants) {
            $count = count($variants);
            if ($count < 1) {
                continue;
            }
            $labels = [];
            foreach ($variants as $variant) {
                $label = trim((string) ($variant['spec_label'] ?? ''));
                if ($label === '') {
                    $label = trim((string) ($variant['variant_code'] ?? ''));
                }
                if ($label === '') {
                    continue;
                }
                $labels[] = $label;
                if (count($labels) >= $maxLabels) {
                    break;
                }
            }
            $out[(int) $itemId] = [
                'count'  => $count,
                'labels' => $labels,
                'more'   => max(0, $count - count($labels)),
            ];
        }

        return $out;
    }

    /**
     * @return ServiceResult
     */
    public function listAdminByItem(int $itemId): ServiceResult
    {
        if ($itemId < 1) {
            return ServiceResult::fail('品项无效');
        }
        if (!Item::where('id', $itemId)->find()) {
            return ServiceResult::fail('品项不存在');
        }
        if (!$this->tableExists()) {
            return ServiceResult::fail('规格表未就绪，请先执行数据库迁移');
        }

        $rows = ItemVariant::where('item_id', $itemId)
            ->order('is_default', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if ($rows === []) {
            // 官方插件品项：首次打开编辑页时把 sku_prices 投影为多规格行
            PluginOfficialProduct::dispatch('ensure_sku_variants', ['item_id' => $itemId], null);
            $rows = ItemVariant::where('item_id', $itemId)
                ->order('is_default', 'desc')
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        }
        $offerMap = app(OfferBridgeFacade::class)->adminOverlayByVariantIds(array_map(
            static fn (array $r): int => (int) ($r['id'] ?? 0),
            $rows,
        ));
        $list = [];
        foreach ($rows as $row) {
            $formatted = $this->formatAdminRow($row);
            $vid       = (int) ($formatted['id'] ?? 0);
            if (isset($offerMap[$vid])) {
                $formatted['offer'] = $offerMap[$vid];
            } else {
                // 数字品/官方：无 shop offer 时用 spec_map.price 合成，编辑页可改
                $spec = is_array($formatted['spec_map'] ?? null) ? $formatted['spec_map'] : [];
                if (array_key_exists('price', $spec)) {
                    $formatted['offer'] = [
                        'sku_id'         => 0,
                        'price'          => round((float) $spec['price'], 2),
                        'original_price' => 0.0,
                        'stock'          => -1,
                        'status'         => 1,
                        'price_text'     => '¥' . number_format((float) $spec['price'], 2, '.', ''),
                        'stock_text'     => '不限',
                    ];
                }
            }
            $list[] = $formatted;
        }

        return ServiceResult::ok($list);
    }

    /**
     * 列表快捷改规格排序
     *
     * @return ServiceResult
     */
    public function patchSortAdmin(int $id, int $sort): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('规格无效');
        }
        if (!$this->tableExists()) {
            return ServiceResult::fail('规格表未就绪');
        }
        if ($sort < 0) {
            $sort = 0;
        }
        $row = ItemVariant::where('id', $id)->find();
        if ($row === null) {
            return ServiceResult::fail('规格不存在');
        }
        ItemVariant::where('id', $id)->update([
            'sort'       => $sort,
            'updated_at' => AppTime::now(),
        ]);
        $fresh = ItemVariant::where('id', $id)->find()?->toArray() ?? [];

        return ServiceResult::ok($this->formatAdminRow($fresh), '已更新');
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        if (!$this->tableExists()) {
            return ServiceResult::fail('规格表未就绪');
        }

        $id           = (int) ($data['id'] ?? 0);
        $itemId       = (int) ($data['item_id'] ?? 0);
        $variantCode  = trim((string) ($data['variant_code'] ?? ''));
        $specLabel    = trim((string) ($data['spec_label'] ?? ''));
        $status       = trim((string) ($data['status'] ?? self::STATUS_ACTIVE));
        $sort         = (int) ($data['sort'] ?? 0);
        $isDefault    = !empty($data['is_default']) ? 1 : 0;

        if ($itemId < 1 || !Item::where('id', $itemId)->find()) {
            return ServiceResult::fail('品项不存在');
        }
        $specMap = $data['spec_map'] ?? null;
        if (is_string($specMap) && $specMap !== '') {
            $decoded = json_decode($specMap, true);
            $specMap = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($specMap)) {
            $specMap = null;
        }
        if ($variantCode === '') {
            if ($id > 0) {
                return ServiceResult::fail('订货编码不能为空');
            }
            $seq = ItemVariant::where('item_id', $itemId)->count() + 1;
            $variantCode = $this->suggestVariantCode(
                $itemId,
                $specLabel,
                $seq,
                '',
                $specMap,
            );
        }
        if ($variantCode === '') {
            return ServiceResult::fail('订货编码不能为空');
        }
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_DISCONTINUED], true)) {
            return ServiceResult::fail('状态无效');
        }
        if ($specLabel === '') {
            $specLabel = $variantCode;
        }

        $dup = ItemVariant::where('variant_code', $variantCode);
        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }
        if ($dup->count() > 0) {
            return ServiceResult::fail('订货编码已存在');
        }

        $now     = AppTime::now();
        $payload = [
            'item_id'      => $itemId,
            'variant_code' => mb_substr($variantCode, 0, 64),
            'spec_label'   => mb_substr($specLabel, 0, 255),
            'spec_map'     => $specMap,
            'status'       => $status,
            'sort'         => $sort,
            'updated_at'   => $now,
        ];

        Db::startTrans();
        try {
            if ($id > 0) {
                $row = ItemVariant::where('id', $id)->where('item_id', $itemId)->find();
                if ($row === null) {
                    Db::rollback();

                    return ServiceResult::fail('规格不存在');
                }
                if ($isDefault) {
                    $payload['is_default'] = 1;
                }
                ItemVariant::where('id', $id)->update($payload);
                if ($isDefault) {
                    $this->clearDefaultExcept($itemId, $id);
                }
                Db::commit();
                PluginOfficialProduct::dispatch('after_variant_persist', ['item_id' => $itemId], null);

                return ServiceResult::ok(['id' => $id], '保存成功');
            }

            $hasAny = ItemVariant::where('item_id', $itemId)->count() > 0;
            $payload['is_default']   = ($isDefault || !$hasAny) ? 1 : 0;
            $payload['created_at']   = $now;
            $newId = (int) ItemVariant::insertGetId($payload);
            if ($payload['is_default'] === 1) {
                $this->clearDefaultExcept($itemId, $newId);
            }
            app(OfferBridgeFacade::class)->linkSkuByVariantCode($itemId, $newId, $variantCode);
            Db::commit();
            PluginOfficialProduct::dispatch('after_variant_persist', ['item_id' => $itemId], null);

            return ServiceResult::ok(['id' => $newId], '保存成功');
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail('保存失败：' . $e->getMessage());
        }
    }

    /** @return ServiceResult */
    public function deleteAdmin(int $id): ServiceResult
    {
        if ($id < 1 || !$this->tableExists()) {
            return ServiceResult::fail('参数无效');
        }
        $row = ItemVariant::where('id', $id)->find();
        if ($row === null) {
            return ServiceResult::fail('规格不存在');
        }
        $itemId = (int) ($row['item_id'] ?? 0);
        if (ItemVariant::where('item_id', $itemId)->count() <= 1) {
            return ServiceResult::fail('至少保留一条规格');
        }

        $wasDefault = !empty($row['is_default']);
        ItemVariant::where('id', $id)->delete();
        if ($wasDefault) {
            $next = ItemVariant::where('item_id', $itemId)->order('sort', 'asc')->order('id', 'asc')->find();
            if ($next !== null) {
                ItemVariant::where('id', (int) ($next['id'] ?? 0))->update(['is_default' => 1, 'updated_at' => AppTime::now()]);
            }
        }
        PluginOfficialProduct::dispatch('after_variant_persist', ['item_id' => $itemId], null);

        return ServiceResult::ok(null, '删除成功');
    }

    public function copyFromItem(int $fromItemId, int $toItemId, string $codePrefix): void
    {
        if ($fromItemId < 1 || $toItemId < 1 || !$this->tableExists()) {
            return;
        }
        $rows = ItemVariant::where('item_id', $fromItemId)
            ->order('is_default', 'desc')
            ->order('sort', 'asc')
            ->select()
            ->toArray();
        if ($rows === []) {
            return;
        }
        $now = AppTime::now();
        foreach ($rows as $row) {
            $baseCode = trim((string) ($row['variant_code'] ?? ''));
            $newCode  = $this->uniqueVariantCode(
                mb_substr($codePrefix . '-' . ($baseCode !== '' ? $baseCode : 'VAR'), 0, 58),
                $toItemId,
            );
            ItemVariant::insert([
                'item_id'      => $toItemId,
                'variant_code' => $newCode,
                'spec_label'   => (string) ($row['spec_label'] ?? $newCode),
                'spec_map'     => $row['spec_map'] ?? null,
                'is_default'   => !empty($row['is_default']) ? 1 : 0,
                'status'       => self::STATUS_ACTIVE,
                'sort'         => (int) ($row['sort'] ?? 0),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    /**
     * 报价插件保存 SKU 时解析或创建 Core 规格并返回 variant_id。
     */
    public function resolveIdForOfferSku(int $itemId, string $skuCode, string $specLabel, mixed $specMap): int
    {
        if ($itemId < 1 || !$this->tableExists()) {
            return 0;
        }
        $skuCode = trim($skuCode);
        if ($skuCode === '') {
            return 0;
        }
        $existing = ItemVariant::where('variant_code', $skuCode)->find();
        if ($existing !== null) {
            return (int) ($existing['id'] ?? 0);
        }
        $map = null;
        if (is_array($specMap)) {
            $map = $specMap;
        } elseif (is_string($specMap) && $specMap !== '') {
            $decoded = json_decode($specMap, true);
            $map     = is_array($decoded) ? $decoded : null;
        }
        $now = AppTime::now();
        $hasDefault = ItemVariant::where('item_id', $itemId)->where('is_default', 1)->count() > 0;

        return (int) ItemVariant::insertGetId([
            'item_id'      => $itemId,
            'variant_code' => mb_substr($skuCode, 0, 64),
            'spec_label'   => mb_substr($specLabel !== '' ? $specLabel : $skuCode, 0, 255),
            'spec_map'     => $map,
            'is_default'   => $hasDefault ? 0 : 1,
            'status'       => self::STATUS_ACTIVE,
            'sort'         => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function formatAdminRow(array $row): array
    {
        $public = $this->formatPublicRow($row);

        return array_merge($public, [
            'status_text' => $public['status'] === self::STATUS_DISCONTINUED ? '停订' : '可订',
        ]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function formatPublicRow(array $row): array
    {
        $specMap = $row['spec_map'] ?? null;
        if (is_string($specMap) && $specMap !== '') {
            $decoded = json_decode($specMap, true);
            $specMap = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($specMap)) {
            $specMap = [];
        }

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'item_id'       => (int) ($row['item_id'] ?? 0),
            'variant_code'  => (string) ($row['variant_code'] ?? ''),
            'spec_label'    => (string) ($row['spec_label'] ?? ''),
            'spec_map'      => $specMap,
            'is_default'    => !empty($row['is_default']) ? 1 : 0,
            'status'        => (string) ($row['status'] ?? self::STATUS_ACTIVE),
            'sort'          => (int) ($row['sort'] ?? 0),
        ];
    }

    private function clearDefaultExcept(int $itemId, int $keepId): void
    {
        ItemVariant::where('item_id', $itemId)->where('id', '<>', $keepId)->update([
            'is_default' => 0,
            'updated_at' => AppTime::now(),
        ]);
    }

    public function suggestVariantCode(
        int $itemId,
        string $specLabel,
        int $sequence = 1,
        string $itemCode = '',
        ?array $specMap = null,
    ): string {
        if ($itemCode === '' && $itemId > 0) {
            $row = Item::where('id', $itemId)->find()?->toArray();
            $itemCode = is_array($row) ? trim((string) ($row['code'] ?? '')) : '';
        }

        $policy = ProductConfigService::variantNamingPolicy();
        // 默认规格且规则为「等于型号」时，直接用型号（多规格留空仍走拼接）
        if (
            $policy['mode'] === ItemVariantCodePolicy::MODE_EQUALS_ITEM
            && trim($specLabel) === ''
            && ($specMap === null || $specMap === [])
            && $itemCode !== ''
        ) {
            $base = mb_substr($itemCode, 0, 64);
        } else {
            $base = ItemVariantCodePolicy::build(
                $policy,
                $itemCode,
                $specLabel,
                $sequence,
                $specMap,
            );
        }

        return $this->uniqueVariantCode($base, $itemId);
    }

    private function uniqueVariantCode(string $base, int $itemId): string
    {
        $base = trim($base) !== '' ? trim($base) : ('ITEM-' . $itemId);
        $candidate = mb_substr($base, 0, 64);
        $n         = 2;
        while (ItemVariant::where('variant_code', $candidate)->find()) {
            $suffix    = '-' . $n;
            $candidate = mb_substr($base, 0, max(1, 64 - strlen($suffix))) . $suffix;
            $n++;
        }

        return $candidate;
    }
}
