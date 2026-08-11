<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\bulk;

use app\common\model\Document;
use app\common\model\DocumentTag;
use app\common\model\SitePage;
use app\common\service\content\ContentSearchService;
use app\common\service\document\DocumentAttrFlagIndexService;
use app\common\support\ParseIds;
use app\common\support\QueryLimit;
use think\db\Query;

/** 批量查找替换：文档 + 可选单页，预览/分批执行/快照/审计 */

/** 筛选与数据加载 */
class ContentBulkReplaceQuery
{

public function optionsFromRequest(array $post): array
    {
        $fields = $post['fields'] ?? \app\common\service\ContentBulkReplaceService::DEFAULT_FIELDS;
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            $fields  = is_array($decoded) ? $decoded : \app\common\service\ContentBulkReplaceService::DEFAULT_FIELDS;
        }
        if (!is_array($fields) || $fields === []) {
            $fields = \app\common\service\ContentBulkReplaceService::DEFAULT_FIELDS;
        }
        $allowed = array_keys(\app\common\service\ContentBulkReplaceService::DOCUMENT_FIELDS);
        $fields  = array_values(array_unique(array_filter(
            array_map('strval', $fields),
            static fn (string $f): bool => in_array($f, $allowed, true)
        )));
        if ($fields === []) {
            $fields = \app\common\service\ContentBulkReplaceService::DEFAULT_FIELDS;
        }

        $rules = app(ContentBulkReplaceRules::class)->normalizeRules([
            'find'    => (string) ($post['find'] ?? ''),
            'replace' => (string) ($post['replace'] ?? ''),
            'rules'   => $post['rules'] ?? [],
        ]);

        return [
            'filter' => [
                'keyword'            => trim((string) ($post['keyword'] ?? '')),
                'tags'               => app(ContentBulkReplaceRules::class)->normalizeTagNames($post['tags'] ?? $post['tag'] ?? []),
                'attrs'              => app(ContentBulkReplaceRules::class)->normalizeAttrKeys($post['attrs'] ?? $post['attr'] ?? []),
                'status'             => $this->normalizeStatus((string) ($post['status'] ?? 'all')),
                'document_ids'       => ParseIds::fromMixed($post['document_ids'] ?? []),
                'include_recycle'    => (int) ($post['include_recycle'] ?? 0) === 1,
                'include_site_pages' => (int) ($post['include_site_pages'] ?? 0) === 1,
            ],
            'rules'       => $rules,
            'fields'      => $fields,
            'options'     => [
                'case_insensitive' => (int) ($post['case_insensitive'] ?? 0) === 1,
                'whole_word'       => (int) ($post['whole_word'] ?? 0) === 1,
                'regex'            => (int) ($post['regex'] ?? 0) === 1,
                'links_only'       => (int) ($post['links_only'] ?? 0) === 1,
                'sanitize_after'   => (int) ($post['sanitize_after'] ?? 1) === 1,
                'dry_run'          => (int) ($post['dry_run'] ?? 0) === 1,
                'stats_only'       => (int) ($post['stats_only'] ?? 0) === 1,
                'export_snapshot'  => (int) ($post['export_snapshot'] ?? 0) === 1,
            ],
            'batch_page' => max(1, (int) ($post['batch_page'] ?? 1)),
        ];
    }

public function applyBulkDocumentFilters(Query $query, array $filter): void
    {
        $keyword = trim((string) ($filter['keyword'] ?? ''));
        if ($keyword !== '') {
            app(ContentSearchService::class)->applyArticleKeyword($query, $keyword, true);
        }

        $tags = app(ContentBulkReplaceRules::class)->normalizeTagNames($filter['tags'] ?? $filter['tag'] ?? []);
        if ($tags !== []) {
            $ids = DocumentTag::alias('at')
                ->join('tags t', 't.id = at.tag_id')
                ->whereIn('t.name', $tags)
                ->column('at.document_id');
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $query->whereIn('id', $ids !== [] ? $ids : [0]);
        }

        $attrs = app(ContentBulkReplaceRules::class)->normalizeAttrKeys($filter['attrs'] ?? $filter['attr'] ?? []);
        if ($attrs !== []) {
            app(DocumentAttrFlagIndexService::class)->applyAnyFlagsFilter($query, $attrs);
        }
    }

public function normalizeStatus(string $status): string
    {
        return match ($status) {
            'published', 'draft' => $status,
            default             => 'all',
        };
    }

public function loadDocumentRows(array $filter): \Generator
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($filter['document_ids'] ?? [])))));
        $fieldList = implode(',', array_merge(['id', 'title'], array_keys(\app\common\service\ContentBulkReplaceService::DOCUMENT_FIELDS)));

        if ($ids !== []) {
            $query = Document::whereIn('id', $ids);
            if (!($filter['include_recycle'] ?? false)) {
                $query->whereNull('deleted_at');
            }
            foreach ($query->field($fieldList)->select()->toArray() as $row) {
                yield $row;
            }

            return;
        }

        $query = Document::query();
        if (!($filter['include_recycle'] ?? false)) {
            $query->whereNull('deleted_at');
        }

        $status = (string) ($filter['status'] ?? 'all');
        if ($status === 'published') {
            $query->where('status', 1);
        } elseif ($status === 'draft') {
            $query->where('status', 0);
        }

        $this->applyBulkDocumentFilters($query, $filter);

        foreach ($query->field($fieldList)->order('id', 'asc')->limit(\app\common\service\ContentBulkReplaceService::MAX_SCAN)->cursor() as $row) {
            yield $row->toArray();
        }
    }

public function loadSitePageRows(): \Generator
    {
        foreach (SitePage::field('id,title,content')->order('id', 'asc')->limit(QueryLimit::ADMIN_UNBOUNDED)->cursor() as $row) {
            yield $row->toArray();
        }
    }
}
