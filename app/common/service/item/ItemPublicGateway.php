<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

/**
 * 品项前台读路径可注入门面（Phase 2 DI 试点：v1 Item API）。
 */
final class ItemPublicGateway
{

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function listPublic(array $params = []): array
    {
        return app(ItemService::class)->listPublic($params);
    }

    /** @return array<string, mixed>|null */
    public function findPublicBySlug(string $slug): ?array
    {
        return app(ItemService::class)->findPublicBySlug($slug);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function catalog(array $params = []): array
    {
        return app(ItemCatalogPublicService::class)->catalog($params);
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    public function listPublicByIds(array $ids): array
    {
        return app(ItemService::class)->listPublicByIds($ids);
    }

    /** @return list<array<string, mixed>> */
    public function listRelatedPublic(int $itemId, int $limit = 8): array
    {
        return app(ItemService::class)->listRelatedPublic($itemId, $limit);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function filterOptions(array $params = []): array
    {
        return app(ItemCatalogPublicService::class)->filterOptions($params);
    }
}
