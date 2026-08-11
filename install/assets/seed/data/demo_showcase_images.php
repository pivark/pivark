<?php
/**
 * 华仪智控演示站 · 配图路径映射（相对 URL：/uploads/demo-seed/…）
 */
declare(strict_types=1);

$base = '/uploads/demo-seed';
$map  = [];

foreach (range(1, 12) as $i) {
    $slug = 'pv-demo-news-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    $file = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $map[$slug] = "{$base}/news/{$file}.jpg";
}
foreach (range(1, 10) as $i) {
    $slug = 'pv-demo-gallery-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $file = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $map[$slug] = "{$base}/gallery/{$file}.jpg";
}
foreach (range(1, 8) as $i) {
    $slug = 'pv-demo-video-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $file = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $map[$slug] = "{$base}/video/{$file}.jpg";
}
foreach (range(1, 6) as $i) {
    $slug = 'pv-demo-download-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $file = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $map[$slug] = "{$base}/download/{$file}.jpg";
}
foreach (range(1, 14) as $i) {
    $slug = 'pv-demo-product-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $file = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $map[$slug] = "{$base}/product/{$file}.jpg";
}

$map['default'] = "{$base}/banner/list-top.jpg";

/** @return list<string> 案例图集详情多图 */
$galleryItems = static function (string $htmlName) use ($base): array {
    if (!preg_match('/^pv-demo-gallery-(\d+)$/', $htmlName, $m)) {
        return [];
    }
    $n   = (int) $m[1];
    $out = [];
    for ($i = 1; $i <= 6; $i++) {
        $out[] = "{$base}/gallery/{$n}-{$i}.jpg";
    }
    return $out;
};

/** @return list<string> 产品详情内多图（外观 / 细节 / 现场） */
$productGalleryItems = static function (string $htmlName) use ($base): array {
    if (!preg_match('/^pv-demo-product-(\d+)$/', $htmlName, $m)) {
        return [];
    }
    $file = str_pad((string) ((int) $m[1]), 2, '0', STR_PAD_LEFT);
    $out  = [];
    for ($i = 1; $i <= 3; $i++) {
        $out[] = "{$base}/product/{$file}-{$i}.jpg";
    }
    return $out;
};

return [
    'base'                  => $base,
    // 主题 logo 不在 demo-seed（SSOT 数据资产与目录规范 §6.1）
    'logo'                  => '/static/theme/default/images/logo.svg',
    'logo_hero'             => '/static/theme/default/images/logo-hero.svg',
    'carousel'              => [
        "{$base}/carousel/slide-1.jpg",
        "{$base}/carousel/slide-2.jpg",
        "{$base}/carousel/slide-3.jpg",
        "{$base}/carousel/slide-4.jpg",
    ],
    'list_top'              => "{$base}/banner/list-top.jpg",
    'map'                   => $map,
    'gallery_items'         => $galleryItems,
    'product_gallery_items' => $productGalleryItems,
    'talent_avatars'        => [
        "{$base}/talent/01.jpg",
        "{$base}/talent/02.jpg",
    ],
];
