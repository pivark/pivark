<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\tag;
use app\common\service\tag\TagCore;
use app\common\support\ServiceResult;
use app\common\support\QueryLimit;

use app\common\model\TagGroup as TagGroupModel;
use think\facade\Db;
use app\common\model\Document;
use app\common\model\Tag;
use app\common\model\DocumentTag;
use app\common\support\DbTable;
use app\common\support\SiteDomainContext;
use app\common\support\SiteUrl;
use think\db\Query;

/** 标签（TAG）实现 */

/**
 * 标签（TAG）门面：委托 tag/* 子模块。
 * @see tag/README.md
 */
class TagService
{

    public function __construct(
        private readonly TagCore $tagCore,
        private readonly TagPublicService $tagPublicService,
        private readonly TagTreeHelper $tagTreeHelper,
        private readonly TagDocumentSync $tagDocumentSync,
    ) {
    }

    public const KIND_TOPIC = 'topic';
    public const KIND_LABEL = 'label';

    /** 栏目树最大层级（含顶级） */
    public const MAX_PARENT_DEPTH = 12;

    /**
     * @return array<string, string>
     */

    public function kindLabels(): array
    {
        return $this->tagCore->kindLabels();
    }

    public function normalizeKind(mixed $raw): string
    {
        return $this->tagCore->normalizeKind($raw);
    }

    public function listTagTemplates(): array
    {
        return $this->tagCore->listTagTemplates();
    }

    public function publicTemplateBasename(string $tplFile): string
    {
        return $this->tagCore->publicTemplateBasename($tplFile);
    }

    public function buildPublicListViewVars(array $row): array
    {
        return $this->tagCore->buildPublicListViewVars($row);
    }

    /**
     * 文档详情面包屑 / 频道头图：优先 topic 标签，否则取首个标签
     *
     * @param array<string, mixed> $detail 含 tags
     * @return array<string, mixed>|null
     */
    public function primaryTagFromDocument(array $detail): ?array
    {
        return $this->primaryTagFromTagList((array) ($detail['tags'] ?? []));
    }

    /**
     * @param list<mixed> $tags
     * @return array<string, mixed>|null
     */
    public function primaryTagFromTagList(array $tags): ?array
    {
        $primary = null;
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            if ($this->normalizeKind((string) ($tag['kind'] ?? '')) === self::KIND_TOPIC) {
                $primary = $tag;
                break;
            }
        }
        if ($primary === null && $tags !== []) {
            $primary = is_array($tags[0]) ? $tags[0] : null;
        }

