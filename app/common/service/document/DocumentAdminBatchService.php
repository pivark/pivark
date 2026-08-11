<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 文档后台批量操作（状态/标签/属性/SEO）
 */
declare(strict_types=1);

namespace app\common\service\document;

use app\common\support\ServiceResult;

use app\common\model\Document;
use app\common\service\audit\AuditLogService;
use app\common\service\media\MediaAssetRefService;
use app\common\service\search\SearchIndexService;
use app\common\service\seo\SitemapService;
use app\common\service\site\SiteModeService;
use app\common\service\static\StaticHtmlDispatch;
use app\common\service\tag\TagService;
use app\common\support\HtmlSanitizer;
use app\common\support\AppTime;
use think\facade\Db;

class DocumentAdminBatchService
{

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function batchSetSeoAdmin(
        array $ids,
        string $seoTitle = '',
        string $seoKeywords = '',
        string $seoDescription = '',
        string $titleMode = 'replace',
    ): ServiceResult {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ServiceResult::fail('请选择文章');
        }
        $seoTitle       = HtmlSanitizer::cleanPlainText($seoTitle, 200);
        $seoKeywords    = HtmlSanitizer::cleanPlainText($seoKeywords, 500);
        $seoDescription = HtmlSanitizer::cleanPlainText($seoDescription, 500);
        $titleMode      = $titleMode === 'from_title' ? 'from_title' : 'replace';
        if ($seoTitle === '' && $seoKeywords === '' && $seoDescription === '' && $titleMode !== 'from_title') {
            return ServiceResult::fail('请填写 SEO 字段或选择「SEO 标题沿用文档标题」');
        }

        $now   = AppTime::now();
        $count = 0;
        Db::transaction(function () use ($ids, $seoTitle, $seoKeywords, $seoDescription, $titleMode, $now, &$count): void {
            $articles = $this->loadActiveDocumentsByIds($ids);
            $validIds = [];
            foreach ($ids as $id) {
                if (isset($articles[$id])) {
                    $validIds[] = $id;
                }
            }
            if ($validIds === []) {
                return;
            }

            if ($titleMode === 'from_title') {
                foreach ($validIds as $id) {
                    $article = $articles[$id];
                    $payload = [
                        'updated_at' => $now,
                        'seo_title'  => HtmlSanitizer::cleanPlainText((string) ($article['title'] ?? ''), 200),
                    ];
                    if ($seoKeywords !== '') {
                        $payload['seo_keywords'] = $seoKeywords;
                    }
                    if ($seoDescription !== '') {
                        $payload['seo_description'] = $seoDescription;
                    }
                    Document::where('id', $id)->update($payload);
                }
                $count = count($validIds);

                return;
            }

            $payload = ['updated_at' => $now];
            if ($seoTitle !== '') {
                $payload['seo_title'] = $seoTitle;
            }
            if ($seoKeywords !== '') {
                $payload['seo_keywords'] = $seoKeywords;
            }
            if ($seoDescription !== '') {
                $payload['seo_description'] = $seoDescription;
            }
            Document::whereIn('id', $validIds)->whereNull('deleted_at')->update($payload);
            $count = count($validIds);
        });

        if ($count < 1) {
            return ServiceResult::fail('没有可更新的文章');
        }

        $this->auditLogService->operate('批量设置 SEO', 'admin.document', ['ids' => $ids, 'count' => $count]);
        $this->afterBatchDocumentChange($ids, 'edit');

