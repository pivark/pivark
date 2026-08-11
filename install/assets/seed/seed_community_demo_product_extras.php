<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * Community 演示：产品中心扩展（多规格 · 商城 SKU · 相关文档 · 可售标记）
 * 用法: php install/assets/seed/seed_community_demo_product_extras.php [--force]
 */
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    require dirname(__DIR__, 3) . '/app/bootstrap/cli.php';
    pivark_app();
}

use app\common\model\Item;
use app\common\model\ItemVariant;
use weapp\shop\model\WeappShopSku;
use weapp\shop\model\WeappShopSpecDef;
use app\common\service\item\ItemService;
use app\common\service\item\ItemVariantService;
use app\common\service\plugin\PluginService;
use app\common\service\product\DocumentRelatedRefService;
use app\common\support\AppTime;
use app\common\support\DbTable;
use think\facade\Db;
use weapp\shop\service\ShopSkuService;

$seedExit = static function (int $code): void {
    if (\defined('PIVARK_INSTALL_SEED_INCLUDE') && \constant('PIVARK_INSTALL_SEED_INCLUDE')) {
        if ($code !== 0) {
            throw new \RuntimeException('seed_community_demo_product_extras exit ' . $code);
        }

        return;
    }
    exit($code);
};

$force = in_array('--force', $argv ?? [], true);

echo "=== seed_community_demo_product_extras ===\n";

$dataCandidates = [
    __DIR__ . '/demo_product_extras_data.php',
    __DIR__ . '/data/demo_product_extras.php',
];
$dataFile = null;
foreach ($dataCandidates as $candidate) {
    if (is_file($candidate)) {
        $dataFile = $candidate;
        break;
    }
}
if ($dataFile === null) {
    echo "FAIL missing demo_product_extras data file\n";
    $seedExit(1);
    return;
}

/** @var array<string, mixed> $cfg */
$cfg = require $dataFile;
if (!is_array($cfg) || $cfg === []) {
    echo "FAIL demo_product_extras empty\n";
    $seedExit(1);
    return;
}

$itemIdByCode = static function (string $code): int {
    $code = trim($code);
    if ($code === '') {
        return 0;
    }

    return (int) Db::name('items')->where('code', $code)->value('id');
};

$docIdByHtml = static function (string $html): int {
    $html = trim($html);
    if ($html === '') {
        return 0;
    }

    return (int) Db::name('documents')->where('html_name', $html)->value('id');
};

// ── 可售标记 ─────────────────────────────────────────────
$sellable = is_array($cfg['sellable_codes'] ?? null) ? $cfg['sellable_codes'] : [];
$sellableOk = 0;
$itemService = app(ItemService::class);
foreach ($sellable as $code) {
    $code = trim((string) $code);
    if ($code === '') {
        continue;
    }
    $row = Item::where('code', $code)->find();
    if ($row === null) {
        echo "  WARN sellable item missing: {$code}\n";
        continue;
    }
    $rowArr = is_array($row) ? $row : $row->toArray();
    $flags  = $rowArr['flags'] ?? [];
    if (is_string($flags)) {
        $decoded = json_decode($flags, true);
        $flags   = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($flags)) {
        $flags = [];
    }
    if (!empty($flags['sellable'])) {
        $sellableOk++;
        continue;
    }
    $flags['sellable']     = 1;
    $flags['web_visible']  = !empty($flags['web_visible']) ? 1 : 1;
    $payload               = $rowArr;
    $payload['id']         = (int) ($rowArr['id'] ?? 0);
    $payload['flag_sellable'] = 1;
    $payload['attrs']      = is_array($rowArr['attrs'] ?? null) ? $rowArr['attrs'] : [];
    if (is_string($payload['attrs'])) {
        $decodedAttrs = json_decode($payload['attrs'], true);
        $payload['attrs'] = is_array($decodedAttrs) ? $decodedAttrs : [];
    }
    $result = $itemService->saveAdmin($payload);
    if ($result->isOk()) {
        $sellableOk++;
    } else {
        echo "  WARN sellable {$code}: " . $result->message() . "\n";
    }
}
echo "  sellable flags={$sellableOk}\n";

