<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 文档后台：列表、保存、批量委托、会员投稿
 */
declare(strict_types=1);

namespace app\common\service\document;

use app\common\support\ServiceResult;
use app\common\support\QueryLimit;
use app\common\support\DbTable;
use think\facade\Db;


use app\common\model\Document;
use app\common\model\DocumentTag;
use app\common\model\Item;
use app\common\service\audit\AuditLogService;
use app\common\service\content\ContentSearchService;
use app\common\service\item\ItemService;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberService;
use app\common\service\media\MediaAssetRefService;
use app\common\service\search\SearchIndexService;
use app\common\service\site\SiteModeService;
use app\common\service\site\SiteNavService;
use app\common\service\static\StaticHtmlDispatch;
use app\common\service\static\StaticHtmlService;
use app\common\service\tag\TagService;
use app\common\support\DbRead;
use app\common\support\AppTime;
use app\common\support\CursorPaginator;
use app\common\support\OpsLog;
use app\common\support\SiteUrl;
use think\db\Query;

final class DocumentAdminService
{

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function applyAdminListFilters(Query $query, string $keyword, string $tag, string $attr): void
    {
        if ($keyword !== '') {
            app(ContentSearchService::class)->applyArticleKeyword($query, $keyword, true);
        }
        if ($tag !== '') {
            $ids = DocumentTag::alias('at')
                ->join('tags t', 't.id = at.tag_id')
                ->where('t.name', $tag)
                ->column('at.document_id');
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $query->whereIn('id', $ids !== [] ? $ids : [0]);
        }
        app(DocumentAttrFlagIndexService::class)->applyFlagFilter($query, $attr);
    }
    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array>,total:int}
     */
    public function listAdmin(array $params): array
    {
        $page    = max(1, (int) ($params['page'] ?? 1));
        $limit   = min(max((int) ($params['limit'] ?? 15), 1), 100);
        $keyword = trim((string) ($params['keyword'] ?? ''));
        $tag     = trim((string) ($params['tag'] ?? $params['tags'] ?? ''));
        $attr    = trim((string) ($params['attr'] ?? $params['attr_flag'] ?? ''));
        $navId   = max(0, (int) ($params['nav_id'] ?? 0));
        $recycle = (int) ($params['recycle'] ?? 0) === 1;
        $statusFilter = array_key_exists('status', $params) ? (int) $params['status'] : null;
        $memberPending = (int) ($params['member_pending'] ?? $params['member'] ?? 0) === 1;
        $excludeItemPrimary = !array_key_exists('exclude_item_primary', $params)
            || (int) $params['exclude_item_primary'] === 1;

        $query = Document::query()->field([
            'id',
            'title',
            'litpic',
            'status',
            'attr_flags',
            'author_id',
            'published_at',
            'created_at',
            'updated_at',
            'deleted_at',
            'html_name',
            'url_path',
            'nav_id',
            'click',
            // 列表禁拉 content/seo_*/extra_json（正文可达数百 KB/行）
        ]);
        if ($recycle) {
            $query->whereNotNull('deleted_at');
        } else {
            $query->whereNull('deleted_at');
            if ($memberPending) {
                $query->where('status', 0)->where('author_id', '>', 0);
            } elseif ($statusFilter !== null) {
                $query->where('status', $statusFilter === 1 ? 1 : 0);
            }
        }
        // 主栏目树或附加栏目命中
        if ($navId > 0) {
            $navIds = app(SiteNavService::class)->contentCategorySelfAndDescendantIds($navId);
            app(SiteNavService::class)->applyPrimaryOrExtraNavFilter($query, $navIds !== [] ? $navIds : [0], 'document');
        }
        app(\app\common\service\admin\AdminTagScopeService::class)->applyNavQueryScope($query, 'nav_id', 'document');
        // 按标签筛选时须展示该标签下全部关联稿（含品项/产品主绑定文档），与标签「文档」计数一致
        if ($excludeItemPrimary && !$recycle && !$memberPending && $tag === '') {
            $excludeDocIds = $this->adminListExcludedProductDocumentIds();
            if ($excludeDocIds !== []) {
                $query->whereNotIn('id', $excludeDocIds);
            }
        }
        $this->applyAdminListFilters($query, $keyword, $tag, $attr);
        $cursorId = (int) ($params['cursor_id'] ?? 0);
        $orderCol = $recycle ? 'deleted_at' : 'id';
        $allowCursor = $keyword === '' && $tag === '' && $attr === '' && $navId < 1;
        $pageResult = CursorPaginator::paginateById(
            $query,
            $limit,
            $page,
            $cursorId,
            $orderCol,
            $allowCursor,
            'id'
        );
        $rows  = $pageResult['rows'];
        $total = $pageResult['total'];
        $nextCursor = $pageResult['next_cursor_id'];
        $hasMore = (int) ($pageResult['has_more'] ?? 0);
        $ids   = array_map(static fn ($r) => (int) $r['id'], $rows);
        $tagsMap = $recycle ? [] : app(TagService::class)->getTagsForDocuments($ids);
        $publicHome = $recycle ? '' : SiteUrl::configuredPublicHome();
        foreach ($rows as &$item) {
            $tagRows = $tagsMap[(int) $item['id']] ?? [];
            $names   = array_column($tagRows, 'name');
            $item['tag_list'] = array_values(array_map(static function (array $tag): array {
                return [
                    'id'   => (int) ($tag['id'] ?? 0),
                    'name' => (string) ($tag['name'] ?? ''),
                ];
            }, $tagRows));
            $item['tags']       = implode(',', $names);
            $item['status_text'] = (int) $item['status'] === 1
                ? '已发布'
                : ((int) ($item['author_id'] ?? 0) > 0 ? '待审核' : '草稿');
            $flags = (string) ($item['attr_flags'] ?? '');
            $item['attr_labels'] = app(DocumentAttrHelper::class)->attrFlagLabels($flags);
            $item['attr_shorts'] = app(DocumentAttrHelper::class)->attrFlagShorts($flags);
            $timeRaw             = !empty($item['published_at']) ? $item['published_at'] : ($item['created_at'] ?? '');
            $item['create_time'] = $timeRaw !== '' ? AppTime::format('Y-m-d H:i', strtotime((string) $timeRaw)) : '';
            $deletedAt = (string) ($item['deleted_at'] ?? '');
            $item['deleted_time'] = $deletedAt !== '' ? AppTime::format('Y-m-d H:i', strtotime($deletedAt)) : '';
            $item['front_url']   = $recycle ? '' : app(DocumentFormatService::class)->buildPublicDocumentUrl($item, $tagRows, $publicHome);
        }
        unset($item);

        return ['list' => $rows, 'total' => $total, 'next_cursor_id' => $nextCursor, 'has_more' => $hasMore];
    }

    /**
     * 文档列表默认排除：品项详情页 / 产品 Tab 主绑定文档（非内容运营稿）
     *
     * @return list<int>
     */
    private function adminListExcludedProductDocumentIds(): array
    {
        $ids = [];
        foreach (Item::where('primary_document_id', '>', 0)->column('primary_document_id') ?: [] as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if (DbTable::exists('document_item_refs')) {
            foreach (
                Db::name('document_item_refs')
                    ->where('role', 'primary')
                    ->column('document_id') ?: [] as $raw
            ) {
                $id = (int) $raw;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        if (DbTable::exists('document_product_settings')) {
            foreach (Db::name('document_product_settings')->column('document_id') ?: [] as $raw) {
                $id = (int) $raw;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** 会员投稿未发布（待后台审核） */
    public function countMemberPendingReview(): int
    {
        return (int) Document::whereNull('deleted_at')
            ->where('status', 0)
            ->where('author_id', '>', 0)
            ->count();
    }

    /**
     * 未发布「内容稿」数量（后台草稿 + 会员投稿）。
     * 排除品项/产品主详情文档——那些不是内容运营待发布，否则门户首页会虚高「草稿文档待发布」。
     */
    public function countDraftDocuments(): int
    {
        $query = Document::whereNull('deleted_at')->where('status', 0);
        $excludeDocIds = $this->adminListExcludedProductDocumentIds();
        if ($excludeDocIds !== []) {
            $query->whereNotIn('id', $excludeDocIds);
        }

        return (int) $query->count();
    }
    /**
     * @param int $id 文档 ID
     * @return array<string, mixed>|null
     */
    public function findForAdmin(int $id): ?array
    {
        $row = Document::where('id', $id)->whereNull('deleted_at')->find()?->toArray();
        if (!$row) {
            return null;
        }
        $row['item_ids'] = app(ItemService::class)->itemIdsForDocument($id);
        $row['extra_nav_ids'] = app(SiteNavService::class)->listDocumentExtraNavIds($id);
        $row['extra_fields'] = app(\app\common\service\tag\TagCore::class)->normalizeExtraFieldDefs($row['extra_json'] ?? null);
        unset($row['extra_json']);
        $navId = (int) ($row['nav_id'] ?? 0);
        $channelDefs = app(SiteNavService::class)->listDocumentReceivableExtraFieldDefs($navId);
        $row['channel_extra_field_defs'] = $channelDefs;
        // 合并栏目可接收 schema：文档已有值保留，缺 key 用栏目定义（空值）
        foreach ($channelDefs as $key => $def) {
            if (!isset($row['extra_fields'][$key])) {
                $row['extra_fields'][$key] = [
                    'type'    => (string) ($def['type'] ?? 'text'),
                    'value'   => '',
                    'scope'   => (string) ($def['scope'] ?? 'document'),
                    'options' => is_array($def['options'] ?? null) ? $def['options'] : [],
                ];
                continue;
            }
            $cur = $row['extra_fields'][$key];
            $row['extra_fields'][$key] = [
                'type'    => (string) ($def['type'] ?? $cur['type'] ?? 'text'),
                'value'   => (string) ($cur['value'] ?? ''),
                'scope'   => (string) ($def['scope'] ?? 'document'),
                'options' => is_array($def['options'] ?? null) ? $def['options'] : [],
            ];
        }
        $row['product_images'] = app(DocumentProductImageService::class)->listOrSynthesizeFromLitpic(
            $id,
            (string) ($row['litpic'] ?? '')
        );
        $productBridge = app(\app\common\service\product\DocumentProductFacade::class);
        if ($productBridge->enabled()) {
            $groupIds = $productBridge->paramGroupIdsForDocument($id);
            $row['param_group_ids'] = $groupIds;
            $row['param_group_id']  = (int) ($groupIds[0] ?? 0);
            $row['product_layout_mode'] = $productBridge->layoutModeForDocument($id);
        }

        return $row;
    }
    /**
     * @param array<string, mixed> $data     表单数据
     * @param int                $authorId 作者用户 ID
     * @return ServiceResult
     */
    public function saveAdmin(array $data, int $authorId = 0): ServiceResult
    {
        $prepared = app(DocumentAdminInternals::class)->prepareSaveAdminPayload($data);
        if (!$prepared->isOk()) {
            return $prepared;
        }
        $payload   = $prepared->dataArray() ?? [];
        $id        = (int) ($payload['id'] ?? 0);
        $tagStr    = (string) ($payload['tagStr'] ?? '');
        $saveData  = is_array($payload['saveData'] ?? null) ? $payload['saveData'] : [];
        $attrFlags = (string) ($saveData['attr_flags'] ?? '');
        $extraNavIds = is_array($payload['extraNavIds'] ?? null)
            ? array_values(array_map('intval', $payload['extraNavIds']))
            : [];

        if ($id > 0) {
            return app(DocumentAdminInternals::class)->executeSaveAdminUpdate(
                $id,
                $saveData,
                $tagStr,
                $attrFlags,
                $data,
                $extraNavIds
            );
        }

        return app(DocumentAdminInternals::class)->executeSaveAdminCreate(
            $saveData,
            $tagStr,
            $attrFlags,
            $authorId,
            $data,
            $extraNavIds
        );
    }
    /**
     * @param int $id 文档 ID
     * @return ServiceResult
     */
    public function deleteAdmin(int $id): ServiceResult
    {
        $article = Document::where('id', $id)->whereNull('deleted_at')->find()?->toArray();
        if (!$article) {
            return ServiceResult::fail('文档不存在');
        }
        $now = AppTime::now();
        Db::transaction(function () use ($id, $now): void {
            app(MediaAssetRefService::class)->releaseDocument($id);
            Document::where('id', $id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            app(TagService::class)->detachDocumentTags($id);
            app(SiteNavService::class)->replaceDocumentExtraNavs($id, []);
        });
        $this->auditLogService->operate('删除文档', 'admin.document', ['document_id' => $id, 'title' => $article['title'] ?? '']);
        app(SiteModeService::class)->clearPageCache();
        app(StaticHtmlDispatch::class)->afterArticleDelete($id, $article);
        app(SearchIndexService::class)->removeDocument($id);

        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @param list<int> $ids
     */
    public function batchDeleteAdmin(array $ids): ServiceResult
    {
        return app(DocumentAdminBatchService::class)->batchDeleteAdmin($ids);
    }

    public function batchSetStatusAdmin(array $ids, int $status): ServiceResult
    {
        return app(DocumentAdminBatchService::class)->batchSetStatusAdmin($ids, $status);
    }

    public function batchSetTagsAdmin(array $ids, string $tagsCsv, string $mode = 'replace'): ServiceResult
    {
        return app(DocumentAdminBatchService::class)->batchSetTagsAdmin($ids, $tagsCsv, $mode);
    }

    public function batchSetAttrAdmin(array $ids, array|string $flags, string $mode): ServiceResult
    {
        return app(DocumentAdminBatchService::class)->batchSetAttrAdmin($ids, $flags, $mode);
    }

    public function batchSetSeoAdmin(
        array $ids,
        string $seoTitle = '',
        string $seoKeywords = '',
        string $seoDescription = '',
        string $titleMode = 'replace',
    ): ServiceResult {
        return app(DocumentAdminBatchService::class)->batchSetSeoAdmin($ids, $seoTitle, $seoKeywords, $seoDescription, $titleMode);
    }

    public function updateStatusAdmin(int $id, int $status): ServiceResult
    {
        $res = $this->batchSetStatusAdmin([$id], $status);
        if (!$res->isOk()) {
            return $res;
        }
        $status = $status === 1 ? 1 : 0;
        return ServiceResult::ok(['status' => $status], $status === 1 ? '已发布' : '已转为草稿');
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return list<array<string, mixed>>
     */
    public function enrichListForView(array $list): array
    {
        return app(DocumentFormatService::class)->enrichListForView($list);
    }

    public function getAdjacentPublic(int $id): array
    {
        return app(DocumentPublicService::class)->getAdjacentPublic($id);
    }

    public function getRelatedPublic(int $id, int $limit = QueryLimit::RELATED_DOCS): array
    {
        return app(DocumentPublicService::class)->getRelatedPublic($id, $limit);
    }

    public function formatForApi(array $row, bool $detail = false, ?array $prefetchedTags = null): array
    {
        return app(DocumentFormatService::class)->formatForApi($row, $detail, $prefetchedTags);
    }

    /**
     * @return array{list:list<array<string,mixed>>,total:int}
     */
    public function listForMemberAuthor(int $userId, int $page = 1, int $limit = 15): array
    {
        if ($userId < 1) {
            return ['list' => [], 'total' => 0];
        }
        $page  = max(1, $page);
        $limit = min(max($limit, 1), 50);
        $query = Document::where('author_id', $userId)->whereNull('deleted_at');
        $total = (int) $query->count();
        $rows  = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $ids   = array_map(static fn ($r) => (int) $r['id'], $rows);
        $tagsMap = app(TagService::class)->getTagsForDocuments($ids);
        foreach ($rows as &$item) {
            $item['status_text'] = (int) ($item['status'] ?? 0) === 1 ? '已发布' : '待审核';
            $timeRaw             = !empty($item['published_at']) ? $item['published_at'] : ($item['created_at'] ?? '');
            $item['create_time'] = $timeRaw !== '' ? AppTime::format('Y-m-d H:i', strtotime((string) $timeRaw)) : '';
            $docId               = (int) ($item['id'] ?? 0);
            $item['front_url']   = (int) ($item['status'] ?? 0) === 1
                ? app(DocumentFormatService::class)->buildPublicDocumentUrl($item, $tagsMap[$docId] ?? null)
                : '';
            $item['edit_url']    = SiteUrl::memberDocumentEdit($docId);
        }
        unset($item);

        return ['list' => $rows, 'total' => $total];
    }

    /** @return ServiceResult */
    public function saveForMember(array $data, int $userId): ServiceResult
    {
        if ($userId < 1) {
            return ServiceResult::fail('请先登录');
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $cur = $this->findForAdmin($id);
            if ($cur === null || (int) ($cur['author_id'] ?? 0) !== $userId) {
                return ServiceResult::fail('无权编辑该文章');
            }
        }
        // 会员投稿须挂真分类（待审 status=0 仍要 nav_id）
        $navId = max(0, (int) ($data['nav_id'] ?? 0));
        if ($navId < 1) {
            return ServiceResult::fail('请选择栏目（投稿必须挂在栏目上）');
        }
        if (!app(\app\common\service\site\SiteNavService::class)->isContentCategoryId($navId)) {
            return ServiceResult::fail('所选分类不可挂载文档（请选文章/产品分类）');
        }
        $data['status'] = 0;
        $profile = app(MemberService::class)->profile($userId);
        if (is_array($profile)) {
            $nick = trim((string) ($profile['nickname'] ?? ''));
            $user = trim((string) ($profile['username'] ?? ''));
            if (trim((string) ($data['author_name'] ?? '')) === '') {
                $data['author_name'] = $nick !== '' ? $nick : $user;
            }
        }

        $result = $this->saveAdmin($data, $userId);
        if ($result->isOk() && (int) ($data['status'] ?? 0) === 0) {
            return ServiceResult::ok($result->dataArray(), '已提交，待管理员审核');
        }

        return $result;
    }

}
