<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\tag;
use app\common\support\QueryLimit;

/**
 * 标签前台读路径可注入门面（Phase 2 DI 试点：v1 Tag API）。
 */
final class TagPublicGateway
{

    /** @return array{list:list<array>,total:int,page:int,limit:int} */
    public function listPublic(int $page = 1, int $limit = QueryLimit::PUBLIC_CATALOG_LIST): array
    {
        return app(TagService::class)->listPublic($page, $limit);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return app(TagService::class)->findBySlug($slug);
    }
}
