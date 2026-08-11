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

final class TagDocumentLinkIndexService
{

    /** @var array<int, array<int, true>>|null document_id => [tag_id => true] */
    private static ?array $docTagSets = null;

    /** @var list<int>|null */
    private static ?array $loadedTagIds = null;

    public function reset(): void
    {
        self::$docTagSets    = null;
        self::$loadedTagIds  = null;
    }

    public function isReady(): bool
    {
        return self::$docTagSets !== null;
    }

    /**
     * @param list<int> $tagIds
     */
    public function preloadForTagIds(array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), static fn (int $id): bool => $id > 0)));
        if ($tagIds === []) {
            self::$docTagSets   = [];
            self::$loadedTagIds = [];

            return;
        }

        if (self::$docTagSets !== null && self::$loadedTagIds !== null) {
            $missing = array_values(array_diff($tagIds, self::$loadedTagIds));
            if ($missing === []) {
                return;
            }
            $tagIds = array_values(array_unique(array_merge(self::$loadedTagIds, $tagIds)));
        } else {
            self::$docTagSets = [];
        }

        $rows = DocumentTag::whereIn('tag_id', $tagIds)
            ->field('document_id,tag_id')
            ->select()
            ->toArray();

        foreach ($rows as $link) {
            $docId = (int) ($link['document_id'] ?? 0);
            $tagId = (int) ($link['tag_id'] ?? 0);
            if ($docId > 0 && $tagId > 0) {
                self::$docTagSets[$docId][$tagId] = true;
            }
        }

        self::$loadedTagIds = $tagIds;
    }

    /**
     * @return array<int, array<int, true>>
     */
    public function docTagSets(): array
    {
        return self::$docTagSets ?? [];
    }

    /**
     * AND：文档须同时关联全部 tag_id
     *
     * @param list<int> $requiredTagIds
     * @return list<int>
     */
    /**
     * 按文档 ID 补全关联（列表卡片需展示文档全部标签）
     *
     * @param list<int> $documentIds
     */
    public function preloadForDocumentIds(array $documentIds): void
    {
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn (int $id): bool => $id > 0)));
        if ($documentIds === []) {
            return;
        }

        if (self::$docTagSets === null) {
            self::$docTagSets = [];
        }

        $need = [];
        foreach ($documentIds as $docId) {
            if (!isset(self::$docTagSets[$docId])) {
                $need[] = $docId;
            }
        }
        if ($need === []) {
            return;
        }

        $rows = DocumentTag::whereIn('document_id', $need)
            ->field('document_id,tag_id')
            ->select()
            ->toArray();

        foreach ($rows as $link) {
            $docId = (int) ($link['document_id'] ?? 0);
            $tagId = (int) ($link['tag_id'] ?? 0);
            if ($docId > 0 && $tagId > 0) {
                self::$docTagSets[$docId][$tagId] = true;
            }
        }

        foreach ($need as $docId) {
            if (!isset(self::$docTagSets[$docId])) {
                self::$docTagSets[$docId] = [];
            }
        }
    }

    public function documentIdsMatchingAllTags(array $requiredTagIds): array
    {
        $requiredTagIds = array_values(array_unique(array_filter(array_map('intval', $requiredTagIds), static fn (int $id): bool => $id > 0)));
        if ($requiredTagIds === [] || !$this->isReady()) {
            return [];
        }

        $need = count($requiredTagIds);
        $out  = [];
        foreach (self::$docTagSets ?? [] as $docId => $tags) {
            $hit = 0;
            foreach ($requiredTagIds as $tid) {
                if (isset($tags[$tid])) {
                    $hit++;
                }
            }
            if ($hit >= $need) {
                $out[] = (int) $docId;
            }
        }

        return $out;
    }

}