        return $primary;
    }

    /** 委托 {@see TagCore::makeSlug()} — slug 生成 SSOT 在 TagCore + {@see SlugHelper} */
    public function makeSlug(string $name, int $excludeId = 0): string
    {
        return $this->tagCore->makeSlug($name, $excludeId);
    }

    public function slugExists(string $slug, int $excludeId = 0): bool
    {
        return $this->tagCore->slugExists($slug, $excludeId);
    }

    /**
     * 后台品项 meta：启用标签下拉
     *
     * @return list<array<string, mixed>>
     */
    public function listActivePicker(int $limit = 500): array
    {
        $limit = max(1, min($limit, 500));

        return array_values(Tag::where('status', 1)
            ->order('id', 'desc')
            ->field('id,name,slug')
            ->limit($limit)
            ->select()
            ->toArray());
    }

    public function listPublic(int $page = 1, int $limit = QueryLimit::PUBLIC_CATALOG_LIST): array
    {
        return $this->tagPublicService->listPublic($page, $limit);
    }

    /** @param array<string, mixed> $params */
    public function listPublicQuery(array $params): array
    {
        return $this->tagPublicService->listPublicQuery($params);
    }

    public function findRowBySlug(string $slug): ?array
    {
        return $this->tagPublicService->findRowBySlug($slug);
    }

    public function findRowByUrlPath(string $path): ?array
    {
        return $this->tagPublicService->findRowByUrlPath($path);
    }

    public function findRowById(int $id): ?array
    {
        return $this->tagPublicService->findRowById($id);
    }

    public function findRowByName(string $name): ?array
    {
        return $this->tagPublicService->findRowByName($name);
    }

    public function publicPath(array $row): string
    {
        return $this->tagPublicService->publicPath($row);
    }

    public function templateUrlVars(): array
    {
        return $this->tagPublicService->templateUrlVars();
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->tagPublicService->findBySlug($slug);
    }

    public function findByName(string $name): ?array
    {
        return $this->tagPublicService->findByName($name);
    }

    public function listNav(int $limit = QueryLimit::NAV_TAGS): array
    {
        return $this->tagPublicService->listNav($limit);
    }

    /**
     * @param list<array<string, mixed>> $navRows
     * @return list<array<string, mixed>>
     */
    public function expandNavRowsWithDescendants(array $navRows, int $maxDepth = 4): array
    {
        return $this->tagPublicService->expandNavRowsWithDescendants($navRows, $maxDepth);
    }

    public function applySiteDomainGroupScope(Query $query, string $alias = ''): void
    {
        $this->tagCore->applySiteDomainGroupScope($query, $alias);
    }

    public function buildTagForest(array $tagRows, int $parentId = 0): array
    {
        return $this->tagTreeHelper->buildTagForest($tagRows, $parentId);
    }

    public function flattenNavTree(array $nodes, int $depth = 0): array
    {
        return $this->tagTreeHelper->flattenNavTree($nodes, $depth);
    }

    public function listParentOptions(int $excludeId = 0, int $groupId = 0): array
    {
        return $this->tagTreeHelper->listParentOptions($excludeId, $groupId);
    }

    public function collectDescendantIds(int $tagId): array
    {
        return $this->tagTreeHelper->collectDescendantIds($tagId);
    }

    public function validateParentAssignment(int $tagId, int $parentId, int $groupId, string $kind): string
    {
        return $this->tagTreeHelper->validateParentAssignment($tagId, $parentId, $groupId, $kind);
    }

    public function parentDepth(int $tagId): int
    {
        return $this->tagTreeHelper->parentDepth($tagId);
    }

    public function listNavGrouped(): array
    {
        return $this->tagTreeHelper->listNavGrouped();
    }

    public function buildNavGroupTree(array $tags, array $groups): array
    {
        return $this->tagTreeHelper->buildNavGroupTree($tags, $groups);
    }

    public function listAllActive(): array
    {
        return $this->tagTreeHelper->listAllActive();
    }

    public function getTagsForDocuments(array $articleIds): array
    {
        return $this->tagDocumentSync->getTagsForDocuments($articleIds);
    }

    public function countPublishedDocumentsByTagIds(array $tagIds): array
    {
        return $this->tagDocumentSync->countPublishedDocumentsByTagIds($tagIds);
    }

    public function countDocumentsByTagIds(array $tagIds, bool $publishedOnly = false): array
    {
        return $this->tagDocumentSync->countDocumentsByTagIds($tagIds, $publishedOnly);
    }

    public function formatForApi(array $row): array
    {
        return $this->tagCore->formatForApi($row);
    }

    public function upsertByName(string $name): int
    {
        return $this->tagCore->upsertByName($name);
    }

    public function normalizeTagsCsv(string $tagsStr): string
    {
        return $this->tagCore->normalizeTagsCsv($tagsStr);
    }

    public function parseTagNames(string $tagsStr): array
    {
        return $this->tagCore->parseTagNames($tagsStr);
    }

    public function isValidTagName(string $name): bool
    {
        return $this->tagCore->isValidTagName($name);
    }

    public function syncDocumentTags(int $articleId, string $tagsStr): void
    {
        $this->tagDocumentSync->syncDocumentTags($articleId, $tagsStr);
    }

    public function tagNamesCsvForDocument(int $documentId): string
    {
        return $this->tagDocumentSync->tagNamesCsvForDocument($documentId);
    }

    public function getTagsForDocument(int $documentId): array
    {
        return $this->tagDocumentSync->getTagsForDocument($documentId);
    }

    public function listAdminTree(array $params = []): array
    {
        return app(TagAdminService::class)->listAdminTree($params);
    }

    public function listAdmin(array $params = []): array
    {
        return app(TagAdminService::class)->listAdmin($params);
    }

    public function findAdmin(int $id): ?array
    {
        return app(TagAdminService::class)->findAdmin($id);
    }

    public function saveAdmin(array $data): ServiceResult
    {
        return app(TagAdminService::class)->saveAdmin($data);
    }

    public function updateSortAdmin(int $id, int $sort): ServiceResult
    {
        return app(TagAdminService::class)->updateSortAdmin($id, $sort);
    }

    public function updateStatusAdmin(int $id, int $status): ServiceResult
    {
        return app(TagAdminService::class)->updateStatusAdmin($id, $status);
    }

    public function deleteAdmin(int $id): ServiceResult
    {
        return app(TagAdminService::class)->deleteAdmin($id);
    }

    public function mergeAdmin(int $sourceId, int $targetId): ServiceResult
    {
        return app(TagAdminService::class)->mergeAdmin($sourceId, $targetId);
    }

    public function detachDocumentTags(int $documentId): void
    {
        $this->tagDocumentSync->detachDocumentTags($documentId);
    }

    public function purgeAllOrphanDocumentTagLinks(): int
    {
        return $this->tagDocumentSync->purgeAllOrphanDocumentTagLinks();
    }

    public function reconcileUseCount(int $id): ServiceResult
    {
        return $this->tagDocumentSync->reconcileUseCount($id);
    }

    public function batchDeleteAdmin(array $ids): ServiceResult
    {
        return app(TagAdminService::class)->batchDeleteAdmin($ids);
    }

    public function batchSetStatusAdmin(array $ids, int $status): ServiceResult
    {
        return app(TagAdminService::class)->batchSetStatusAdmin($ids, $status);
    }

    public function batchReconcileAdmin(array $ids): ServiceResult
    {
        return app(TagAdminService::class)->batchReconcileAdmin($ids);
    }

    public function buildAdminParentPath(int $tagId, ?array $index = null): string
    {
        return $this->tagTreeHelper->buildAdminParentPath($tagId, $index);
    }

    public function adminTagDepth(int $tagId, ?array $index = null): int
    {
        return $this->tagTreeHelper->adminTagDepth($tagId, $index);
    }
}
