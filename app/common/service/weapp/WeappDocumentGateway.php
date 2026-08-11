<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappDocumentGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\support\AppTime;
use app\common\support\ServiceResult;
use app\common\model\Document;
use app\common\model\DocumentTag;
use app\common\model\DocumentItemRef;
use app\common\service\document\DocumentAdminService;
use app\common\service\document\DocumentPublicService;
use app\common\service\document\satellite\DocumentAssetSyncService;
use app\common\service\document\satellite\DocumentEnterpriseResourceService;
use app\common\service\document\DocumentAttrHelper;
use app\common\service\document\DocumentFormatService;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\plugin\gateway\PluginGatewayPermissionService;

final class WeappDocumentGateway
{
    use PluginGatewayGuardTrait;

    public function __construct(
        private readonly DocumentAssetSyncService $documentAssetSync,
        private readonly DocumentAttrHelper $documentAttrHelper,
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly DocumentFormatService $documentFormat,
        private readonly DocumentPublicService $documentPublic,
        private readonly DocumentAdminService $documentAdmin,
        private readonly DocumentEnterpriseResourceService $documentEnterpriseResource,
        private readonly PluginGatewayPermissionService $gatewayPermission,
    ) {
    }

    public function documentDownloadBridgeEnabled(): bool
    {
        $id = DocumentAddonBridgeAccess::docGalleryPackBridgeIdentifier();

        return $id !== null && DocumentAddonBridgeAccess::isEnabled($id);
    }

    public function documentDownloadBridgePurgeForDocument(int $documentId): void
    {
        $id = DocumentAddonBridgeAccess::docGalleryPackBridgeIdentifier();
        if ($id === null) {
            return;
        }
        DocumentAddonBridgeAccess::invoke($id, 'purgeForDocument', [$documentId]);
    }

    public function documentAddonBridgeIsEnabled(string $identifier): bool
    {
        return DocumentAddonBridgeAccess::isEnabled($identifier);
    }

    /** @param list<mixed> $args */
    public function documentAddonBridgeInvoke(string $identifier, string $method, array $args): mixed
    {
        return DocumentAddonBridgeAccess::invoke($identifier, $method, $args);
    }

    public function documentAssetSyncDownload(int $documentId): void
    {
        $this->documentAssetSync->syncDownloadForDocument($documentId);
    }

    public function documentAssetSyncVideo(int $documentId): void
    {
        $this->documentAssetSync->syncVideoForDocument($documentId);
    }

    public function documentPrimaryItemId(int $documentId): int
    {
        if ($documentId < 1) {
            return 0;
        }

        return (int) DocumentItemRef::where('document_id', $documentId)
            ->order('sort', 'asc')
            ->value('item_id');
    }

    public function documentExistsPublished(int $documentId): bool
    {
        $this->gatewayRequire('document.read');
        if ($documentId < 1) {
            return false;
        }

        return (bool) Document::where('id', $documentId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->find();
    }

    public function documentMergeAttrHasImage(string $flagsCsv, string $litpic): string
    {
        return $this->documentAttrHelper->mergeAttrHasImage($flagsCsv, $litpic);
    }

    /** 插件（如 gallery）回写 documents.litpic / attr_flags */
    public function documentPatchLitpic(int $documentId, string $litpic, string $attrFlags): void
    {
        $this->gatewayRequire('document.write');
        if ($documentId < 1 || $litpic === '') {
            return;
        }
        Document::where('id', $documentId)->update([
            'litpic'     => $litpic,
            'attr_flags' => $attrFlags,
            'updated_at' => AppTime::now(),
        ]);
        $this->frontCacheInvalidator->bumpGeneration();
    }

    /** @param array<string, mixed> $row */
    public function documentFormatPublicUrl(array $row, ?array $prefetchedTags = null): string
    {
        return $this->documentFormat->buildPublicDocumentUrl($row, $prefetchedTags);
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function documentListPublic(array $params): array
    {
        $this->gatewayRequire('document.read');

        return $this->documentPublic->listPublic($params);
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function documentSaveAdmin(array $data, int $authorId = 0): ServiceResult
    {
        $this->gatewayRequire('document.write');

        return $this->documentAdmin->saveAdmin($data, $authorId);
    }

    /**
     * 会员投稿（强制待审 + 必挂 nav_id）
     *
     * @param array<string, mixed> $data
     */
    public function documentSaveForMember(array $data, int $memberId): ServiceResult
    {
        $this->gatewayRequire('document.write');

        return $this->documentAdmin->saveForMember($data, $memberId);
    }

    public function documentEnterpriseResourceEnabled(): bool
    {
        return $this->documentEnterpriseResource->enabled();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function documentEnterpriseResourceRecall(string $brief, int $entityId, int $limit): array
    {
        return $this->documentEnterpriseResource->recall($brief, $entityId, $limit);
    }

    /**
     * @param list<int> $ids
     * @return list<array{id:int,title:string,summary:string,url:string,type_label:string}>
     */
    public function documentRecallByIds(array $ids, int $limit = 8): array
    {
        $this->gatewayRequire('document.read');
        $sources = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $ids))), 0, max(1, $limit)) as $id) {
            if ($id < 1) {
                continue;
            }
            $row = $this->documentAdmin->findForAdmin($id);
            if (!is_array($row)) {
                continue;
            }
            $summary = trim(strip_tags((string) ($row['summary'] ?? $row['description'] ?? '')));
            $sources[] = [
                'id'         => $id,
                'title'      => (string) ($row['title'] ?? ('文档#' . $id)),
                'summary'    => mb_substr($summary, 0, 400),
                'url'        => $this->documentFormatPublicUrl($row),
                'type_label' => '指定文档',
            ];
        }

