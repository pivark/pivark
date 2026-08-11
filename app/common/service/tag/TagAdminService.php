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
use app\common\service\tag\TagCore;

use app\common\service\infra\UrlPathService;
use app\common\service\static\StaticHtmlDispatch;
use app\common\service\admin\AdminTagScopeService;
use app\common\service\member\MemberLevelService;
use app\common\service\audit\AuditLogService;
use app\common\model\TagGroup as TagGroupModel;
use think\facade\Db;
use app\common\model\Document;
use app\common\model\ItemTag;
use app\common\model\Tag;
use app\common\model\DocumentTag;
use app\common\support\ModelRelationLoad;
use app\common\support\DbTable;
use app\common\support\SiteDomainContext;
use app\common\support\SiteUrl;
use think\db\Query;

/** 标签（TAG）实现 */

/** 标签后台 CRUD */
class TagAdminService
{

    public function __construct(
        private readonly TagDocumentSync $tagDocumentSync,
        private readonly AdminTagScopeService $adminTagScopeService,
        private readonly TagCore $tagCore,
        private readonly TagTreeHelper $tagTreeHelper,
        private readonly MemberLevelService $memberLevelService,
        private readonly UrlPathService $urlPathService,
        private readonly AuditLogService $auditLogService,
        private readonly StaticHtmlDispatch $staticHtmlDispatch,
        private readonly TagPublicService $tagPublicService,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function listAdminTree(array $params = []): array
    {
        // 勿在列表路径做全表孤儿清理：大站 documents 数万时 whereNotIn(全部 id) 会拖垮接口
        $keyword = trim((string) ($params['keyword'] ?? ''));
        $status  = ($params['status'] ?? '') !== '' ? (int) $params['status'] : null;
        $groupId = ($params['group_id'] ?? '') !== '' ? (int) $params['group_id'] : null;

        $query = Tag::alias('t')->with([
            'tagGroup' => static function ($groupQuery): void {
                $groupQuery->field('id,name');
            },
            'parent' => static function ($parentQuery): void {
                $parentQuery->field('id,name');
            },
        ]);
        if ($keyword !== '') {
            $query->whereLike('t.name|t.slug', '%' . $keyword . '%');
        }
        if ($status !== null) {
            $query->where('t.status', $status);
        }
        if ($groupId !== null) {
            $query->where('t.group_id', $groupId);
        }
        $this->adminTagScopeService->applyTagQueryScope($query, 't');
        $query->order('t.nav_sort', 'asc')->order('t.id', 'asc');
        $rows = $this->mapTagAdminRows($query->select());
        $tagIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $docCounts = $this->tagDocumentSync->countDocumentsByTagIds($tagIds);
        $list = [];
        foreach ($rows as $row) {
            $list[] = $this->formatAdminRow($row, $docCounts[(int) $row['id']] ?? null);
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listAdmin(array $params = []): array
    {
        if (!empty($params['tree'])) {
            $list = $this->listAdminTree($params);

            return ['list' => $list, 'total' => count($list), 'page' => 1, 'limit' => count($list)];
        }

        $page    = max(1, (int) ($params['page'] ?? 1));
        $limit   = min(max((int) ($params['limit'] ?? 15), 1), 100);
        $keyword   = trim((string) ($params['keyword'] ?? ''));
        $status    = ($params['status'] ?? '') !== '' ? (int) $params['status'] : null;
        $groupId   = ($params['group_id'] ?? '') !== '' ? (int) $params['group_id'] : null;
        $parentFilter = ($params['parent_id'] ?? '') !== '' ? (int) $params['parent_id'] : null;
        $sortField = trim((string) ($params['field'] ?? ''));
        $sortOrder = strtolower((string) ($params['order'] ?? '')) === 'asc' ? 'asc' : 'desc';

        $docCountSql = '(SELECT COUNT(DISTINCT at.document_id) FROM ' . DbTable::model(DocumentTag::class)
            . ' at INNER JOIN ' . DbTable::model(Document::class)
            . ' a ON a.id = at.document_id AND a.deleted_at IS NULL WHERE at.tag_id = t.id)';

        $query = Tag::alias('t')->with([
            'tagGroup' => static function ($groupQuery): void {
                $groupQuery->field('id,name');
            },
            'parent' => static function ($parentQuery): void {
                $parentQuery->field('id,name');
            },
        ])->field('t.*, ' . $docCountSql . ' AS document_count_sort');

        $sortMap = [
            'id'             => 't.id',
            'nav_sort'       => 't.nav_sort',
            'use_count'      => 't.use_count',
            'document_count' => 'document_count_sort',
            'updated_at'     => 't.updated_at',
        ];
        if ($sortField !== '' && isset($sortMap[$sortField])) {
            $query->order($sortMap[$sortField], $sortOrder)->order('t.id', 'desc');
        } else {
            $query->order('t.nav_sort', 'asc')->order('t.use_count', 'desc')->order('t.id', 'desc');
        }
        if ($keyword !== '') {
            $query->whereLike('t.name|t.slug', '%' . $keyword . '%');
        }
        if ($status !== null) {
            $query->where('t.status', $status);
        }
        if ($groupId !== null) {
            $query->where('t.group_id', $groupId);
        }
        $this->adminTagScopeService->applyTagQueryScope($query, 't');
        if ($parentFilter !== null) {
            if ($parentFilter === -1) {
                $query->where('t.parent_id', 0);
            } elseif ($parentFilter > 0) {
                $query->where('t.parent_id', $parentFilter);
            }
        }

        $total = (int) $query->count();
        $rows  = $this->mapTagAdminRows($query->page($page, $limit)->select());
        $tagIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $docCounts = $this->tagDocumentSync->countDocumentsByTagIds($tagIds);
        $list  = [];
        foreach ($rows as $row) {
            $list[] = $this->formatAdminRow($row, $docCounts[(int) $row['id']] ?? null);
        }

        return ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAdmin(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $model = Tag::alias('t')->with([
            'tagGroup' => static function ($groupQuery): void {
                $groupQuery->field('id,name');
            },
            'parent' => static function ($parentQuery): void {
                $parentQuery->field('id,name');
            },
        ])->where('t.id', $id)->find();
        $row = $model === null ? null : $this->mapTagAdminRow($model);
        if (!$row) {
            return null;
        }
        $id = (int) $row['id'];
        if ($deny = $this->adminTagScopeService->assertCanManageTag($this->adminTagScopeService->currentUserId(), $id)) {
            return null;
        }
        $counts = $this->tagDocumentSync->countDocumentsByTagIds([$id]);

        return $this->formatAdminRow($row, $counts[$id] ?? 0);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id     = (int) ($data['id'] ?? 0);
        $name   = trim((string) ($data['name'] ?? ''));
        $slug   = trim((string) ($data['slug'] ?? ''));

        if ($name === '') {
            return ServiceResult::fail('标签名称不能为空');
        }
        if (mb_strlen($name) > 100) {
            return ServiceResult::fail('标签名称过长');
        }

        $dupName = Tag::where('name', $name);
        if ($id > 0) {
            $dupName->where('id', '<>', $id);
        }
        if ($dupName->count() > 0) {
            return ServiceResult::fail('标签名称已存在');
        }

        if ($slug === '') {
            $slug = $this->tagCore->makeSlug($name, $id);
        } else {
            $slug = strtolower(trim((string) preg_replace('/[^a-z0-9\-]+/i', '-', $slug), '-'));
            if ($slug === '') {
                return ServiceResult::fail('Slug 格式无效');
            }
            $slug = substr($slug, 0, 100);
            if ($this->tagCore->slugExists($slug, $id)) {
                return ServiceResult::fail('Slug 已被占用');
            }
        }

        $status = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
        // 专题通道默认 topic；兼容旧 label 行（表单不再暴露类型）
        $kind = $this->tagCore->normalizeKind($data['kind'] ?? TagCore::KIND_TOPIC);
        $groupId = (int) ($data['group_id'] ?? 0);
        if ($groupId < 1) {
            $groupId = app(TagGroupService::class)->defaultGroupId();
        }
        $navSort = (int) ($data['nav_sort'] ?? 0);
        $now    = AppTime::now();

        if ($groupId > 0 && !TagGroupModel::where('id', $groupId)->where('status', 1)->find()) {
            return ServiceResult::fail('所选分组不存在或已禁用');
        }

        $parentId = max(0, (int) ($data['parent_id'] ?? 0));
        $parentErr = $this->tagTreeHelper->validateParentAssignment($id, $parentId, $groupId, $kind);
        if ($parentErr !== '') {
            return ServiceResult::fail($parentErr);
        }
        if ($kind === TagCore::KIND_LABEL && $id > 0 && (int) Tag::where('parent_id', $id)->count() > 0) {
            return ServiceResult::fail('该标注标签下仍有子标签，请先调整子标签');
        }

        $operatorId = $this->adminTagScopeService->currentUserId();
        if ($id > 0 && ($deny = $this->adminTagScopeService->assertCanManageTag($operatorId, $id))) {
            return $deny;
        }
        if ($parentId > 0 && ($deny = $this->adminTagScopeService->assertCanManageTag($operatorId, $parentId))) {
            return $deny;
        }

        $readAccess = $this->memberLevelService->readAccessFromPost($data);

        // 聚合 Tag 可无门牌：显式传空 url_path 时禁止回落 slug（分类门牌归 site_nav）
        $hasPathKey = array_key_exists('url_path', $data);
        $rawPath    = trim((string) ($data['url_path'] ?? ''), '/');
        if ($hasPathKey && $rawPath === '') {
            $urlPath = '';
        } else {
            $urlPath = $this->urlPathService->normalize($rawPath !== '' ? $rawPath : $slug);
            if ($urlPath === '') {
                $urlPath = $slug;
            }
            $pathErr = $this->urlPathService->validateAvailable($urlPath, 'tag', $id);
            if ($pathErr !== '') {
                return ServiceResult::fail($pathErr);
            }
        }

        // Tag：可选专题 path；空 = 仅聚合。分类列表门牌归 site_nav。
        $payload = [
            'name'            => $name,
            'slug'            => $slug,
            'kind'            => $kind,
            'group_id'        => max(0, $groupId),
            'parent_id'       => $parentId,
            'description'     => trim((string) ($data['description'] ?? '')),
            'nav_sort'        => $navSort,
            'url_path'        => $urlPath,
            'litpic'          => trim((string) ($data['litpic'] ?? '')),
            'tpl_name'        => trim((string) ($data['tpl_name'] ?? '')),
            'view_tpl_name'   => trim((string) ($data['view_tpl_name'] ?? '')),
            'seo_title'       => trim((string) ($data['seo_title'] ?? '')),
            'seo_keywords'    => trim((string) ($data['seo_keywords'] ?? '')),
            'seo_description' => trim((string) ($data['seo_description'] ?? '')),
            'read_perm'       => $readAccess['read_perm'],
            'read_level_id'   => $readAccess['read_level_id'],
            'status'          => $status,
            'updated_at'      => $now,
        ];
        if (array_key_exists('extra_fields', $data) || array_key_exists('extra_json', $data)) {
            $payload['extra_json'] = $this->tagCore->encodeExtraFields(
                $data['extra_fields'] ?? $data['extra_json'] ?? null
            );
        }

        if ($id > 0) {
            $row = $this->tagRow(Tag::where('id', $id)->find());
            if (!$row) {
                return ServiceResult::fail('标签不存在');
            }
            Tag::where('id', $id)->update($payload);
            $this->auditLogService->operate('更新标签', 'admin.tag', ['tag_id' => $id, 'name' => $name]);
            $this->staticHtmlDispatch->afterTagChange($id);
            $this->adminTagScopeService->clearTagScopeCache();

            return ServiceResult::ok(['id' => $id], '保存成功');
        }

        $newId = (int) Tag::insertGetId(array_merge($payload, [
            'extra_json' => $payload['extra_json'] ?? $this->tagCore->encodeExtraFields(null),
            'use_count'  => 0,
            'created_at' => $now,
        ]));
        $this->auditLogService->operate('创建标签', 'admin.tag', ['tag_id' => $newId, 'name' => $name]);
        $this->staticHtmlDispatch->afterTagChange($newId);
        $this->adminTagScopeService->clearTagScopeCache();

        return ServiceResult::ok(['id' => $newId], '创建成功');
    }

public function updateSortAdmin(int $id, int $sort): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        if (!Tag::where('id', $id)->find()) {
            return ServiceResult::fail('标签不存在');
        }
        Tag::where('id', $id)->update([
            'nav_sort'   => $sort,
            'updated_at' => AppTime::now(),
        ]);
        return ServiceResult::ok(null, '已更新');
    }

public function updateStatusAdmin(int $id, int $status): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        if (!Tag::where('id', $id)->find()) {
            return ServiceResult::fail('标签不存在');
        }
        $status = $status === 1 ? 1 : 0;
        Tag::where('id', $id)->update([
            'status'     => $status,
            'updated_at' => AppTime::now(),
        ]);
        $this->staticHtmlDispatch->afterTagChange($id);
        return ServiceResult::ok(['status' => $status], $status === 1 ? '已启用' : '已禁用');
    }

public function deleteAdmin(int $id): ServiceResult
    {
        if ($deny = $this->adminTagScopeService->assertCurrentCanManageTag($id)) {
            return $deny;
        }
        $row = $this->tagRow(Tag::where('id', $id)->find());
        if ($row === null) {
            return ServiceResult::fail('标签不存在');
        }
        $this->tagDocumentSync->purgeOrphanDocumentTagLinksForTag($id);
        $linked = $this->tagDocumentSync->countLiveDocumentLinks($id);
        if ($linked > 0) {
            return ServiceResult::fail("该标签仍关联 {$linked} 篇有效文章，请先合并到其他标签或解除关联");
        }
        $itemLinked = (int) ItemTag::where('tag_id', $id)->count();
        if ($itemLinked > 0) {
            return ServiceResult::fail("该标签仍关联 {$itemLinked} 个品项，请先改绑到其他标签或解除关联");
        }
        $childCount = (int) Tag::where('parent_id', $id)->count();
        if ($childCount > 0) {
            return ServiceResult::fail("该栏目下仍有 {$childCount} 个子栏目，请先调整或删除子栏目");
        }

        Tag::where('id', $id)->delete();
        $this->auditLogService->operate('删除标签', 'admin.tag', ['tag_id' => $id, 'name' => $row['name'] ?? '']);
        $this->staticHtmlDispatch->afterTagDelete($row);
        return ServiceResult::ok(null, '删除成功');
    }

public function mergeAdmin(int $sourceId, int $targetId): ServiceResult
    {
        if ($sourceId < 1 || $targetId < 1 || $sourceId === $targetId) {
            return ServiceResult::fail('参数无效');
        }
        $source = $this->tagRow(Tag::where('id', $sourceId)->find());
        $target = $this->tagRow(Tag::where('id', $targetId)->find());
        if ($source === null || $target === null) {
            return ServiceResult::fail('标签不存在');
        }

        Db::transaction(function () use ($sourceId, $targetId, $source): void {
            $articleIds = array_map('intval', DocumentTag::where('tag_id', $sourceId)->column('document_id'));
            if ($articleIds !== []) {
                $dupIds = DocumentTag::where('tag_id', $targetId)
                    ->whereIn('document_id', $articleIds)
                    ->column('document_id');
                if ($dupIds !== []) {
                    DocumentTag::where('tag_id', $sourceId)
                        ->whereIn('document_id', $dupIds)
                        ->delete();
                }
                DocumentTag::where('tag_id', $sourceId)
                    ->whereIn('document_id', $articleIds)
                    ->update(['tag_id' => $targetId]);
            }

            $itemIds = array_map('intval', ItemTag::where('tag_id', $sourceId)->column('item_id'));
            if ($itemIds !== []) {
                $dupItemIds = ItemTag::where('tag_id', $targetId)
                    ->whereIn('item_id', $itemIds)
                    ->column('item_id');
                if ($dupItemIds !== []) {
                    ItemTag::where('tag_id', $sourceId)
                        ->whereIn('item_id', $dupItemIds)
                        ->delete();
                }
                ItemTag::where('tag_id', $sourceId)
                    ->whereIn('item_id', $itemIds)
                    ->update(['tag_id' => $targetId]);
            }

            $this->tagDocumentSync->reconcileUseCountInternal($targetId);
            $newParent = (int) ($source['parent_id'] ?? 0);
            Tag::where('parent_id', $sourceId)->update([
                'parent_id'  => $newParent,
                'updated_at' => AppTime::now(),
            ]);
            Tag::where('id', $sourceId)->delete();
            $this->auditLogService->operate('合并标签', 'admin.tag', [
                'source_id'   => $sourceId,
                'source_name' => $source['name'] ?? '',
                'target_id'   => $targetId,
            ]);
        });

        $this->staticHtmlDispatch->afterTagDelete($source);
        $this->staticHtmlDispatch->afterTagChange($targetId);

        return ServiceResult::ok(null, '合并成功');
    }

    /**
     * @param list<int> $ids
     */
    public function batchDeleteAdmin(array $ids): ServiceResult
    {
        return $this->runBatchAdmin($ids, fn (int $id): ServiceResult => $this->deleteAdmin($id), '删除');
    }

    /**
     * @param list<int> $ids
     */
    public function batchSetStatusAdmin(array $ids, int $status): ServiceResult
    {
        $status = $status === 1 ? 1 : 0;
        $label  = $status === 1 ? '启用' : '禁用';

        return $this->runBatchAdmin(
            $ids,
            fn (int $id): ServiceResult => $this->updateStatusAdmin($id, $status),
            $label
        );
    }

    /**
     * @param list<int> $ids
     */
    public function batchReconcileAdmin(array $ids): ServiceResult
    {
        return $this->runBatchAdmin(
            $ids,
            fn (int $id): ServiceResult => $this->tagDocumentSync->reconcileUseCount($id),
            '校正引用'
        );
    }

    /**
     * @param list<int> $ids
     */
    private function runBatchAdmin(array $ids, callable $action, string $actionLabel): ServiceResult
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return ServiceResult::fail('请选择标签');
        }

        $ok     = 0;
        $failed = [];
        foreach ($ids as $id) {
            $res = $action($id);
            if ($res->isOk()) {
                $ok++;
                continue;
            }
            $name     = (string) (Tag::where('id', $id)->value('name') ?? '');
            $failed[] = ($name !== '' ? $name : ('ID ' . $id)) . '：' . (string) ($res->message() ?? '失败');
        }

        if ($ok < 1) {
            return ServiceResult::fail('没有可' . $actionLabel . '的标签' . ($failed !== [] ? '；' . implode('；', array_slice($failed, 0, 3)) : ''));
        }

        $msg = "已{$actionLabel} {$ok} 个标签";
        if ($failed !== []) {
            $msg .= '，' . count($failed) . ' 个失败';
        }

        $this->auditLogService->operate('批量' . $actionLabel . '标签', 'admin.tag', [
            'ids'    => $ids,
            'count'  => $ok,
            'failed' => $failed,
        ]);

        return ServiceResult::ok(['count' => $ok, 'failed' => $failed], $msg);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatAdminRow(array $row, ?int $documentCount = null): array
    {
        $articleCount = $documentCount ?? (int) DocumentTag::alias('at')
            ->join('documents a', 'a.id = at.document_id')
            ->where('at.tag_id', (int) $row['id'])
            ->whereNull('a.deleted_at')
            ->count();

        $labels = $this->tagCore->kindLabels();
        $tagId  = (int) $row['id'];
        $depth  = $this->tagTreeHelper->adminTagDepth($tagId);

        return [
            'id'              => $tagId,
            'name'            => (string) $row['name'],
            'name_indent'     => ($depth > 0 ? str_repeat('　', $depth) . '└ ' : '') . (string) $row['name'],
            'slug'            => (string) ($row['slug'] ?? ''),
            'url_path'        => $this->tagPublicService->publicPath($row),
            'kind'            => $this->tagCore->normalizeKind($row['kind'] ?? TagCore::KIND_LABEL),
            'kind_text'       => $labels[$this->tagCore->normalizeKind($row['kind'] ?? TagCore::KIND_LABEL)] ?? '标注标签',
            'group_id'        => (int) ($row['group_id'] ?? 0),
            'group_name'      => (string) ($row['group_name'] ?? ''),
            'parent_id'       => (int) ($row['parent_id'] ?? 0),
            'parent_name'     => (string) ($row['parent_name'] ?? ''),
            'parent_path'     => $this->tagTreeHelper->buildAdminParentPath($tagId),
            'tree_depth'      => $depth,
            'nav_sort'        => (int) ($row['nav_sort'] ?? 0),
            'description'     => (string) ($row['description'] ?? ''),
            'litpic'          => (string) ($row['litpic'] ?? ''),
            'read_perm'       => (int) ($row['read_perm'] ?? 0),
            'read_level_id'   => (int) ($row['read_level_id'] ?? 0),
            'read_access'     => $this->memberLevelService->encodeReadAccess(
                (int) ($row['read_perm'] ?? 0),
                (int) ($row['read_level_id'] ?? 0)
            ),
            'tpl_name'        => (string) ($row['tpl_name'] ?? ''),
            'view_tpl_name'   => (string) ($row['view_tpl_name'] ?? ''),
            'seo_title'       => (string) ($row['seo_title'] ?? ''),
            'seo_keywords'    => (string) ($row['seo_keywords'] ?? ''),
            'seo_description' => (string) ($row['seo_description'] ?? ''),
            'extra_fields'    => $this->tagCore->normalizeExtraFieldDefs($row['extra_json'] ?? null),
            'status'          => (int) ($row['status'] ?? 1),
            'status_text'     => (int) ($row['status'] ?? 1) === 1 ? '启用' : '禁用',
            'use_count'       => $articleCount,
            'document_count'   => $articleCount,
            'url'             => SiteUrl::tagFromRow($row),
            'created_at'      => (string) ($row['created_at'] ?? ''),
            'updated_at'      => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param iterable<mixed> $models
     * @return list<array<string, mixed>>
     */
    private function mapTagAdminRows(iterable $models): array
    {
        $out = [];
        foreach ($models as $model) {
            $out[] = $this->mapTagAdminRow($model);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function mapTagAdminRow(mixed $model): array
    {
        $row = ModelRelationLoad::mergeBelongsTo($model, 'tagGroup', ['name' => 'group_name']);

        return ModelRelationLoad::mergeBelongsTo($row, 'parent', ['name' => 'parent_name']);
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
