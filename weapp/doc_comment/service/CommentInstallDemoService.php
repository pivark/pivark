<?php
declare(strict_types=1);

namespace weapp\doc_comment\service;

use app\common\service\weapp\WeappConfigGateway;
use app\common\service\weapp\WeappInstallDemoService;
use think\facade\Db;

/** 评论插件：安装向导首次装插件时的默认演示数据 */
final class CommentInstallDemoService
{
    public static function seed(): void
    {
        $cfg     = app(WeappConfigGateway::class);
        $tagSlug = 'pv-demo-news';
        $tagIds  = [
            $tagSlug => $cfg->installDemoUpsertTag([
                'slug'        => $tagSlug,
                'name'        => '新闻动态',
                'tpl'         => 'list_document_news.php',

                'nav_sort'    => 4,
                'description' => '公司新闻、行业动态与项目案例',
                'url_path'    => 'news',
            ]),
        ];
        $docId = $cfg->installDemoUpsertDocument(
            'pv-demo-news-001',
            'HY-810 通过某石化项目 FAT 验收',
            '演示评论插件：可在本篇下查看示例评论。',
            '<p>本篇为评论插件自带演示文档。</p>',
        );
        $cfg->installDemoLinkDocumentTags($docId, [$tagSlug], $tagIds);
        $cfg->installDemoAppendContentCategoryNav('news', '新闻动态', 4);
        self::seedComments($docId);
        $cfg->installDemoRefreshTagUseCounts();
    }

    private static function seedComments(int $documentId): void
    {
        if ($documentId < 1) {
            return;
        }
        if (!WeappInstallDemoService::tableReady('weapp_doc_comment_comments')) {
            return;
        }
        $now = WeappInstallDemoService::now();
        Db::name('weapp_doc_comment_comments')->where('document_id', $documentId)->delete();
        $rootId = (int) Db::name('weapp_doc_comment_comments')->insertGetId([
            'document_id' => $documentId,
            'parent_id'   => 0,
            'user_id'     => 0,
            'username'    => '张工',
            'content'     => '现场 HART 通讯调试很顺畅，文档也齐全。',
            'user_ip'     => '127.0.0.1',
            'like_count'  => 3,
            'status'      => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        if ($rootId > 0) {
            Db::name('weapp_doc_comment_comments')->insert([
                'document_id' => $documentId,
                'parent_id'   => $rootId,
                'user_id'     => 0,
                'username'    => '李工',
                'content'     => '同感，验收资料包里的接线表很清楚。',
                'user_ip'     => '127.0.0.1',
                'like_count'  => 1,
                'status'      => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            Db::name('weapp_doc_comment_comments')->insert([
                'document_id' => $documentId,
                'parent_id'   => 0,
                'user_id'     => 0,
                'username'    => '访客小陈',
                'content'     => '想了解一下交付周期，方便私信吗？',
                'user_ip'     => '127.0.0.1',
                'like_count'  => 0,
                'status'      => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }
}
