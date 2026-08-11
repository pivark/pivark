<?php
declare(strict_types=1);

namespace weapp\doc_comment\service;



use weapp\doc_comment\service\CommentConfigService;
use weapp\doc_comment\model\WeappDocComment;
use weapp\doc_comment\model\WeappDocCommentLevel;
use weapp\doc_comment\model\WeappDocCommentLike;
use app\common\service\weapp\WeappDocumentGateway;
use app\common\service\weapp\WeappEntitlementGateway;
use app\common\service\weapp\WeappFrontGateway;
use app\common\service\weapp\WeappMemberGateway;
use app\common\service\weapp\WeappPluginGateway;
use app\common\service\weapp\WeappSearchGateway;
use app\common\service\weapp\WeappSupportGateway;
use think\facade\Db;
use think\facade\Request;

/** 文档评论：列表、发表、审核、点赞、会员等级权限 */
class CommentService
{
    public const STATUS_PENDING  = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;

    public static function ensureAutoload(): void
    {
        app(WeappPluginGateway::class)->pluginRegisterAutoloadPublic('doc_comment');
    }

    public static function apiBasePath(): string
    {
        return '/api/v1/plugins/doc_comment';
    }

    public static function isActive(): bool
    {
        return app(WeappEntitlementGateway::class)->entitlementCan('doc_comment') && CommentConfigService::isOpen();
    }

    /** @return array{allowed:bool,need_review:bool,msg:string} */
    public static function permissionForCurrent(): array
    {
        $member = app(WeappFrontGateway::class)->frontCurrent();
        if ($member === null) {
            if (!CommentConfigService::guestAllowed()) {
                return ['allowed' => false, 'need_review' => false, 'msg' => 'login_required'];
            }

            return self::permissionForLevelId(0);
        }

        return self::permissionForLevelId((int) ($member['member_level_id'] ?? 0));
    }

    /** @return array{allowed:bool,need_review:bool,msg:string} */
    public static function permissionForLevelId(int $levelId): array
    {
        self::syncLevelRows();
        $row = WeappDocCommentLevel::where('member_level_id', $levelId)->find();
        if (!$row) {
            return ['allowed' => false, 'need_review' => true, 'msg' => 'level_denied'];
        }
        if ((int) ($row['can_comment'] ?? 0) !== 1) {
            return ['allowed' => false, 'need_review' => false, 'msg' => 'level_denied'];
        }

        return [
            'allowed'     => true,
            'need_review' => (int) ($row['need_review'] ?? 0) === 1,
            'msg'         => 'ok',
        ];
    }

