<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\tag;

use app\common\model\DocumentTag;
use app\common\service\infra\MetaSqlCacheService;
use app\common\service\search\SearchConfigService;

final class TagDocumentQuery
{

    /** @var array<string, list<int>> */
    private static array $requestCache = [];

    /**
     * 同时匹配全部 slug（AND）的已发布文档 ID 列表
     *
     * @param list<string> $tagSlugs
     * @return list<int>
     */
    public function publishedDocumentIdsForSlugs(array $tagSlugs): array
    {
        $tagSlugs = array_values(array_filter(array_map(
            static fn (string $s): string => trim($s),
            $tagSlugs
        )));
        if ($tagSlugs === []) {
            return [];
        }
        sort($tagSlugs);
        $blocked = app(SearchConfigService::class)->blockedTagIds();
        sort($blocked);
        $cacheKey = hash('sha256', implode("\n", $tagSlugs) . '|b:' . implode(',', $blocked));

        if (isset(self::$requestCache[$cacheKey])) {
            return self::$requestCache[$cacheKey];
        }

        if (app(TagDocumentLinkIndexService::class)->isReady()) {
            $tagIds = app(TagSlugIndexService::class)->idsForSlugs($tagSlugs);
            $ids    = $tagIds !== [] && count($tagIds) >= count($tagSlugs)
                ? app(TagDocumentLinkIndexService::class)->documentIdsMatchingAllTags($tagIds)
                : [];
        } else {
            $ids = app(MetaSqlCacheService::class)->remember(
                'doc_ids_tags_' . $cacheKey,
                static function () use ($tagSlugs): array {
                    $tagIds = app(TagSlugIndexService::class)->idsForSlugs($tagSlugs);
                    $need   = count($tagSlugs);
                    if ($tagIds === [] || count($tagIds) < $need) {
                        return [];
                    }

                    $rows = DocumentTag::whereIn('tag_id', $tagIds)
                        ->field('document_id,tag_id')
                        ->select()
                        ->toArray();
                    if ($rows === []) {
                        return [];
                    }

                    $perDoc = [];
                    foreach ($rows as $row) {
                        $docId = (int) ($row['document_id'] ?? 0);
                        $tagId = (int) ($row['tag_id'] ?? 0);
                        if ($docId < 1 || $tagId < 1) {
                            continue;
                        }
                        $perDoc[$docId][$tagId] = true;
                    }

                    $out = [];
                    foreach ($perDoc as $docId => $tags) {
                        if (count($tags) >= $need) {
                            $out[] = $docId;
                        }
                    }

                    return $out;
                }
            );
        }

        self::$requestCache[$cacheKey] = $ids;

        return $ids;
    }

    public function forgetRequestCache(): void
    {
        self::$requestCache = [];
        app(TagDocumentLinkIndexService::class)->reset();
    }
}
