<?php
/**
 * 元舟 PivArk — www lane 社区咨询示例评论种子（weapp SSOT）
 */
declare(strict_types=1);

namespace weapp\doc_comment\service;

use app\common\service\weapp\WeappSupportGateway;
use think\facade\Db;

final class CommentWwwSeedService
{
    public static function purgeForDocument(int $documentId): void
    {
        if ($documentId < 1) {
            return;
        }
        Db::name('weapp_doc_comment_comments')->where('document_id', $documentId)->delete();
    }

    /**
     * @param list<array<string,mixed>> $comments
     */
    public static function seedComments(int $documentId, array $comments): void
    {
        if ($documentId < 1 || $comments === []) {
            return;
        }

        $now = app(WeappSupportGateway::class)->appTimeNow();
        $rootId = 0;
        foreach ($comments as $i => $comment) {
            if (!is_array($comment)) {
                continue;
            }
            $parentId = $rootId;
            if (($comment['reply_to'] ?? '') === 'root' && $rootId > 0) {
                $parentId = $rootId;
            } elseif (($comment['reply_to'] ?? '') === 'prev' && $rootId > 0) {
                $parentId = $rootId;
            }
            $id = (int) Db::name('weapp_doc_comment_comments')->insertGetId([
                'document_id' => $documentId,
                'parent_id'   => $i === 0 ? 0 : $parentId,
                'user_id'     => 0,
                'username'    => (string) ($comment['user'] ?? '用户'),
                'content'     => (string) ($comment['content'] ?? ''),
                'user_ip'     => '127.0.0.1',
                'like_count'  => (int) ($comment['likes'] ?? 0),
                'status'      => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            if ($i === 0) {
                $rootId = $id;
            }
        }
    }
}
