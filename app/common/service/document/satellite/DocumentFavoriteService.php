<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;

use app\common\service\favorite\FavoriteService;

/** 文档列表/排序 ↔ 点赞收藏内核 */
class DocumentFavoriteService
{

    public function __construct(
        private readonly FavoriteService $favorites,
    ) {
    }

    public function enabled(): bool
    {
        return $this->favorites->isActive();
    }

    /**
     * @return array{like_count:int,collect_count:int}
     */
    public function stats(int $documentId): array
    {
        if (!$this->enabled() || $documentId < 1) {
            return ['like_count' => 0, 'collect_count' => 0];
        }
        $s = $this->favorites->stats($documentId);

        return [
            'like_count'    => (int) ($s['like_count'] ?? 0),
            'collect_count' => (int) ($s['collect_count'] ?? 0),
        ];
    }

    /**
     * @param list<int> $documentIds
     * @return array<int, array{like_count:int,collect_count:int}>
     */
    public function statsForDocuments(array $documentIds): array
    {
        if (!$this->enabled()) {
            return [];
        }

        return $this->favorites->statsForDocuments($documentIds);
    }
}
