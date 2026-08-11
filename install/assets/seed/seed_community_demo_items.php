<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * Community 演示：产品中心品项（与 pv-demo-product-* 文档一一对应）
 * 用法: php install/assets/seed/seed_community_demo_items.php
 */
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    require dirname(__DIR__, 3) . '/app/bootstrap/cli.php';
    pivark_app();
}

$seedItemsFail = static function (string $message): void {
    echo "FAIL {$message}\n";
    if (\defined('PIVARK_INSTALL_SEED_INCLUDE')) {
        throw new \RuntimeException('seed_community_demo_items: ' . $message);
    }
    exit(1);
};

use app\common\service\catalog\CatalogQueryService;
use app\common\service\item\ItemFilterFacetService;
use app\common\model\Item;
use app\common\service\item\ItemService;
use think\facade\Db;

$dataDir = __DIR__ . '/data';

$tableExists = static function (string $logic): bool {
    try {
        Db::name($logic)->limit(1)->select();

        return true;
    } catch (\Throwable) {
        return false;
    }
};

echo "=== seed_community_demo_items ===\n";

if (!$tableExists('items')) {
    echo "SKIP items table missing\n";

    return;
}

$docIdByHtml = static function (string $html): int {
    return (int) Db::name('documents')->where('html_name', $html)->value('id');
};

$docLitpic = static function (string $html): string {
    return trim((string) Db::name('documents')->where('html_name', $html)->value('litpic'));
};

$tagIdsForSlugs = static function (array $slugs): array {
    if ($slugs === []) {
        return [];
    }
    $map = Db::name('tags')->whereIn('slug', $slugs)->column('id', 'slug');
    $out = [];
    foreach ($slugs as $slug) {
        $id = (int) ($map[$slug] ?? 0);
        if ($id > 0) {
            $out[] = $id;
        }
    }

    return $out;
};

/** 品项 active 必须挂栏目：品类 Tag → 社区种子 url_path → site_nav.id */
$navIdByCatTag = static function () use ($tableExists): array {
    if (!$tableExists('site_nav')) {
        return [];
    }
    $pathByTag = [
        'pv-demo-cat-digital'  => 'chanpin-yiqi',
        'pv-demo-cat-service'  => 'chanpin-jicheng',
        'pv-demo-cat-resource' => 'chanpin-peijian',
    ];
    $out = [];
    foreach ($pathByTag as $tag => $path) {
        $navId = (int) Db::name('site_nav')->where('url_path', $path)->where('status', 1)->value('id');
        if ($navId < 1) {
            $navId = (int) Db::name('site_nav')->where('url_path', $path)->value('id');
        }
        if ($navId > 0) {
            $out[$tag] = $navId;
        }
    }

    return $out;
};
$catNavMap = $navIdByCatTag();
$resolveNavId = static function (array $tagSlugs) use ($catNavMap): int {
    foreach ($tagSlugs as $slug) {
        $slug = trim((string) $slug);
        if ($slug !== '' && isset($catNavMap[$slug])) {
            return (int) $catNavMap[$slug];
        }
    }

    return 0;
};