    public static function syncLevelRows(): void
    {
        $now = app(WeappSupportGateway::class)->appTimeNow();
        $existing = WeappDocCommentLevel::column('member_level_id');
        $existingSet = array_flip(array_map('intval', $existing));

        if (!isset($existingSet[0])) {
            WeappDocCommentLevel::insert([
                'member_level_id' => 0,
                'can_comment'     => CommentConfigService::guestAllowed() ? 1 : 0,
                'need_review'     => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        } else {
            WeappDocCommentLevel::where('member_level_id', 0)->update([
                'can_comment' => CommentConfigService::guestAllowed() ? 1 : 0,
                'updated_at'  => $now,
            ]);
        }

        foreach (app(WeappMemberGateway::class)->memberLevelsActive() as $level) {
            $id = (int) ($level['id'] ?? 0);
            if ($id < 1 || isset($existingSet[$id])) {
                continue;
            }
            WeappDocCommentLevel::insert([
                'member_level_id' => $id,
                'can_comment'     => 1,
                'need_review'     => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    /**
     * @return mixed
     */
    public static function listPublic(int $documentId, int $page = 1, int $parentId = 0)
    {
        if (!self::isActive() || $documentId < 1) {
            return app(WeappSupportGateway::class)->resultFail('comment_disabled');
        }

        $pageSize = CommentConfigService::pageSize();
        $page     = max(1, $page);
        $query    = WeappDocComment::where('document_id', $documentId)
            ->where('parent_id', max(0, $parentId))
            ->where('status', self::STATUS_APPROVED);

        $total = (int) $query->count();
        $rows  = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return app(WeappSupportGateway::class)->resultList($rows, $total, ['page' => $page, 'page_size' => $pageSize]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return mixed
     */
    public static function submit(array $payload)
    {
        if (!self::isActive()) {
            return app(WeappSupportGateway::class)->resultFail('评论功能未开启');
        }

        $documentId = max(0, (int) ($payload['document_id'] ?? 0));
        $parentId   = max(0, (int) ($payload['parent_id'] ?? 0));
        $content    = trim((string) ($payload['content'] ?? ''));
        if ($documentId < 1) {
            return app(WeappSupportGateway::class)->resultFail('文档无效');
        }
        if ($content === '' || mb_strlen($content) > 2000) {
            return app(WeappSupportGateway::class)->resultFail('评论内容须为 1~2000 字');
        }

        if (!self::documentExists($documentId)) {
            return app(WeappSupportGateway::class)->resultFail('文档不存在或不可评论');
        }

        if ($parentId > 0) {
            $parent = WeappDocComment::where('id', $parentId)->where('document_id', $documentId)->find();
            if (!$parent) {
                return app(WeappSupportGateway::class)->resultFail('回复的评论不存在');
            }
        }

        $perm = self::permissionForCurrent();
        if (!$perm['allowed']) {
            return app(WeappSupportGateway::class)->resultFail((string) ($perm['msg'] ?? 'forbidden'));
        }

        $member   = app(WeappFrontGateway::class)->frontCurrent();
        $userId   = $member !== null ? (int) ($member['id'] ?? 0) : 0;
        $username = $member !== null
            ? (string) ($member['nickname'] ?? $member['username'] ?? '')
            : trim((string) ($payload['username'] ?? ''));

        if ($userId < 1) {
            if ($username === '' || mb_strlen($username) > 50) {
                return app(WeappSupportGateway::class)->resultFail('请填写昵称（50 字以内）');
            }
        }

        $filtered = self::filterSensitive($content);
        if ($filtered['blocked']) {
            return app(WeappSupportGateway::class)->resultFail('评论含有违禁词，请修改后重试');
        }

        $needReview = $perm['need_review'] || $filtered['need_review'];
        $status     = $needReview ? self::STATUS_PENDING : self::STATUS_APPROVED;
        $now        = app(WeappSupportGateway::class)->appTimeNow();

        $id = (int) WeappDocComment::insertGetId([
            'document_id' => $documentId,
            'parent_id'   => $parentId,
            'user_id'     => $userId,
            'username'    => $username,
            'content'     => $filtered['content'],
            'user_ip'     => (string) Request::ip(),
            'like_count'  => 0,
            'status'      => $status,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        return app(WeappSupportGateway::class)->resultOk(['id' => $id, 'status' => $status], $needReview ? 'pending_review' : 'ok');
    }

    /** @return mixed */
    public static function deleteOwn(int $commentId)
    {
        if ($commentId < 1) {
            return app(WeappSupportGateway::class)->resultFail('invalid_id');
        }
        $member = app(WeappFrontGateway::class)->frontCurrent();
        if ($member === null) {
            return app(WeappSupportGateway::class)->resultFail('login_required');
        }
        $row = WeappDocComment::where('id', $commentId)->find();
        if (!$row || (int) ($row['user_id'] ?? 0) !== (int) ($member['id'] ?? 0)) {
            return app(WeappSupportGateway::class)->resultFail('forbidden');
        }

        Db::startTrans();
        try {
            WeappDocComment::where('id', $commentId)->delete();
            WeappDocCommentLike::where('comment_id', $commentId)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            app(WeappSupportGateway::class)->kernelOpsLog('comment_delete_own_failed', [
                'comment_id' => $commentId,
                'msg'        => $e->getMessage(),
            ]);

            return app(WeappSupportGateway::class)->resultFail('delete_failed');
        }

        return app(WeappSupportGateway::class)->resultOk(null, 'ok');
    }

    /** @return mixed */
    public static function toggleLike(int $commentId)
    {
        if (!self::isActive() || $commentId < 1) {
            return app(WeappSupportGateway::class)->resultFail('invalid_id');
        }
        $member = app(WeappFrontGateway::class)->frontCurrent();
        if ($member === null) {
            return app(WeappSupportGateway::class)->resultFail('login_required');
        }
        $userId = (int) ($member['id'] ?? 0);
        $comment = WeappDocComment::where('id', $commentId)->where('status', self::STATUS_APPROVED)->find();
        if (!$comment) {
            return app(WeappSupportGateway::class)->resultFail('not_found');
        }

        $existing = WeappDocCommentLike::where('comment_id', $commentId)
            ->where('user_id', $userId)
            ->find();

        Db::startTrans();
        try {
            WeappDocComment::where('id', $commentId)->lock(true)->find();
            if ($existing) {
                WeappDocCommentLike::where('id', (int) $existing['id'])->delete();
                WeappDocComment::where('id', $commentId)->dec('like_count')->update();
                $liked = false;
            } else {
                WeappDocCommentLike::insert([
                    'comment_id' => $commentId,
                    'user_id'    => $userId,
                    'created_at' => app(WeappSupportGateway::class)->appTimeNow(),
                ]);
                WeappDocComment::where('id', $commentId)->inc('like_count')->update();
                $liked = true;
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            app(WeappSupportGateway::class)->kernelOpsLog('comment_like_failed', ['msg' => $e->getMessage()]);

            return app(WeappSupportGateway::class)->resultFail('like_failed');
        }

        $count = (int) WeappDocComment::where('id', $commentId)->value('like_count');

        return app(WeappSupportGateway::class)->resultOk(['liked' => $liked, 'like_count' => $count], 'ok');
    }

    /**
     * @param array<string, mixed> $filters
     * @return mixed
     */
    public static function listAdmin(array $filters = [], int $page = 1, int $pageSize = 20)
    {
        if (!app(WeappEntitlementGateway::class)->entitlementCan('doc_comment')) {
            return app(WeappSupportGateway::class)->resultFail('plugin_disabled');
        }

        $page     = max(1, $page);
        $pageSize = max(10, min(100, $pageSize));
        $query = WeappDocComment::with(['document' => static function ($documentQuery): void {
            $documentQuery->field('id,title');
        }]);

        $status = $filters['status'] ?? '';
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }
        $documentId = (int) ($filters['document_id'] ?? 0);
        if ($documentId > 0) {
            $query->where('document_id', $documentId);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('content|username', app(WeappSearchGateway::class)->searchLikePattern($keyword));
        }

        $total = (int) (clone $query)->count();
        $rows  = app(WeappSupportGateway::class)->modelRelationMapBelongsTo(
            $query->order('id', 'desc')->page($page, $pageSize)->select(),
            'document',
            ['title' => 'document_title'],
        );

        foreach ($rows as &$row) {
            $status = (int) ($row['status'] ?? 0);
            $row['status_label'] = match ($status) {
                self::STATUS_APPROVED => '已通过',
                self::STATUS_REJECTED => '已驳回',
                default               => '待审核',
            };
        }
        unset($row);

        return app(WeappSupportGateway::class)->resultList($rows, $total);
    }

    /**
     * @param list<int> $ids
     * @return mixed
     */
    public static function reviewAdmin(array $ids, int $status)
    {
        if (!app(WeappEntitlementGateway::class)->entitlementCan('doc_comment')) {
            return app(WeappSupportGateway::class)->resultFail('plugin_disabled');
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return app(WeappSupportGateway::class)->resultFail('empty_ids');
        }
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            return app(WeappSupportGateway::class)->resultFail('invalid_status');
        }

        WeappDocComment::whereIn('id', $ids)->update([
            'status'     => $status,
            'updated_at' => app(WeappSupportGateway::class)->appTimeNow(),
        ]);

        return app(WeappSupportGateway::class)->resultOk(null, 'ok');
    }

    /** @return mixed */
    public static function approveAllPendingAdmin()
    {
        if (!app(WeappEntitlementGateway::class)->entitlementCan('doc_comment')) {
            return app(WeappSupportGateway::class)->resultFail('plugin_disabled');
        }

        $updated = (int) WeappDocComment::where('status', self::STATUS_PENDING)
            ->update([
                'status'     => self::STATUS_APPROVED,
                'updated_at' => app(WeappSupportGateway::class)->appTimeNow(),
            ]);

        return app(WeappSupportGateway::class)->resultOk(['updated' => $updated], 'ok');
    }

    /** @return mixed */
    public static function deleteAdmin(int $id)
    {
        if (!app(WeappEntitlementGateway::class)->entitlementCan('doc_comment') || $id < 1) {
            return app(WeappSupportGateway::class)->resultFail('invalid_id');
        }

        Db::startTrans();
        try {
            $childIds = WeappDocComment::where('parent_id', $id)->column('id');
            $childIds = array_values(array_filter(array_map('intval', is_array($childIds) ? $childIds : [])));
            $likeIds  = array_values(array_unique(array_merge([$id], $childIds)));
            WeappDocComment::where('id', $id)->delete();
            if ($childIds !== []) {
                WeappDocComment::whereIn('id', $childIds)->delete();
            }
            WeappDocCommentLike::whereIn('comment_id', $likeIds)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            app(WeappSupportGateway::class)->kernelOpsLog('comment_delete_admin_failed', [
                'comment_id' => $id,
                'msg'        => $e->getMessage(),
            ]);

            return app(WeappSupportGateway::class)->resultFail('delete_failed');
        }

        return app(WeappSupportGateway::class)->resultOk(null, 'ok');
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return mixed
     */
    public static function saveLevelsAdmin(array $rows)
    {
        if (!app(WeappEntitlementGateway::class)->entitlementCan('doc_comment')) {
            return app(WeappSupportGateway::class)->resultFail('plugin_disabled');
        }
        self::syncLevelRows();
        $now = app(WeappSupportGateway::class)->appTimeNow();
        foreach ($rows as $row) {
            $levelId = (int) ($row['member_level_id'] ?? -1);
            if ($levelId < 0) {
                continue;
            }
            WeappDocCommentLevel::where('member_level_id', $levelId)->update([
                'can_comment' => !empty($row['can_comment']) ? 1 : 0,
                'need_review' => !empty($row['need_review']) ? 1 : 0,
                'updated_at'  => $now,
            ]);
        }

        return app(WeappSupportGateway::class)->resultOk(null, 'ok');
    }

    /** @return list<array<string,mixed>> */
    public static function levelsForAdmin(): array
    {
        self::syncLevelRows();
        $rows = WeappDocCommentLevel::order('member_level_id', 'asc')->select()->toArray();
        $levels = app(WeappMemberGateway::class)->memberLevelsActive();
        $nameMap = [0 => '游客（未登录）'];
        foreach ($levels as $level) {
            $nameMap[(int) ($level['id'] ?? 0)] = (string) ($level['name'] ?? '');
        }
        foreach ($rows as &$row) {
            $lid = (int) ($row['member_level_id'] ?? 0);
            $row['level_name'] = $nameMap[$lid] ?? ('等级#' . $lid);
        }
        unset($row);

        return $rows;
    }

    /** @return array{content:string,blocked:bool,need_review:bool} */
    public static function filterSensitive(string $content): array
    {
        $words = CommentConfigService::sensitiveWords();
        if ($words === []) {
            return ['content' => $content, 'blocked' => false, 'need_review' => false];
        }

        $mode    = CommentConfigService::sensitiveMode();
        $matched = false;
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            if (mb_stripos($content, $word) !== false) {
                $matched = true;
                if ($mode === CommentConfigService::SENSITIVE_BLOCK) {
                    return ['content' => $content, 'blocked' => true, 'need_review' => false];
                }
                if ($mode === CommentConfigService::SENSITIVE_REPLACE) {
                    $content = str_ireplace($word, str_repeat('*', mb_strlen($word)), $content);
                }
            }
        }

        if ($matched && $mode === CommentConfigService::SENSITIVE_REVIEW) {
            return ['content' => $content, 'blocked' => false, 'need_review' => true];
        }

        return ['content' => $content, 'blocked' => false, 'need_review' => false];
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     */
    public static function renderTag(array $attrs, array $pageVars, string $tpl): string
    {
        if (!self::isActive()) {
            return '';
        }

        $documentId = self::resolveDocumentId($attrs, $pageVars);
        if ($documentId < 1) {
            return '';
        }

        $tokenField = app(WeappFrontGateway::class)->frontCsrfFieldName();
        $token      = app(WeappFrontGateway::class)->frontCsrfToken();
        $pageSize   = CommentConfigService::pageSize();
        $guest      = CommentConfigService::guestAllowed() ? '1' : '0';
        $loggedIn   = app(WeappFrontGateway::class)->frontIsLoggedIn() ? '1' : '0';
        $loginUrl   = app(WeappSupportGateway::class)->siteUrlMemberLogin();

        $html = '<div class="pv-comment-widget" data-document-id="' . $documentId . '"'
            . ' data-api-base="' . htmlspecialchars(self::apiBasePath(), ENT_QUOTES) . '"'
            . ' data-page-size="' . $pageSize . '"'
            . ' data-guest="' . $guest . '"'
            . ' data-logged-in="' . $loggedIn . '"'
            . ' data-login-url="' . htmlspecialchars($loginUrl, ENT_QUOTES) . '"'
            . ' data-csrf-field="' . htmlspecialchars($tokenField, ENT_QUOTES) . '"'
            . ' data-csrf-token="' . htmlspecialchars($token, ENT_QUOTES) . '">'
            . '<div class="pv-comment-list"><div class="pv-comment-empty">加载评论中…</div></div>'
            . '<div class="pv-comment-toast" data-comment-toast hidden></div>'
            . '<div class="pv-comment-form">'
            . '<input type="text" class="pv-comment-nick" name="username" maxlength="50" placeholder="昵称（游客必填）" autocomplete="nickname"'
            . ($loggedIn === '1' ? ' hidden' : '') . ' />'
            . '<textarea class="pv-comment-input" rows="3" placeholder="写下你的评论…" maxlength="2000"></textarea>'
            . '<div class="pv-comment-form-meta"><span class="pv-comment-charcount" data-charcount>0/2000</span></div>'
            . '<div class="pv-comment-form-actions">'
            . '<button type="button" class="pv-comment-submit">发表评论</button>'
            . '<button type="button" class="pv-comment-cancel-reply" hidden>取消回复</button>'
            . '</div>'
            . '</div>'
            . '</div>';

        app(WeappFrontGateway::class)->frontAssetRegisterComment();

        return $html;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     */
    private static function resolveDocumentId(array $attrs, array $pageVars): int
    {
        foreach (['id', 'aid', 'document_id'] as $key) {
            if (isset($attrs[$key]) && (string) $attrs[$key] !== '') {
                $raw = (string) $attrs[$key];
                if (str_starts_with($raw, '$')) {
                    $varKey = ltrim($raw, '$');

                    return max(0, (int) ($pageVars[$varKey] ?? 0));
                }

                return max(0, (int) $raw);
            }
        }

        return max(0, (int) ($pageVars['document_id'] ?? ($pageVars['id'] ?? 0)));
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public static function formatPublicRowsPublic(array $rows): array
    {
        return self::formatPublicRows($rows);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private static function formatPublicRows(array $rows): array
    {
        $member = app(WeappFrontGateway::class)->frontCurrent();
        $userId = $member !== null ? (int) ($member['id'] ?? 0) : 0;
        $likedIds = [];
        if ($userId > 0 && $rows !== []) {
            $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
            $likedIds = WeappDocCommentLike::where('user_id', $userId)
                ->whereIn('comment_id', $ids)
                ->column('comment_id');
            $likedIds = array_flip(array_map('intval', $likedIds));
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $out[] = [
                'id'          => $id,
                'document_id' => (int) ($row['document_id'] ?? 0),
                'parent_id'   => (int) ($row['parent_id'] ?? 0),
                'username'    => (string) ($row['username'] ?? ''),
                'content'     => (string) ($row['content'] ?? ''),
                'like_count'  => (int) ($row['like_count'] ?? 0),
                'liked'       => isset($likedIds[$id]),
                'created_at'  => (string) ($row['created_at'] ?? ''),
                'is_mine'     => $userId > 0 && (int) ($row['user_id'] ?? 0) === $userId,
            ];
        }

        return $out;
    }

    private static function documentExists(int $documentId): bool
    {
        return app(WeappDocumentGateway::class)->documentExistsPublished($documentId);
    }

    /** @return array<string, int> */
    public static function statsAdmin(): array
    {
        return [
            'total'   => (int) WeappDocComment::count(),
            'pending' => (int) WeappDocComment::where('status', self::STATUS_PENDING)->count(),
            'approved'=> (int) WeappDocComment::where('status', self::STATUS_APPROVED)->count(),
        ];
    }
}
