<?php
declare(strict_types=1);

namespace weapp\doc_comment\service;


use weapp\doc_comment\model\WeappDocComment;
use app\common\service\weapp\WeappDocumentGateway;
use app\common\service\weapp\WeappFrontGateway;
use app\common\service\weapp\WeappSupportGateway;
use app\common\service\weapp\WeappTagGateway;

/** 站点咨询帖（/faq 用户区）：一文档一主题 + 评论跟帖 */
final class CommentHubService
{
    public const TAG_SLUG = 'community-ask';

    public static function isActive(): bool
    {
        return CommentService::isActive();
    }

    /**
     * @return mixed
     */
    public static function createThread(string $title, string $content)
    {
        if (!self::isActive()) {
            return app(WeappSupportGateway::class)->resultFail('comment_disabled');
        }

        $member = app(WeappFrontGateway::class)->frontCurrent();
        if ($member === null) {
            return app(WeappSupportGateway::class)->resultFail('login_required');
        }

        $title = trim($title);
        $content = trim($content);
        if ($title === '' || mb_strlen($title) > 200) {
            return app(WeappSupportGateway::class)->resultFail('标题须为 1~200 字');
        }
        if ($content === '' || mb_strlen($content) > 5000) {
            return app(WeappSupportGateway::class)->resultFail('问题描述须为 1~5000 字');
        }

        $perm = CommentService::permissionForCurrent();
        if (!$perm['allowed']) {
            return app(WeappSupportGateway::class)->resultFail((string) ($perm['msg'] ?? 'forbidden'));
        }

        $navId = self::resolveCommunityNavId();
        if ($navId < 1) {
            return app(WeappSupportGateway::class)->resultFail(
                '社区提问栏目未就绪：请先在文档栏目下发布过 community-ask 内容，或挂好可投稿栏目'
            );
        }

        $authorId = (int) ($member['id'] ?? 0);
        $htmlName = self::buildHtmlName($title);
        $bodyHtml = '<p>' . nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) . '</p>';
        $docGw    = app(WeappDocumentGateway::class);

        // 真源：会员投稿管线（待审 + 必挂 nav · AD-031）；禁 documentSaveAdmin 直发
        $save = $docGw->documentSaveForMember([
            'title'     => $title,
            'html_name' => $htmlName,
            'content'   => $bodyHtml,
            'nav_id'    => $navId,
            'tags'      => self::TAG_SLUG,
            'summary'   => mb_substr(strip_tags($content), 0, 160),
        ], $authorId);

        if (!$save->isOk()) {
            return app(WeappSupportGateway::class)->resultFail((string) ($save->message() ?? 'create_failed'));
        }

        $documentId = (int) ($save->dataArray()['id'] ?? 0);
        if ($documentId < 1) {
            return app(WeappSupportGateway::class)->resultFail('create_failed');
        }

        $needReview = $perm['need_review'];
        $commentRes = CommentService::submit([
            'document_id' => $documentId,
            'parent_id'   => 0,
            'content'     => $content,
        ]);
        if (!$commentRes->isOk()) {
            // 文档写口已单独 commit：跟帖失败则软删孤儿稿，禁半成功
            $now = app(WeappSupportGateway::class)->appTimeNow();
            $docGw->documentSoftDelete($documentId, $now);
            app(WeappSupportGateway::class)->kernelOpsLog('comment_hub_thread_orphan_rolled_back', [
                'document_id' => $documentId,
                'msg'         => (string) ($commentRes->message() ?? ''),
            ]);

            return app(WeappSupportGateway::class)->resultFail((string) ($commentRes->message() ?? 'comment_failed'));
        }

        // 待审稿无前台详情 URL：回 FAQ 社区 Tab，避免跳空白页
        $faqUrl = app(WeappSupportGateway::class)->siteUrlPageByTpl('faq');
        $url    = $faqUrl !== '' ? $faqUrl . (str_contains($faqUrl, '?') ? '&' : '?') . 'tab=community' : '/faq?tab=community';