        return $sources;
    }

    /** @return array<string, mixed>|null */
    public function documentRowById(int $documentId, bool $requirePublished = false): ?array
    {
        $this->gatewayRequire('document.read');
        if ($documentId < 1) {
            return null;
        }
        $query = Document::where('id', $documentId)->whereNull('deleted_at');
        if ($requirePublished) {
            $query->where('status', 1);
        }
        $row = $query->find();

        return is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (is_array($row) ? $row : null);
    }

    public function documentTitleById(int $documentId): string
    {
        $row = $this->documentRowById($documentId, false);

        return trim((string) ($row['title'] ?? ''));
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function documentRowsByIds(array $ids, string $fields = 'id,title'): array
    {
        $this->gatewayRequire('document.read');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        return Document::whereIn('id', $ids)->field($fields)->select()->toArray();
    }

    public function documentCountPublished(): int
    {
        $this->gatewayRequire('document.read');

        return (int) Document::where('status', 1)->whereNull('deleted_at')->count();
    }

    /**
     * @return array{ids:list<int>,total:int}
     */
    public function documentPublishedIdPage(int $page, int $pageSize): array
    {
        $this->gatewayRequire('document.read');
        $page     = max(1, $page);
        $pageSize = max(1, min(500, $pageSize));
        $query    = Document::where('status', 1)->whereNull('deleted_at');
        $total    = (int) $query->count();
        $ids      = $query->page($page, $pageSize)->column('id');

        return [
            'ids'   => array_map('intval', is_array($ids) ? $ids : []),
            'total' => $total,
        ];
    }

    /** @return list<int> */
    public function documentPublishedIds(?string $htmlName = null): array
    {
        $this->gatewayRequire('document.read');
        $query = Document::where('status', 1)->whereNull('deleted_at');
        if ($htmlName !== null && $htmlName !== '') {
            $query->where('html_name', $htmlName);
        }
        $ids = $query->column('id');

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /** @return list<int> */
    public function documentPublishedIdsWithTitleLike(string $keyword): array
    {
        $this->gatewayRequire('document.read');
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        $pattern = '%' . addcslashes($keyword, '%_\\') . '%';
        $ids     = Document::where('status', 1)
            ->whereNull('deleted_at')
            ->whereLike('title', $pattern)
            ->column('id');

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /** @return list<int> */
    public function documentIdsByTagId(int $tagId): array
    {
        $this->gatewayRequire('document.read');
        if ($tagId < 1) {
            return [];
        }
        $ids = DocumentTag::where('tag_id', $tagId)->column('document_id');

        return array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
    }

    /**
     * 按主栏目 nav_id 取文档（不含附加栏目；禁双轨）。
     *
     * @param list<int> $navIds
     * @return list<int>
     */
    public function documentIdsByNavIds(array $navIds): array
    {
        $this->gatewayRequire('document.read');
        $navIds = array_values(array_unique(array_filter(array_map('intval', $navIds), static fn (int $id): bool => $id > 0)));
        if ($navIds === []) {
            return [];
        }
        $ids = Document::whereIn('nav_id', $navIds)->whereNull('deleted_at')->column('id');

        return array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
    }

    public function documentPrimaryNavId(int $documentId): int
    {
        $this->gatewayRequire('document.read');
        if ($documentId < 1) {
            return 0;
        }
        $row = Document::where('id', $documentId)->whereNull('deleted_at')->field('nav_id')->find()?->toArray();

        return (int) ($row['nav_id'] ?? 0);
    }

    /**
     * @param list<int> $documentIds
     * @param list<int> $allowedNavIds 已含子孙的允许栏目 id
     * @return list<int>
     */
    public function documentFilterIdsByPrimaryNav(array $documentIds, array $allowedNavIds): array
    {
        $this->gatewayRequire('document.read');
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn (int $id): bool => $id > 0)));
        $allowed     = array_fill_keys(
            array_values(array_unique(array_filter(array_map('intval', $allowedNavIds), static fn (int $id): bool => $id > 0))),
            true
        );
        if ($documentIds === [] || $allowed === []) {
            return [];
        }
        $rows = Document::whereIn('id', $documentIds)->whereNull('deleted_at')->field('id,nav_id')->select()->toArray();
        $out  = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $navId = (int) ($row['nav_id'] ?? 0);
            if ($navId > 0 && isset($allowed[$navId])) {
                $out[] = (int) $row['id'];
            }
        }

        return $out;
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function documentPublishedRowsPage(int $page, int $pageSize, int $navId = 0, string $fields = 'id,title,litpic'): array
    {
        $this->gatewayRequire('document.read');
        $page     = max(1, $page);
        $pageSize = max(1, min(500, $pageSize));
        $query    = Document::where('status', 1)->whereNull('deleted_at');
        if ($navId > 0) {
            $query->where('nav_id', $navId);
        }
        $total = (int) (clone $query)->count();
        $rows  = $query->field($fields)->order('id', 'asc')->page($page, $pageSize)->select()->toArray();

        return ['rows' => $rows, 'total' => $total];
    }

    public function documentDownloadPackBridgeIdentifier(): ?string
    {
        return DocumentAddonBridgeAccess::docGalleryPackBridgeIdentifier();
    }

    public function documentPrimaryTagIdForDocument(int $documentId): int
    {
        $this->gatewayRequire('document.read');
        if ($documentId < 1) {
            return 0;
        }

        return (int) DocumentTag::where('document_id', $documentId)->order('id', 'asc')->value('tag_id');
    }

    /** @param list<mixed> $args */
    public function documentAddonBridgeInvokeOr(mixed $default, string $identifier, string $method, array $args): mixed
    {
        if (!$this->documentAddonBridgeIsEnabled($identifier)) {
            return $default;
        }
        try {
            return $this->documentAddonBridgeInvoke($identifier, $method, $args);
        } catch (\Throwable) {
            return $default;
        }
    }

    public function documentIdByHtmlName(string $htmlName, bool $excludeDeleted = true): int
    {
        $this->gatewayRequire('document.read');
        $htmlName = trim($htmlName);
        if ($htmlName === '') {
            return 0;
        }
        $q = Document::where('html_name', $htmlName);
        if ($excludeDeleted) {
            $q->whereNull('deleted_at');
        }

        return (int) $q->value('id');
    }

    public function documentExistsByHtmlName(string $htmlName, bool $excludeDeleted = true): bool
    {
        return $this->documentIdByHtmlName($htmlName, $excludeDeleted) > 0;
    }

    public function documentCountByTagSlug(string $tagSlug): int
    {
        $this->gatewayRequire('document.read');
        $tagSlug = trim($tagSlug);
        if ($tagSlug === '') {
            return 0;
        }

        return (int) Document::alias('d')
            ->join('document_tags dt', 'd.id = dt.document_id')
            ->join('tags t', 'dt.tag_id = t.id')
            ->where('t.slug', $tagSlug)
            ->whereNull('d.deleted_at')
            ->count();
    }

    /** 将挂某 Tag 且未挂栏目的文档回填主栏目（真分类收口，不经 Tag 归属）。 */
    public function backfillDocumentNavIdByTagSlug(string $tagSlug, int $navId): int
    {
        $this->gatewayRequire('document.write');
        $tagSlug = trim($tagSlug);
        $navId = max(0, $navId);
        if ($tagSlug === '' || $navId < 1) {
            return 0;
        }
        $ids = Document::alias('d')
            ->join('document_tags dt', 'd.id = dt.document_id')
            ->join('tags t', 'dt.tag_id = t.id')
            ->where('t.slug', $tagSlug)
            ->whereNull('d.deleted_at')
            ->where(function ($q): void {
                $q->whereNull('d.nav_id')->whereOr('d.nav_id', 0);
            })
            ->column('d.id');
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return (int) Document::whereIn('id', $ids)->update(['nav_id' => $navId]);
    }

    /** @return list<int> */
    public function documentIdsByHtmlLike(string $pattern, bool $excludeDeleted = true): array
    {
        $this->gatewayRequire('document.read');
        $pattern = trim($pattern);
        if ($pattern === '') {
            return [];
        }
        $q = Document::where('html_name', 'like', $pattern);
        if ($excludeDeleted) {
            $q->whereNull('deleted_at');
        }
        $ids = $q->column('id');

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function documentSoftDelete(int $documentId, string $deletedAt): void
    {
        $this->gatewayRequire('document.write');
        if ($documentId < 1) {
            return;
        }
        Document::where('id', $documentId)->update([
            'deleted_at' => $deletedAt,
            'updated_at' => $deletedAt,
        ]);
    }

    /** @param array<string, mixed> $fields */
    public function documentUpdateFields(int $documentId, array $fields): void
    {
        $this->gatewayRequire('document.write');
        if ($documentId < 1 || $fields === []) {
            return;
        }
        Document::where('id', $documentId)->update($fields);
    }

    /** @return list<array{id:int,litpic:string}> */
    public function documentLitpicRowsByTagSlugHtmlLike(string $tagSlug, string $htmlPattern): array
    {
        $this->gatewayRequire('document.read');
        $rows = Document::alias('d')
            ->join('document_tags dt', 'd.id = dt.document_id')
            ->join('tags t', 'dt.tag_id = t.id')
            ->where('t.slug', $tagSlug)
            ->whereNull('d.deleted_at')
            ->where('d.html_name', 'like', $htmlPattern)
            ->field('d.id,d.litpic')
            ->order('d.id', 'asc')
            ->select()
            ->toArray();

        return is_array($rows) ? $rows : [];
    }

    public function documentCountByTagId(int $tagId): int
    {
        $this->gatewayRequire('document.read');
        if ($tagId < 1) {
            return 0;
        }

        return (int) Document::alias('d')
            ->join('document_tags dt', 'd.id = dt.document_id')
            ->where('dt.tag_id', $tagId)
            ->whereNull('d.deleted_at')
            ->count();
    }

    public function documentCountPublishedById(int $documentId): int
    {
        $this->gatewayRequire('document.read');
        if ($documentId < 1) {
            return 0;
        }

        return (int) Document::where('id', $documentId)->whereNull('deleted_at')->count();
    }

    public function documentInsertGetId(array $data): int
    {
        $this->gatewayRequire('document.write');

        return (int) Document::insertGetId($data);
    }

    public function documentUniqueHtmlNameExists(string $htmlName, bool $excludeDeleted = true): bool
    {
        $this->gatewayRequire('document.read');
        $htmlName = trim($htmlName);
        if ($htmlName === '') {
            return false;
        }
        $q = Document::where('html_name', $htmlName);
        if ($excludeDeleted) {
            $q->whereNull('deleted_at');
        }

        return $q->find() !== null;
    }

    /** @return list<int> */
    public function documentIdsByHtmlLikeSimple(string $pattern): array
    {
        $this->gatewayRequire('document.read');
        $ids = Document::where('html_name', 'like', $pattern)->column('id');

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function documentCountPublishedByTitleLike(string $keyword): int
    {
        $this->gatewayRequire('document.read');
        $keyword = trim($keyword);
        if ($keyword === '') {
            return 0;
        }

        return (int) Document::alias('d')
            ->where('d.title', 'like', '%' . addcslashes($keyword, '%_\\') . '%')
            ->whereNull('d.deleted_at')
            ->count();
    }
}