// ── 前台可见（产品目录 / 详情页）────────────────────────────
$visibleOk = 0;
$itemsDataFile = __DIR__ . '/data/demo_product_items.php';
if (is_file($itemsDataFile)) {
    /** @var list<array<string, mixed>> $itemRows */
    $itemRows = require $itemsDataFile;
    foreach ($itemRows as $itemRow) {
        if (!is_array($itemRow)) {
            continue;
        }
        $code = trim((string) ($itemRow['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $row = Item::where('code', $code)->find();
        if ($row === null) {
            continue;
        }
        $rowArr = is_array($row) ? $row : $row->toArray();
        $flags  = $rowArr['flags'] ?? [];
        if (is_string($flags)) {
            $decoded = json_decode($flags, true);
            $flags   = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($flags)) {
            $flags = [];
        }
        if (!empty($flags['web_visible'])) {
            $visibleOk++;
            continue;
        }
        $payload                  = $rowArr;
        $payload['id']            = (int) ($rowArr['id'] ?? 0);
        $payload['flag_web_visible'] = 1;
        $payload['flag_sellable'] = !empty($flags['sellable']) ? 1 : 0;
        $payload['attrs']         = is_array($rowArr['attrs'] ?? null) ? $rowArr['attrs'] : [];
        if (is_string($payload['attrs'])) {
            $decodedAttrs = json_decode($payload['attrs'], true);
            $payload['attrs'] = is_array($decodedAttrs) ? $decodedAttrs : [];
        }
        $result = $itemService->saveAdmin($payload);
        if ($result->isOk()) {
            $visibleOk++;
        }
    }
}
echo "  web_visible flags={$visibleOk}\n";

// ── 相关文档（产品 Tab 第 6 步）──────────────────────────
$relatedOk = 0;
if (DocumentRelatedRefService::isAvailable()) {
    $relatedRows = is_array($cfg['related_docs'] ?? null) ? $cfg['related_docs'] : [];
    foreach ($relatedRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $productHtml = trim((string) ($row['product_html'] ?? ''));
        $productId   = $docIdByHtml($productHtml);
        if ($productId < 1) {
            echo "  WARN related product doc missing: {$productHtml}\n";
            continue;
        }
        $relatedIds = [];
        foreach (is_array($row['related_html'] ?? null) ? $row['related_html'] : [] as $relHtml) {
            $relId = $docIdByHtml((string) $relHtml);
            if ($relId > 0) {
                $relatedIds[] = $relId;
            }
        }
        if ($relatedIds === [] && !$force) {
            continue;
        }
        $res = DocumentRelatedRefService::syncForDocument($productId, $relatedIds);
        if ($res->isOk()) {
            $relatedOk++;
        } else {
            echo '  WARN related ' . $productHtml . ': ' . $res->message() . "\n";
        }
    }
    echo "  document_related_refs={$relatedOk}\n";
} else {
    echo "  SKIP document_related_refs table missing\n";
}

// ── 多规格 Variant ───────────────────────────────────────
$variantService = app(ItemVariantService::class);
$variantOk      = 0;
$variantRows    = is_array($cfg['variants'] ?? null) ? $cfg['variants'] : [];
foreach ($variantRows as $group) {
    if (!is_array($group)) {
        continue;
    }
    $itemCode = trim((string) ($group['item_code'] ?? ''));
    $itemId   = $itemIdByCode($itemCode);
    if ($itemId < 1) {
        echo "  WARN variant item missing: {$itemCode}\n";
        continue;
    }
    $extras = is_array($group['extra'] ?? null) ? $group['extra'] : [];
    if ($force && $extras !== []) {
        ItemVariant::where('item_id', $itemId)->delete();
    }
    foreach ($extras as $extra) {
        if (!is_array($extra)) {
            continue;
        }
        $variantCode = trim((string) ($extra['variant_code'] ?? ''));
        if ($variantCode === '') {
            continue;
        }
        $existing = ItemVariant::where('variant_code', $variantCode)->find();
        if ($existing !== null && (int) ($existing['item_id'] ?? 0) === $itemId && !$force) {
            $variantOk++;
            continue;
        }
        if ($existing !== null && (int) ($existing['item_id'] ?? 0) !== $itemId) {
            echo "  WARN variant code conflict: {$variantCode}\n";
            continue;
        }
        $payload = [
            'id'            => $existing !== null ? (int) ($existing['id'] ?? 0) : 0,
            'item_id'       => $itemId,
            'variant_code'  => $variantCode,
            'spec_label'    => (string) ($extra['spec_label'] ?? $variantCode),
            'spec_map'      => is_array($extra['spec_map'] ?? null) ? $extra['spec_map'] : null,
            'status'        => ItemVariantService::STATUS_ACTIVE,
            'sort'          => (int) ($extra['sort'] ?? 0),
            'is_default'    => !empty($extra['is_default']),
        ];
        $res = $variantService->saveAdmin($payload);
        if ($res->isOk()) {
            $variantOk++;
        } else {
            echo "  WARN variant {$variantCode}: " . $res->message() . "\n";
        }
    }
}
echo "  variants upserted={$variantOk}\n";

// ── 商城规格轴 + SKU ─────────────────────────────────────
$shopOk = 0;
PluginService::registerAutoloadPublic('shop');
if (!DbTable::exists('weapp_shop_skus')) {
    echo "  SKIP weapp_shop_skus table missing (install shop plugin first)\n";
} else {
    $shopGroups = is_array($cfg['shop'] ?? null) ? $cfg['shop'] : [];
    $now        = AppTime::now();
    foreach ($shopGroups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $itemCode = trim((string) ($group['item_code'] ?? ''));
        $itemId   = $itemIdByCode($itemCode);
        if ($itemId < 1) {
            echo "  WARN shop item missing: {$itemCode}\n";
            continue;
        }

        $specDefs = is_array($group['spec_defs'] ?? null) ? $group['spec_defs'] : [];
        if ($specDefs !== [] && DbTable::exists('weapp_shop_spec_defs')) {
            $existingSpecs = (int) WeappShopSpecDef::where('item_id', $itemId)->count();
            if ($existingSpecs === 0 || $force) {
                if ($force && $existingSpecs > 0) {
                    WeappShopSpecDef::where('item_id', $itemId)->delete();
                }
                foreach ($specDefs as $def) {
                    if (!is_array($def)) {
                        continue;
                    }
                    $name = trim((string) ($def['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $opts = is_array($def['options'] ?? null) ? array_values($def['options']) : [];
                    WeappShopSpecDef::insert([
                        'item_id'    => $itemId,
                        'name'       => mb_substr($name, 0, 64),
                        'options'    => json_encode($opts, JSON_UNESCAPED_UNICODE),
                        'sort'       => (int) ($def['sort'] ?? 0),
                        'created_at' => $now,
                    ]);
                }
            }
        }

        $skus = is_array($group['skus'] ?? null) ? $group['skus'] : [];
        foreach ($skus as $sku) {
            if (!is_array($sku)) {
                continue;
            }
            $skuCode = trim((string) ($sku['sku_code'] ?? ''));
            if ($skuCode === '') {
                continue;
            }
            $existingSku = WeappShopSku::where('sku_code', $skuCode)->find();
            if ($existingSku !== null && (int) ($existingSku['item_id'] ?? 0) !== $itemId) {
                echo "  WARN sku code conflict: {$skuCode}\n";
                continue;
            }
            if ($existingSku !== null && !$force) {
                $shopOk++;
                continue;
            }
            $payload = [
                'id'         => $existingSku !== null ? (int) ($existingSku['id'] ?? 0) : 0,
                'item_id'    => $itemId,
                'sku_code'   => $skuCode,
                'spec_label' => (string) ($sku['spec_label'] ?? $skuCode),
                'spec_map'   => is_array($sku['spec_map'] ?? null) ? $sku['spec_map'] : null,
                'price'      => (float) ($sku['price'] ?? 0),
                'stock'      => (int) ($sku['stock'] ?? -1),
                'status'     => !empty($sku['status']) || !isset($sku['status']) ? 1 : 0,
                'sort'       => (int) ($sku['sort'] ?? 0),
            ];
            $res = ShopSkuService::saveAdmin($payload);
            if ($res->isOk()) {
                $shopOk++;
            } else {
                echo "  WARN sku {$skuCode}: " . $res->message() . "\n";
            }
        }
    }
    echo "  shop skus upserted={$shopOk}\n";
}

// ── 虚拟分类归属（仅官网 platform 宿主；产线只读 config） ─────
if (
    class_exists(\app\common\service\product\ProductConfigService::class)
    && \app\common\service\product\ProductConfigService::officialProductCenterAdminEnabled()
) {
    $catalogOk = 0;
    foreach (Item::field('id,code,item_type,attrs')->select()->toArray() as $itemRow) {
        if (!is_array($itemRow)) {
            continue;
        }
        $itemId = (int) ($itemRow['id'] ?? 0);
        if ($itemId < 1) {
            continue;
        }
        $type = trim((string) ($itemRow['item_type'] ?? ''));
        $lineKey = match ($type) {
            ItemService::TYPE_PHYSICAL, ItemService::TYPE_KIT, ItemService::TYPE_COMPONENT => 'goods',
            default => 'other',
        };
        $attrs = $itemRow['attrs'] ?? [];
        if (is_string($attrs)) {
            $decoded = json_decode($attrs, true);
            $attrs   = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($attrs)) {
            $attrs = [];
        }
        $existing = is_array($attrs['official_catalog'] ?? null) ? $attrs['official_catalog'] : [];
        if ((string) ($existing['line'] ?? '') === $lineKey) {
            $catalogOk++;
            continue;
        }
        $attrs['official_catalog'] = [
            'line' => $lineKey,
        ];
        Item::where('id', $itemId)->update(['attrs' => json_encode($attrs, JSON_UNESCAPED_UNICODE)]);
        $catalogOk++;
    }
    echo "  official_catalog lines={$catalogOk}\n";
}

// ── 产品参数组扩展（第二组：安装与质保） ─────────────────
// 表结构由 install/setup/schema.sql + migrate_product_l1_schema_v1 保证，禁止此处 DDL
if (DbTable::exists('product_param_groups') && DbTable::exists('product_param_defs')) {
    $now = AppTime::now();
    $extraGroups = [
        [
            'group_key'   => 'install_warranty',
            'label'       => '安装与质保',
            'description' => '交付与售后相关参数，可在文档产品 Tab 与前台筛选展示',
            'sort'        => 20,
            'params'      => [
                [
                    'param_key'  => 'install_mode',
                    'label'      => '安装方式',
                    'input_type' => 'select',
                    'filterable' => 1,
                    'sort'       => 10,
                    'options'    => ['现场安装', '远程指导', '免安装（软件授权）', '—'],
                ],
                [
                    'param_key'  => 'warranty_years',
                    'label'      => '质保年限',
                    'input_type' => 'select',
                    'filterable' => 1,
                    'sort'       => 20,
                    'options'    => ['1 年', '2 年', '3 年', '5 年', '—'],
                ],
            ],
        ],
    ];
    $groupOk = 0;
    $defOk   = 0;
    foreach ($extraGroups as $group) {
        $groupKey = trim((string) ($group['group_key'] ?? ''));
        if ($groupKey === '') {
            continue;
        }
        $groupId = (int) Db::name('product_param_groups')->where('group_key', $groupKey)->value('id');
        if ($groupId < 1) {
            Db::name('product_param_groups')->insert([
                'group_key'   => $groupKey,
                'label'       => (string) ($group['label'] ?? $groupKey),
                'description' => (string) ($group['description'] ?? ''),
                'status'      => 1,
                'sort'        => (int) ($group['sort'] ?? 0),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $groupId = (int) Db::name('product_param_groups')->where('group_key', $groupKey)->value('id');
            $groupOk++;
        }
        if ($groupId < 1) {
            continue;
        }
        foreach ((array) ($group['params'] ?? []) as $def) {
            if (!is_array($def)) {
                continue;
            }
            $paramKey = trim((string) ($def['param_key'] ?? ''));
            if ($paramKey === '') {
                continue;
            }
            $exists = (int) Db::name('product_param_defs')->where('param_key', $paramKey)->count();
            if ($exists > 0 && !$force) {
                continue;
            }
            $payload = [
                'group_id'      => $groupId,
                'param_key'     => $paramKey,
                'label'         => (string) ($def['label'] ?? $paramKey),
                'input_type'    => (string) ($def['input_type'] ?? 'text'),
                'options_json'  => json_encode(is_array($def['options'] ?? null) ? $def['options'] : [], JSON_UNESCAPED_UNICODE),
                'filterable'    => !empty($def['filterable']) ? 1 : 0,
                'default_value' => '',
                'sort'          => (int) ($def['sort'] ?? 0),
                'updated_at'    => $now,
            ];
            if ($exists > 0) {
                Db::name('product_param_defs')->where('param_key', $paramKey)->update($payload);
            } else {
                Db::name('product_param_defs')->insert($payload + ['created_at' => $now]);
            }
            $defOk++;
        }
    }
    echo "  param_groups+={$groupOk} param_defs+={$defOk}\n";

    $defaultGroupId = (int) Db::name('product_param_groups')->where('group_key', 'default')->value('id');
    if ($defaultGroupId < 1) {
        $defaultGroupId = (int) Db::name('product_param_groups')->order('id', 'asc')->value('id');
    }
    $installGroupId = (int) Db::name('product_param_groups')->where('group_key', 'install_warranty')->value('id');
    $attrsPatch = [
        'HY-810' => ['install_mode' => '现场安装', 'warranty_years' => '2 年'],
        'HY-820' => ['install_mode' => '现场安装', 'warranty_years' => '2 年'],
        'SVC-EMS' => ['install_mode' => '远程指导', 'warranty_years' => '1 年'],
        'DEMO-SKU-001' => ['install_mode' => '免安装（软件授权）', 'warranty_years' => '1 年'],
    ];
    $attrOk = 0;
    foreach ($attrsPatch as $code => $patch) {
        $row = Item::where('code', $code)->find();
        if ($row === null) {
            continue;
        }
        $rowArr = is_array($row) ? $row : $row->toArray();
        $attrs  = $rowArr['attrs'] ?? [];
        if (is_string($attrs)) {
            $decoded = json_decode($attrs, true);
            $attrs   = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($attrs)) {
            $attrs = [];
        }
        foreach ($patch as $k => $v) {
            $attrs[$k] = $v;
        }
        Item::where('id', (int) ($rowArr['id'] ?? 0))->update([
            'attrs' => json_encode($attrs, JSON_UNESCAPED_UNICODE),
        ]);
        $attrOk++;
    }
    echo "  item param attrs patched={$attrOk}\n";
}

echo "=== seed_community_demo_product_extras done ===\n";
