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
use app\common\service\weapp\WeappStaticGateway;
use app\common\service\weapp\WeappItemGateway;
use app\common\model\ItemAttrValue;

/** 品项领域事件（manifest subscribes / EventBus） */
final class ProductEventService
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function onItemUpdated(array $payload): void
    {
        if (!ProductCenterGateService::publicSurfaceOpen()) {
            return;
        }
        $slug = trim((string) ($payload['slug'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ''));
        if ($slug === '') {
            return;
        }
        app(WeappStaticGateway::class)->staticDispatchAfterProductItemChange(
            $slug,
            $status === ItemService::STATUS_ACTIVE ? $status : '',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function onItemDeleted(array $payload): void
    {
        $itemId = (int) ($payload['item_id'] ?? 0);
        if ($itemId > 0 && class_exists(ProductItemRelationService::class)) {
            ProductItemRelationService::purgeForItemId($itemId);
        }
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug !== '') {
            app(WeappStaticGateway::class)->staticDispatchAfterProductItemChange($slug, '');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function onItemStatusChanged(array $payload): void
    {
        $itemId = (int) ($payload['item_id'] ?? 0);
        if ($itemId < 1 || !app(WeappItemGateway::class)->itemFilterFacetServiceAvailable()) {
            return;
        }
        $keys = ItemAttrValue::where('item_id', $itemId)->column('param_key');
        if ($keys === []) {
            return;
        }
        app(WeappItemGateway::class)->itemFilterFacetRebuildForParamKeys(array_values(array_unique(array_map('strval', $keys))));
    }
}