        return ServiceResult::ok(['count' => $count], "已更新 {$count} 篇文章 SEO");
    }

    public function batchDeleteAdmin(array $ids): ServiceResult
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ServiceResult::fail('请选择文章');
        }

        $now   = AppTime::now();
        $count = 0;
        $deletedRows = [];
        Db::transaction(function () use ($ids, $now, &$count, &$deletedRows): void {
            $articles = $this->loadActiveDocumentsByIds($ids);
            $validIds = [];
            foreach ($ids as $id) {
                $article = $articles[$id] ?? null;
                if (!$article) {
                    continue;
                }
                app(MediaAssetRefService::class)->releaseDocument($id);
                app(TagService::class)->detachDocumentTags($id);
                $validIds[]    = $id;
                $deletedRows[] = $article;
            }
            if ($validIds === []) {
                return;
            }
            Document::whereIn('id', $validIds)->whereNull('deleted_at')->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
            $count = count($validIds);
        });

        if ($count < 1) {
            return ServiceResult::fail('没有可删除的文章');
        }

        $this->auditLogService->operate('批量删除文档', 'admin.document', ['ids' => $ids, 'count' => $count]);
        app(SiteModeService::class)->clearPageCache();
        $deletedIds = [];
        $rowsById   = [];
        foreach ($deletedRows as $row) {
            $docId = (int) ($row['id'] ?? 0);
            if ($docId < 1) {
                continue;
            }
            $deletedIds[]       = $docId;
            $rowsById[$docId] = $row;
        }
        if ($deletedIds !== []) {
            app(StaticHtmlDispatch::class)->afterBatchArticleDelete($deletedIds, $rowsById);
            app(SearchIndexService::class)->removeDocumentsByIds($deletedIds);
        }

        return ServiceResult::ok(['count' => $count], "已删除 {$count} 篇文章");
    }

    /**
     * @param list<int> $ids
     * @param int       $status 0草稿 1发布
     * @return ServiceResult
     */
    public function batchSetStatusAdmin(array $ids, int $status): ServiceResult
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ServiceResult::fail('请选择文章');
        }
        $status = $status === 1 ? 1 : 0;
        $now    = AppTime::now();
        $count  = 0;

        Db::transaction(function () use ($ids, $status, $now, &$count): void {
            $articles = $this->loadActiveDocumentsByIds($ids);
            $validIds = [];
            $publishIds = [];
            foreach ($ids as $id) {
                $article = $articles[$id] ?? null;
                if (!$article) {
                    continue;
                }
                $validIds[] = $id;
                if ($status === 1 && empty($article['published_at'])) {
                    $publishIds[] = $id;
                }
            }
            if ($validIds === []) {
                return;
            }
            Document::whereIn('id', $validIds)->whereNull('deleted_at')->update([
                'status'     => $status,
                'updated_at' => $now,
            ]);
            if ($publishIds !== []) {
                Document::whereIn('id', $publishIds)->whereNull('deleted_at')->whereNull('published_at')->update([
                    'published_at' => $now,
                ]);
            }
            $count = count($validIds);
        });

        if ($count < 1) {
            return ServiceResult::fail('没有可更新的文章');
        }

        $action = $status === 1 ? '批量发布文档' : '批量下架文章';
        $this->auditLogService->operate($action, 'admin.document', ['ids' => $ids, 'status' => $status, 'count' => $count]);
        $this->afterBatchDocumentChange($ids, $status === 1 ? 'publish' : 'edit');

        return ServiceResult::ok(['count' => $count], ($status === 1 ? '已发布' : '已下架') . " {$count} 篇文章");
    }

    /**
     * @param list<int> $ids
     * @return ServiceResult
     */
    public function batchSetTagsAdmin(array $ids, string $tagsCsv, string $mode = 'replace'): ServiceResult
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ServiceResult::fail('请选择文章');
        }
        $tagsCsv = app(TagService::class)->normalizeTagsCsv($tagsCsv);
        if ($tagsCsv === '') {
            return ServiceResult::fail('请填写标签');
        }
        $mode  = $mode === 'append' ? 'append' : 'replace';
        $now   = AppTime::now();
        $count = 0;

        Db::transaction(function () use ($ids, $tagsCsv, $mode, $now, &$count): void {
            $articles = $this->loadActiveDocumentsByIds($ids);
            $updatedIds = [];
            foreach ($ids as $id) {
                $article = $articles[$id] ?? null;
                if (!$article) {
                    continue;
                }
                $finalTags = $tagsCsv;
                if ($mode === 'append') {
                    $existing  = app(TagService::class)->tagNamesCsvForDocument($id);
                    $finalTags = app(TagService::class)->normalizeTagsCsv($existing . ',' . $tagsCsv);
                }
                app(TagService::class)->syncDocumentTags($id, $finalTags);
                $updatedIds[] = $id;
            }
            if ($updatedIds === []) {
                return;
            }
            Document::whereIn('id', $updatedIds)->whereNull('deleted_at')->update(['updated_at' => $now]);
            $count = count($updatedIds);
        });

        if ($count < 1) {
            return ServiceResult::fail('没有可更新的文章');
        }

        $this->auditLogService->operate('批量设置标签', 'admin.document', [
            'ids'   => $ids,
            'tags'  => $tagsCsv,
            'mode'  => $mode,
            'count' => $count,
        ]);
        $this->afterBatchDocumentChange($ids, 'edit');

        return ServiceResult::ok(['count' => $count], "已更新 {$count} 篇文章标签");
    }

    /**
     * @param list<int>         $ids
     * @param list<string>|string $flags
     * @param 'add'|'remove'    $mode
     * @return ServiceResult
     */
    public function batchSetAttrAdmin(array $ids, array|string $flags, string $mode): ServiceResult
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ServiceResult::fail('请选择文章');
        }
        $flags = app(DocumentAttrHelper::class)->normalizeManualAttrFlags($flags);
        if ($flags === []) {
            return ServiceResult::fail('无效属性');
        }
        $mode  = $mode === 'remove' ? 'remove' : 'add';
        $now   = AppTime::now();
        $count = 0;

        Db::transaction(function () use ($ids, $flags, $mode, $now, &$count): void {
            $articles = $this->loadActiveDocumentsByIds($ids);
            foreach ($ids as $id) {
                $article = $articles[$id] ?? null;
                if (!$article) {
                    continue;
                }
                $next = (string) ($article['attr_flags'] ?? '');
                foreach ($flags as $flag) {
                    $next = $mode === 'add'
                        ? app(DocumentAttrHelper::class)->mergeAttrFlag($next, $flag)
                        : app(DocumentAttrHelper::class)->stripAttrFlag($next, $flag);
                }
                $current = (string) ($article['attr_flags'] ?? '');
                if ($next === $current) {
                    continue;
                }
                Document::where('id', $id)->update(['attr_flags' => $next, 'updated_at' => $now]);
                app(DocumentAttrFlagIndexService::class)->sync($id, $next);
                $count++;
            }
        });

        if ($count < 1) {
            return ServiceResult::fail('没有可更新的文章');
        }

        $labels = array_map(static fn (string $flag): string => DocumentAttrHelper::ATTR_FLAG_LABELS[$flag] ?? $flag, $flags);
        $label  = implode('、', $labels);
        $verb   = $mode === 'add' ? '新增' : '删除';
        $this->auditLogService->operate('批量' . $verb . '文档属性', 'admin.document', [
            'ids'   => $ids,
            'flags' => $flags,
            'mode'  => $mode,
            'count' => $count,
        ]);
        $this->afterBatchDocumentChange($ids, 'edit');

        return ServiceResult::ok(['count' => $count], "已{$verb}属性「{$label}」共 {$count} 篇");
    }

    /**
     * @param list<int> $ids
     */
    private function afterBatchDocumentChange(array $ids, string $scene): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }
        app(SiteModeService::class)->clearPageCache();
        app(StaticHtmlDispatch::class)->afterBatchArticleChange($ids, $scene);
        app(SitemapService::class)->syncAfterContentChange(0, $scene);
        app(SearchIndexService::class)->syncDocumentsByIds($ids);
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function loadActiveDocumentsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = Document::whereIn('id', $ids)->whereNull('deleted_at')->select()->toArray();
        $map  = [];
        foreach ($rows as $row) {
            $map[(int) ($row['id'] ?? 0)] = $row;
        }

        return $map;
    }
}