if ($tableExists('product_param_defs')) {
    $now = date('Y-m-d H:i:s');
    $defaultGroupId = 0;
    if ($tableExists('product_param_groups')) {
        $defaultGroupId = (int) Db::name('product_param_groups')->where('group_key', 'default')->value('id');
        if ($defaultGroupId < 1) {
            Db::name('product_param_groups')->insert([
                'group_key'   => 'default',
                'label'       => '工业参数',
                'description' => '华仪演示默认参数组（量程/信号/精度等）',
                'status'      => 1,
                'sort'        => 10,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $defaultGroupId = (int) Db::name('product_param_groups')->where('group_key', 'default')->value('id');
        }
        echo "  product_param_groups default_id={$defaultGroupId}\n";
    }

    $paramDefs = require $dataDir . '/demo_product_param_defs.php';
    foreach ($paramDefs as $def) {
        if (!is_array($def)) {
            continue;
        }
        $key = trim((string) ($def['param_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $exists = (int) Db::name('product_param_defs')->where('param_key', $key)->count();
        $options = $def['options'] ?? [];
        $payload = [
            'group_id'     => $defaultGroupId > 0 ? $defaultGroupId : 0,
            'param_key'    => $key,
            'label'        => (string) ($def['label'] ?? $key),
            'input_type'   => (string) ($def['input_type'] ?? 'text'),
            'options_json' => json_encode(is_array($options) ? $options : [], JSON_UNESCAPED_UNICODE),
            'filterable'   => !empty($def['filterable']) ? 1 : 0,
            'sort'         => (int) ($def['sort'] ?? 0),
            'updated_at'   => $now,
        ];
        if ($exists > 0) {
            if ($defaultGroupId > 0) {
                Db::name('product_param_defs')->where('param_key', $key)->where('group_id', 0)->update([
                    'group_id'   => $defaultGroupId,
                    'updated_at' => $now,
                ]);
            }
            continue;
        }
        $payload['created_at'] = $now;
        Db::name('product_param_defs')->insert($payload);
    }
    echo "  product_param_defs OK\n";

    // 华仪演示只用工业参数筛选，禁用 product 插件迁移插入的通用 color/size
    Db::name('product_param_defs')->whereIn('param_key', ['color', 'size'])->update(['filterable' => 0]);
}

$rows = require $dataDir . '/demo_product_items.php';
if (!is_array($rows) || $rows === []) {
    $seedItemsFail('demo_product_items empty');
}

$itemService = app(ItemService::class);
$ok          = 0;
$skipped     = 0;

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $html = trim((string) ($row['html'] ?? ''));
    $code = trim((string) ($row['code'] ?? ''));
    $name = trim((string) ($row['name'] ?? ''));
    if ($code === '' || $name === '') {
        $skipped++;
        continue;
    }

    $documentId = $html !== '' ? $docIdByHtml($html) : 0;
    if ($html !== '' && $documentId < 1) {
        echo "  WARN missing doc {$html} for item {$code}\n";
        $skipped++;
        continue;
    }

    $tagSlugs   = is_array($row['tags'] ?? null) ? $row['tags'] : [];
    $navId      = $resolveNavId($tagSlugs);
    if ($navId < 1) {
        echo "  FAIL item {$code}: 未解析到产品栏目 nav_id（检查品类 Tag 与 site_nav.url_path）\n";
        $skipped++;
        continue;
    }

    $existingId = (int) Db::name('items')->where('code', $code)->value('id');
    $payload    = [
        'code'                => $code,
        'name'                => $name,
        'slug'                => trim((string) ($row['slug'] ?? '')),
        'item_type'           => trim((string) ($row['item_type'] ?? ItemService::TYPE_PHYSICAL)),
        'status'              => ItemService::STATUS_ACTIVE,
        'sort'                => (int) ($row['sort'] ?? 0),
        'primary_document_id' => $documentId,
        'nav_id'              => $navId,
        'tag_ids'             => $tagIdsForSlugs($tagSlugs),
        'flag_sellable'       => !empty($row['shop']) ? 1 : 0,
        'flag_web_visible'    => 1,
        'attrs'               => is_array($row['attrs'] ?? null) ? $row['attrs'] : [],
    ];
    if ($existingId > 0) {
        $payload['id'] = $existingId;
    }

    $result = $itemService->saveAdmin($payload);
    if (!$result->isOk()) {
        echo "  FAIL item {$code}: " . $result->message() . "\n";
        continue;
    }

    $itemId = (int) ($result['id'] ?? $existingId);
    if ($itemId < 1) {
        $skipped++;
        continue;
    }

    $litpic = $html !== '' ? $docLitpic($html) : '';
    if ($litpic !== '' && $documentId > 0) {
        \app\common\model\Document::where('id', $documentId)->update([
            'litpic'     => mb_substr($litpic, 0, 512),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if (class_exists(\app\common\service\document\DocumentProductImageService::class)
            && \app\common\support\DbTable::exists('document_product_images')
        ) {
            $n = (int) \think\facade\Db::name('document_product_images')->where('document_id', $documentId)->count();
            if ($n < 1) {
                app(\app\common\service\document\DocumentProductImageService::class)
                    ->replaceForDocument($documentId, [], $litpic);
            }
        }
    }

    if ($documentId > 0) {
        $itemService->syncDocumentRefs($documentId, [$itemId]);
    }

    $ok++;
}

if (class_exists(\app\common\service\item\ItemFilterFacetService::class)) {
    app(ItemFilterFacetService::class)->rebuildForParamKeys(['product_line', 'output_signal', 'accuracy']);
    echo "  item_filter_facets OK\n";
}

echo "  items seeded={$ok} skipped={$skipped}\n";

if ($ok < 1) {
    $seedItemsFail('items seeded=0（品项须挂栏目；检查 nav_id / ItemCapabilityGate 装机上下文）');
}

if (class_exists(\app\common\service\catalog\CatalogQueryService::class)) {
    app(CatalogQueryService::class)->bumpCache('items');
    echo "  catalog cache bumped\n";
}

echo "=== seed_community_demo_items done ===\n";
