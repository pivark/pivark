<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\support\AppTime;
use think\facade\Db;

/** 官方插件安装演示：栏目/文档/导航 upsert（各 weapp/service/*InstallDemoService 复用） */
final class WeappInstallDemoSupport
{
    /** 演示缺省封面（public/uploads/demo-seed） */
    public const PREVIEW_IMAGE = '/uploads/demo-seed/preview.svg';

    public static function ensureTagGroup(): void
    {
        if ((int) Db::name('tag_groups')->where('id', 10)->value('id') > 0) {
            return;
        }
        $now = AppTime::now();
        Db::name('tag_groups')->insert([
            'id'         => 10,
            'name'       => '默认标签',
            'sort'       => 1,
            'status'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array{slug:string,name:string,tpl:string,nav_sort:int,description:string,url_path:string,litpic?:string} $row
     */
    public static function upsertTag(array $row): int
    {
        self::ensureTagGroup();
        $now   = AppTime::now();
        $slug  = trim($row['slug']);
        $litpic = trim((string) ($row['litpic'] ?? self::PREVIEW_IMAGE));
        $existing = (int) Db::name('tags')->where('slug', $slug)->value('id');
        $payload = [
            'name'        => $row['name'],
            'group_id'    => 10,
            'nav_sort'    => (int) $row['nav_sort'],
            'description' => $row['description'],
            'seo_title'   => $row['name'],
            'tpl_name'    => $row['tpl'],
            'litpic'      => $litpic,
            'url_path'    => trim($row['url_path']),
            'status'      => 1,
            'updated_at'  => $now,
        ];
        if ($existing > 0) {
            Db::name('tags')->where('id', $existing)->update($payload);

            return $existing;
        }
        $payload['slug']       = $slug;
        $payload['kind']       = 'topic';
        $payload['use_count']  = 0;
        $payload['created_at'] = $now;

        return (int) Db::name('tags')->insertGetId($payload);
    }

    public static function upsertDocument(
        string $htmlName,
        string $title,
        string $summary,
        string $content,
        string $litpic = '',
        string $attrFlags = 'has_image',
        int $click = 0,
        int $daysAgo = 3,
        string $tplName = '',
    ): int {
        $litpic = $litpic !== '' ? $litpic : self::PREVIEW_IMAGE;
        $click  = $click > 0 ? $click : random_int(100, 800);
        $pub    = date('Y-m-d H:i:s', strtotime('-' . max(0, $daysAgo) . ' days') ?: time());
        $now    = AppTime::now();
        $existing = (int) Db::name('documents')->where('html_name', $htmlName)->value('id');
        $patch = [
            'title'           => $title,
            'summary'         => $summary,
            'content'         => $content,
            'litpic'          => $litpic,
            'attr_flags'      => $attrFlags,
            'click'           => $click,
            'published_at'    => $pub,
            'seo_title'       => $title,
            'seo_description' => $summary,
            'updated_at'      => $now,
        ];
        if ($tplName !== '') {
            $patch['tpl_name'] = $tplName;
        }
        if ($existing > 0) {
            Db::name('documents')->where('id', $existing)->update($patch);

            return $existing;
        }

        return (int) Db::name('documents')->insertGetId(array_merge($patch, [
            'html_name'   => $htmlName,
            'status'      => 1,
            'author_id'   => 1,
            'author_name' => '演示作者',
            'created_at'  => $now,
        ]));
    }

    /**
     * @param list<string> $tagSlugs
     * @param array<string, int> $tagIds slug => id
     */
    public static function linkDocumentTags(int $documentId, array $tagSlugs, array $tagIds): void
    {
        if ($documentId < 1) {
            return;
        }
        $now = AppTime::now();
        foreach ($tagSlugs as $slug) {
            $slug = trim($slug);
            if ($slug === '' || !isset($tagIds[$slug])) {
                continue;
            }
            $tid = (int) $tagIds[$slug];
            $exists = (int) Db::name('document_tags')
                ->where('document_id', $documentId)
                ->where('tag_id', $tid)
                ->count();
            if ($exists === 0) {
                Db::name('document_tags')->insert([
                    'document_id' => $documentId,
                    'tag_id'      => $tid,
                    'created_at'  => $now,
                ]);
            }
        }
    }

    /** 演示站补真分类栏目（route + url_path）；禁再写 nav_type=tag */
    public static function appendContentCategoryNav(string $urlPath, string $title, int $sort): void
    {
        $urlPath = strtolower(trim($urlPath, '/'));
        if ($urlPath === '') {
            return;
        }
        $exists = (int) Db::name('site_nav')->where('url_path', $urlPath)->count();
        if ($exists < 1) {
            $exists = (int) Db::name('site_nav')
                ->where('nav_type', 'route')
                ->where('target', '/' . $urlPath)
                ->count();
        }
        if ($exists > 0) {
            return;
        }
        $now = AppTime::now();
        Db::name('site_nav')->insert([
            'parent_id'     => 0,
            'title'         => $title,
            'nav_type'      => 'route',
            'target'        => '',
            'url_path'      => $urlPath,
            'content_kind'  => 'document',
            'sort'          => $sort,
            'status'        => 1,
            'open_new_tab'  => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    public static function documentIdByHtml(string $htmlName): int
    {
        return (int) Db::name('documents')->where('html_name', $htmlName)->value('id');
    }

    /** @return list<int> */
    public static function documentIdsByTagSlug(string $slug): array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return [];
        }
        try {
            $ids = Db::name('documents')->alias('d')
                ->join('document_tags dt', 'dt.document_id = d.id')
                ->join('tags t', 't.id = dt.tag_id')
                ->where('t.slug', $slug)
                ->where('d.status', 1)
                ->whereNull('d.deleted_at')
                ->column('d.id');

            return array_values(array_unique(array_map('intval', $ids ?: [])));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<int> */
    public static function documentIdsByHtmlPrefix(string $prefix): array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return [];
        }
        try {
            $ids = Db::name('documents')
                ->where('html_name', 'like', $prefix . '%')
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->column('id');

            return array_values(array_unique(array_map('intval', $ids ?: [])));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function documentField(int $documentId, string $field): string
    {
        if ($documentId < 1) {
            return '';
        }
        $allowed = ['title' => true, 'html_name' => true, 'summary' => true];
        if (!isset($allowed[$field])) {
            return '';
        }

        return (string) (Db::name('documents')->where('id', $documentId)->value($field) ?: '');
    }

    public static function refreshTagUseCounts(): void
    {
        foreach (Db::name('tags')->column('id') as $tagId) {
            $tagId = (int) $tagId;
            if ($tagId < 1) {
                continue;
            }
            $count = (int) Db::name('document_tags')->where('tag_id', $tagId)->count();
            Db::name('tags')->where('id', $tagId)->update(['use_count' => $count]);
        }
    }
}