        return app(WeappSupportGateway::class)->resultOk(
            ['document_id' => $documentId, 'url' => $url, 'pending' => 1],
            $needReview ? 'pending_review' : 'ok'
        );
    }

    /** @return array{list:list<array<string,mixed>>,total:int} */
    public static function listThreads(int $page = 1, int $limit = 30, string $sort = 'latest'): array
    {
        if (!self::isActive()) {
            return ['list' => [], 'total' => 0];
        }

        $page  = max(1, $page);
        $limit = max(1, min(50, $limit));

        $result = app(WeappDocumentGateway::class)->documentListPublic([
            'page'  => $page,
            'limit' => $limit,
            'tags'  => self::TAG_SLUG,
            'sort'  => $sort === 'hot' ? 'click_desc' : 'published_at_desc',
        ]);

        $list = [];
        foreach ($result['list'] as $row) {
            $list[] = self::formatThreadRow($row);
        }

        return ['list' => $list, 'total' => (int) ($result['total'] ?? 0)];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function formatThreadRow(array $row): array
    {
        $documentId = (int) ($row['id'] ?? 0);
        $stats      = self::commentStats($documentId);
        $tag        = app(WeappTagGateway::class)->tagPrimaryFromDocument($row);

        return [
            'document_id'    => $documentId,
            'title'          => (string) ($row['title'] ?? ''),
            'summary'        => (string) ($row['summary'] ?? $row['excerpt'] ?? ''),
            'url'            => (string) ($row['url'] ?? app(WeappSupportGateway::class)->siteUrlDocument($documentId, (string) ($row['html_name'] ?? ''))),
            'views'          => (int) ($row['click'] ?? 0),
            'replies'        => $stats['replies'],
            'last_user'      => $stats['last_user'],
            'last_at'        => $stats['last_at'],
            'best_answer'    => $stats['best_answer'],
            'best_user'      => $stats['best_user'],
            'tag_name'       => (string) ($tag['name'] ?? '大家在问'),
            'tag_url'        => app(WeappSupportGateway::class)->siteUrlTag(self::TAG_SLUG),
            'published_date' => (string) ($row['published_date'] ?? ''),
        ];
    }

    /** @return array{replies:int,last_user:string,last_at:string,best_answer:string,best_user:string} */
    public static function commentStats(int $documentId): array
    {
        if ($documentId < 1) {
            return ['replies' => 0, 'last_user' => '', 'last_at' => '', 'best_answer' => '', 'best_user' => ''];
        }

        $replies = (int) WeappDocComment::where('document_id', $documentId)
            ->where('status', CommentService::STATUS_APPROVED)
            ->count();

        $last = WeappDocComment::where('document_id', $documentId)
            ->where('status', CommentService::STATUS_APPROVED)
            ->order('id', 'desc')
            ->find();

        $best = WeappDocComment::where('document_id', $documentId)
            ->where('status', CommentService::STATUS_APPROVED)
            ->where('parent_id', '>', 0)
            ->order(['like_count' => 'desc', 'id' => 'asc'])
            ->find();

        return [
            'replies'     => max(0, $replies - 1),
            'last_user'   => $last !== null ? (string) ($last['username'] ?? '') : '',
            'last_at'     => $last !== null ? (string) ($last['updated_at'] ?? $last['created_at'] ?? '') : '',
            'best_answer' => $best !== null
                ? app(WeappSupportGateway::class)->htmlSanitizePlainText((string) ($best['content'] ?? ''), 200)
                : '',
            'best_user'   => $best !== null ? (string) ($best['username'] ?? '') : '',
        ];
    }

    /**
     * 拉取文档下全部已通过评论（前台树形渲染）
     *
     * @return mixed
     */
    public static function listTreePublic(int $documentId)
    {
        if (!CommentService::isActive() || $documentId < 1) {
            return app(WeappSupportGateway::class)->resultFail('comment_disabled');
        }

        $rows = WeappDocComment::where('document_id', $documentId)
            ->where('status', CommentService::STATUS_APPROVED)
            ->order(['parent_id' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();

        $formatted = CommentService::formatPublicRowsPublic($rows);

        return app(WeappSupportGateway::class)->resultOk($formatted);
    }

    /** 从 community-ask 标签下已有稿推断可投稿栏目（禁硬编码 nav id） */
    private static function resolveCommunityNavId(): int
    {
        $tag = app(WeappTagGateway::class)->tagFindBySlug(self::TAG_SLUG);
        $tagId = (int) ($tag['id'] ?? 0);
        if ($tagId < 1) {
            return 0;
        }
        $docGw = app(WeappDocumentGateway::class);
        $counts = [];
        foreach (array_slice($docGw->documentIdsByTagId($tagId), 0, 50) as $docId) {
            $navId = $docGw->documentPrimaryNavId((int) $docId);
            if ($navId < 1) {
                continue;
            }
            $counts[$navId] = ($counts[$navId] ?? 0) + 1;
        }
        if ($counts === []) {
            return 0;
        }
        arsort($counts);
        $top = (int) array_key_first($counts);

        return $top > 0 ? $top : 0;
    }

    private static function buildHtmlName(string $title): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9\x{4e00}-\x{9fa5}]+/u', '-', $title) ?? '', '-'));
        if ($base === '') {
            $base = 'ask';
        }
        $base = mb_substr($base, 0, 40);
        $suffix = substr(md5($title . microtime(true)), 0, 6);

        return 'ask-' . $base . '-' . $suffix;
    }
}
