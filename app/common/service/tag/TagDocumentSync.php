<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\tag;

use app\common\support\AppTime;

use app\common\support\ServiceResult;

use app\common\model\TagGroup as TagGroupModel;
use think\facade\Db;
use app\common\model\Document;
use app\common\model\Tag;
use app\common\model\DocumentTag;
use app\common\support\DbTable;
use app\common\support\SiteDomainContext;
use app\common\support\SiteUrl;
use think\db\Query;

/** 文档与标签关联 */
class TagDocumentSync
{

    /** @var array<int, list<array<string, mixed>>> */
    private static array $tagsForDocumentCache = [];

    /**
     * @param list<int> $articleIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function getTagsForDocuments(array $articleIds): array
    {
        $articleIds = array_values(array_unique(array_filter(array_map('intval', $articleIds))));
        if ($articleIds === []) {
            return [];
        }
        $missing = array_values(array_filter(
            $articleIds,
            static fn (int $id): bool => !array_key_exists($id, self::$tagsForDocumentCache)
        ));
        if ($missing === []) {
            $out = [];
            foreach ($articleIds as $aid) {
                $out[$aid] = self::$tagsForDocumentCache[$aid] ?? [];
            }

            return $out;
        }
        $links = DocumentTag::whereIn('document_id', $missing)->select()->toArray();
        if ($links === []) {
            foreach ($missing as $aid) {
                self::$tagsForDocumentCache[$aid] = [];
            }
            $out = [];
            foreach ($articleIds as $aid) {
                $out[$aid] = self::$tagsForDocumentCache[$aid] ?? [];
            }

            return $out;
        }
        $tagIds  = array_unique(array_column($links, 'tag_id'));
        $tagRows = Tag::whereIn('id', $tagIds)->where('status', 1)->select()->toArray();
        $tagMap  = [];
        foreach ($tagRows as $row) {
            $tagMap[(int) $row['id']] = app(TagCore::class)->formatForApi($row);
        }
        $out = array_fill_keys($articleIds, []);
        foreach ($links as $link) {
            $aid = (int) $link['document_id'];
            $tid = (int) $link['tag_id'];
            if (isset($tagMap[$tid])) {
                $out[$aid][] = $tagMap[$tid];
            }
        }
        foreach ($articleIds as $aid) {
            if (!array_key_exists($aid, self::$tagsForDocumentCache)) {
                self::$tagsForDocumentCache[$aid] = $out[$aid] ?? [];
            }
        }
        return $out;
    }

    /**
     * @param list<int> $tagIds
     * @return array<int, int>
     */
    public function countPublishedDocumentsByTagIds(array $tagIds): array
    {
        return $this->countDocumentsByTagIds($tagIds, true);
    }

    /**
     * @param list<int> $tagIds
     * @return array<int, int>
     */
    public function countDocumentsByTagIds(array $tagIds, bool $publishedOnly = false): array
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds))));
        if ($tagIds === []) {
            return [];
        }
        $query = DocumentTag::alias('at')
            ->join('documents a', 'a.id = at.document_id')
            ->whereIn('at.tag_id', $tagIds)
            ->whereNull('a.deleted_at');
        if ($publishedOnly) {
            $query->where('a.status', 1);
        }
        $rows = $query->field('at.tag_id, COUNT(*) AS cnt')
            ->group('at.tag_id')
            ->select()
            ->toArray();
        $out = array_fill_keys($tagIds, 0);
        foreach ($rows as $row) {
            $out[(int) $row['tag_id']] = (int) $row['cnt'];
        }

        return $out;
    }

public function syncDocumentTags(int $articleId, string $tagsStr): void
    {
        $tagsStr = app(TagCore::class)->normalizeTagsCsv($tagsStr);
        Db::transaction(function () use ($articleId, $tagsStr) {
            $oldTagIds = array_map('intval', DocumentTag::where('document_id', $articleId)->column('tag_id'));
            DocumentTag::where('document_id', $articleId)->delete();

            $names = app(TagCore::class)->parseTagNames($tagsStr);
            $newTagIds = [];
            $now       = AppTime::now();
            foreach ($names as $name) {
                $tagId = $this->resolveTagIdByName($name);
                if ($tagId > 0) {
                    $newTagIds[] = $tagId;
                    DocumentTag::insert([
                        'document_id' => $articleId,
                        'tag_id'     => $tagId,
                        'created_at' => $now,
                    ]);
                }
            }

            $toDec = array_diff($oldTagIds, $newTagIds);
            $toInc = array_diff($newTagIds, $oldTagIds);
            foreach ($toDec as $tagId) {
                Tag::where('id', $tagId)->where('use_count', '>', 0)->dec('use_count')->update();
            }
            foreach ($toInc as $tagId) {
                Tag::where('id', $tagId)->inc('use_count')->update();
            }
        });
        $this->forgetTagsForDocumentCache($articleId);
    }

