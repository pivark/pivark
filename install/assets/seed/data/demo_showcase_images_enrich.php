<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 加灌文档配图映射（仅 has_image 文档会用到）
 */
declare(strict_types=1);

$base = '/uploads/demo-seed';

$map = [];

foreach (range(13, 36) as $i) {
    $slug = 'pv-demo-news-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    $file = str_pad((string) ((($i - 1) % 12) + 1), 2, '0', STR_PAD_LEFT) . '.jpg';
    $map[$slug] = $base . '/news/' . $file;
}

foreach (range(11, 24) as $i) {
    $slug = 'pv-demo-gallery-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    // 优先产品实拍，其次案例图
    $prod = str_pad((string) ((($i - 1) % 14) + 1), 2, '0', STR_PAD_LEFT);
    $map[$slug] = $base . '/product/' . $prod . '.jpg';
}

foreach (range(1, 36) as $i) {
    $slug = 'pv-demo-download-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $prod = str_pad((string) ((($i - 1) % 14) + 1), 2, '0', STR_PAD_LEFT);
    $map[$slug] = $base . '/product/' . $prod . '.jpg';
}

foreach (range(1, 14) as $i) {
    $slug = 'pv-demo-video-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $prod = str_pad((string) ((($i - 1) % 14) + 1), 2, '0', STR_PAD_LEFT);
    $map[$slug] = $base . '/product/' . $prod . '.jpg';
}

return ['map' => $map];
