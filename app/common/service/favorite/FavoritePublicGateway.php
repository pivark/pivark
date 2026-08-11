<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\favorite;
use app\common\support\ServiceResult;

/**
 * 点赞收藏可注入门面（Phase 2 DI 试点：v1 Favorite API）。
 */
final class FavoritePublicGateway
{

    /** @return array{like_count:int,collect_count:int,liked:bool,collected:bool} */
    public function stats(int $documentId): array
    {
        return app(FavoriteService::class)->stats($documentId);
    }

    public function toggleLike(int $documentId): ServiceResult
    {
        return app(FavoriteService::class)->toggleLike($documentId);
    }

    public function toggleCollect(int $documentId): ServiceResult
    {
        return app(FavoriteService::class)->toggleCollect($documentId);
    }
}
