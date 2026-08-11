<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\seo;

use app\common\model\Document;
use app\common\model\SitePage;
use app\common\model\Tag;
use app\common\service\tag\TagService;
use app\common\support\AppTime;
use app\common\support\HtmlSanitizer;

/** 批量补全 documents / site_pages / tags 的 SEO 关键词与描述（仅填空） */
final class SeoMetadataBackfillService
{

    public function __construct(
        private readonly SeoExcerpt $seoExcerpt,
        private readonly TagService $tagService,
    ) {
    }

    /**
     * @return array{documents:int,site_pages:int,tags:int,skipped:int}
     */
    public function backfillAll(bool $dryRun = false): array
    {
        $docs  = $this->backfillDocuments($dryRun);
        $pages = $this->backfillSitePages($dryRun);
        $tags  = $this->backfillTags($dryRun);

        return [
            'documents'  => $docs['updated'],
            'site_pages' => $pages['updated'],
            'tags'       => $tags['updated'],
            'skipped'    => $docs['skipped'] + $pages['skipped'] + $tags['skipped'],
        ];
    }

    /**
     * @return array{updated:int,skipped:int}
     */
    public function backfillDocuments(bool $dryRun = false): array
    {
        $rows = Document::whereNull('deleted_at')
            ->where(function ($q): void {
                $q->where('seo_keywords', '')->whereOr('seo_description', '');
            })
            ->field('id,title,subtitle,content,summary,seo_keywords,seo_description')
            ->select()
            ->toArray();

        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $tagNames = $this->tagNamesForDocument($id);
            $derived  = $this->seoExcerpt->deriveForDocument([
                'title'    => (string) ($row['title'] ?? ''),
                'subtitle' => (string) ($row['subtitle'] ?? ''),
                'content'  => (string) ($row['content'] ?? ''),
                'tags'     => $tagNames,
            ]);
            $patch = $this->buildEmptyOnlyPatch($row, $derived, ['seo_keywords', 'seo_description']);
            if ($patch === []) {
                $skipped++;
                continue;
            }
            if (!$dryRun) {
                $patch['updated_at'] = AppTime::now();
                Document::where('id', $id)->whereNull('deleted_at')->update($patch);
            }
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @return array{updated:int,skipped:int}
     */
    public function backfillSitePages(bool $dryRun = false): array
    {
        $rows = SitePage::where('status', 1)
            ->where(function ($q): void {
                $q->where('seo_keywords', '')->whereOr('seo_description', '');
            })
            ->field('id,path,title,content,seo_title,seo_keywords,seo_description')
            ->select()
            ->toArray();

        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $derived = $this->deriveForSiteEntity($row);
            $patch   = $this->buildEmptyOnlyPatch($row, $derived, ['seo_keywords', 'seo_description']);
            if ($patch === []) {
                $skipped++;
                continue;
            }
            if (!$dryRun) {
                $patch['updated_at'] = AppTime::now();
                SitePage::where('id', (int) ($row['id'] ?? 0))->update($patch);
            }
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @return array{updated:int,skipped:int}
     */
    public function backfillTags(bool $dryRun = false): array
    {
        $rows = Tag::where(function ($q): void {
            $q->where('seo_keywords', '')->whereOr('seo_description', '');
        })
            ->field('id,slug,name,description,seo_title,seo_keywords,seo_description')
            ->select()
            ->toArray();

        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $derived = $this->deriveForSiteEntity([
                'title'           => (string) ($row['name'] ?? ''),
                'seo_description' => (string) ($row['seo_description'] ?? $row['description'] ?? ''),
                'content'           => (string) ($row['description'] ?? ''),
                'path'            => (string) ($row['slug'] ?? ''),
            ]);
            $patch = $this->buildEmptyOnlyPatch($row, $derived, ['seo_keywords', 'seo_description']);
            if ($patch === []) {
                $skipped++;
                continue;
            }
            if (!$dryRun) {
                $patch['updated_at'] = AppTime::now();
                Tag::where('id', (int) ($row['id'] ?? 0))->update($patch);
            }
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{seo_description:string,seo_keywords:string}
     */
    private function deriveForSiteEntity(array $row): array
    {
        $title       = trim((string) ($row['title'] ?? $row['seo_title'] ?? ''));
        $description = trim((string) ($row['seo_description'] ?? ''));
        $content     = (string) ($row['content'] ?? '');
        $path        = trim((string) ($row['path'] ?? ''));
        $tags        = array_values(array_filter([$path !== '' ? $path : null]));

        $html = $content;
        if ($description !== '' && $html === '') {
            $html = '<p>' . htmlspecialchars($description, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
        }

        $derived = $this->seoExcerpt->deriveForDocument([
            'title'    => $title,
            'subtitle' => '',
            'content'  => $html,
            'tags'     => $tags,
        ]);

        if ($description !== '') {
            $derived['seo_description'] = HtmlSanitizer::cleanPlainText($description, 500);
        }

        return [
            'seo_description' => $derived['seo_description'],
            'seo_keywords'    => $derived['seo_keywords'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $derived
     * @param list<string> $fields
     * @return array<string, string>
     */
    private function buildEmptyOnlyPatch(array $row, array $derived, array $fields): array
    {
        $patch = [];
        foreach ($fields as $field) {
            $cur = trim((string) ($row[$field] ?? ''));
            if ($cur !== '') {
                continue;
            }
            $val = HtmlSanitizer::cleanPlainText((string) ($derived[$field] ?? ''), $field === 'seo_keywords' ? 255 : 500);
            if ($val !== '') {
                $patch[$field] = $val;
            }
        }

        return $patch;
    }

    /**
     * @return list<string>
     */
    private function tagNamesForDocument(int $documentId): array
    {
        $tags = $this->tagService->getTagsForDocument($documentId);
        $out  = [];
        foreach ($tags as $tag) {
            $name = trim((string) (is_array($tag) ? ($tag['name'] ?? '') : ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }
}