private function resolveTagIdByName(string $name): int
    {
        $name = trim($name);
        if (!app(TagCore::class)->isValidTagName($name)) {
            return 0;
        }
        $tag = $this->tagRow(Tag::where('name', $name)->find());
        if ($tag !== null) {
            return (int) $tag['id'];
        }
        $now = AppTime::now();
        $slug = app(TagCore::class)->makeSlug($name);

        return (int) Tag::insertGetId([
            'name'       => $name,
            'slug'       => $slug,
            'url_path'   => $slug,
            'status'     => 1,
            'use_count'  => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

public function tagNamesCsvForDocument(int $documentId): string
    {
        $tags = $this->getTagsForDocument($documentId);
        if ($tags === []) {
            return '';
        }
        return implode(',', array_column($tags, 'name'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTagsForDocument(int $documentId): array
    {
        if ($documentId < 1) {
            return [];
        }
        if (array_key_exists($documentId, self::$tagsForDocumentCache)) {
            return self::$tagsForDocumentCache[$documentId];
        }
        $tagIds = $this->resolveTagIdsForDocument($documentId);
        if ($tagIds === []) {
            return self::$tagsForDocumentCache[$documentId] = [];
        }
        $rows = Tag::whereIn('id', $tagIds)->where('status', 1)->select()->toArray();
        $out  = [];
        foreach ($rows as $row) {
            $out[] = app(TagCore::class)->formatForApi($row);
        }

        return self::$tagsForDocumentCache[$documentId] = $out;
    }

    /** @return list<int> */
    private function resolveTagIdsForDocument(int $documentId): array
    {
        if (app(TagDocumentLinkIndexService::class)->isReady()) {
            $set = app(TagDocumentLinkIndexService::class)->docTagSets()[$documentId] ?? null;
            if ($set !== null) {
                return array_map('intval', array_keys($set));
            }
        }

        return array_values(array_map('intval', DocumentTag::where('document_id', $documentId)->column('tag_id') ?: []));
    }

    public function forgetTagsForDocumentCache(?int $documentId = null): void
    {
        if ($documentId === null || $documentId < 1) {
            self::$tagsForDocumentCache = [];

            return;
        }
        unset(self::$tagsForDocumentCache[$documentId]);
    }

public function detachDocumentTags(int $documentId): void
    {
        if ($documentId < 1) {
            return;
        }
        $tagIds = array_map('intval', DocumentTag::where('document_id', $documentId)->column('tag_id'));
        if ($tagIds === []) {
            return;
        }
        DocumentTag::where('document_id', $documentId)->delete();
        foreach (array_unique(array_filter($tagIds, static fn (int $id): bool => $id > 0)) as $tagId) {
            $this->reconcileUseCountInternal($tagId);
        }
        $this->forgetTagsForDocumentCache($documentId);
    }

public function reconcileUseCountInternal(int $id): void
    {
        $this->purgeOrphanDocumentTagLinksForTag($id);
        $count = $this->countLiveDocumentLinks($id);
        Tag::where('id', $id)->update([
            'use_count'  => $count,
            'updated_at' => AppTime::now(),
        ]);
    }

public function countLiveDocumentLinks(int $tagId): int
    {
        if ($tagId < 1) {
            return 0;
        }

        return (int) DocumentTag::alias('at')
            ->join('documents a', 'a.id = at.document_id')
            ->where('at.tag_id', $tagId)
            ->whereNull('a.deleted_at')
            ->count();
    }

    public function purgeAllOrphanDocumentTagLinks(): int
    {
        $docTable = DbTable::model(Document::class);
        $linkTable = DbTable::model(DocumentTag::class);
        // LEFT JOIN 删除：避免 column('id') + whereNotIn 在数万文档时炸 SQL
        $sql = "DELETE at FROM `{$linkTable}` at
            LEFT JOIN `{$docTable}` a ON a.id = at.document_id
            WHERE a.id IS NULL OR a.deleted_at IS NOT NULL";

        return (int) Db::execute($sql);
    }

public function purgeOrphanDocumentTagLinksForTag(int $tagId): int
    {
        if ($tagId < 1) {
            return 0;
        }
        $docTable = DbTable::model(Document::class);
        $linkTable = DbTable::model(DocumentTag::class);
        $sql = "DELETE at FROM `{$linkTable}` at
            LEFT JOIN `{$docTable}` a ON a.id = at.document_id
            WHERE at.tag_id = ?
              AND (a.id IS NULL OR a.deleted_at IS NOT NULL)";

        return (int) Db::execute($sql, [$tagId]);
    }

public function reconcileUseCount(int $id): ServiceResult
    {
        $row = $this->tagRow(Tag::where('id', $id)->find());
        if ($row === null) {
            return ServiceResult::fail('标签不存在');
        }
        $this->reconcileUseCountInternal($id);
        $count = $this->countLiveDocumentLinks($id);
        return ServiceResult::ok(['use_count' => $count], '已校正');
    }

    public function forgetRequestCache(): void
    {
        self::$tagsForDocumentCache = [];
        app(TagDocumentQuery::class)->forgetRequestCache();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tagRow(mixed $found): ?array
    {
        if ($found instanceof Tag) {
            return $found->toArray();
        }

        return is_array($found) ? $found : null;
    }
}
