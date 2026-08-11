<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\bulk;

use app\common\service\infra\RateLimitGateway;
use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\support\QueryLimit;



use app\common\service\document\satellite\DocumentAssetSyncService;
use app\common\service\static\StaticHtmlService;
use app\common\model\Document;
use app\common\model\DocumentTag;
use app\common\model\SitePage;
use think\db\Query;
use app\common\support\HtmlSanitizer;
use think\facade\Db;
use think\facade\Session;

/** 批量查找替换：文档 + 可选单页，预览/分批执行/快照/审计 */

/** 预览/执行/快照 */
class ContentBulkReplaceRunner
{

public function preview(array $filter, array $rules, array $fields, array $options = []): ServiceResult
    {
        $err = app(ContentBulkReplaceRules::class)->validateRules($rules, $options);
        if ($err !== null) {
            return ServiceResult::fail($err);
        }

        $scan = $this->scanMatches($filter, $rules, $fields, $options);
        $page = max(1, (int) ($options['preview_page'] ?? 1));
        $size = min(QueryLimit::BULK_PREVIEW_PAGE_MAX, max(QueryLimit::BULK_PREVIEW_PAGE_MIN, (int) ($options['preview_page_size'] ?? QueryLimit::BULK_PREVIEW_PAGE_DEFAULT)));
        $all  = $scan['items'];
        $totalItems = count($all);
        $slice = array_slice($all, ($page - 1) * $size, $size);

        $preview = [];
        foreach ($slice as $item) {
            if (($options['stats_only'] ?? false) === true) {
                $preview[] = [
                    'type'  => $item['type'],
                    'id'    => $item['id'],
                    'title' => $item['title'],
                    'hits'  => $item['hits'],
                ];
                continue;
            }
            $preview[] = [
                'type'     => $item['type'],
                'id'       => $item['id'],
                'title'    => $item['title'],
                'hits'     => $item['hits'],
                'snippets' => $item['snippets'] ?? [],
                'edit_url' => $item['edit_url'] ?? '',
            ];
        }

        $payload = [
            'document_count'  => $scan['document_count'],
            'site_page_count' => $scan['site_page_count'],
            'hit_count'       => $scan['hit_count'],
            'scanned'         => $scan['scanned'],
            'scan_capped'     => $scan['scan_capped'],
            'preview'         => $preview,
            'preview_page'    => $page,
            'preview_total'   => $totalItems,
            'preview_pages'   => (int) max(1, ceil($totalItems / $size)),
        ];

        if (($options['export_snapshot'] ?? false) === true && $all !== []) {
            $payload['snapshot'] = $this->buildSnapshotPayload($all, $filter, $fields, $rules);
        }

        return ServiceResult::ok($payload);
    }

public function execute(
        array $filter,
        array $rules,
        array $fields,
        array $options = [],
        int $batchPage = 1
    ): ServiceResult {
        $err = app(ContentBulkReplaceRules::class)->validateRules($rules, $options);
        if ($err !== null) {
            return ServiceResult::fail($err);
        }

        $dryRun = ($options['dry_run'] ?? false) === true;
        if ($batchPage === 1 && !$dryRun) {
            $rate = $this->checkRateLimit();
            if ($rate !== null) {
                return ServiceResult::fail($rate);
            }
        }

        $scan = $this->scanMatches($filter, $rules, $fields, $options);
        if ($scan['hit_count'] < 1) {
            return ServiceResult::fail('没有匹配项');
        }

        $items      = $scan['items'];
        $totalPages = (int) max(1, ceil(count($items) / \app\common\service\ContentBulkReplaceService::BATCH_SIZE));
        $batchPage  = min($batchPage, $totalPages);
        $slice      = array_slice($items, ($batchPage - 1) * \app\common\service\ContentBulkReplaceService::BATCH_SIZE, \app\common\service\ContentBulkReplaceService::BATCH_SIZE);

        if ($dryRun) {
            return ServiceResult::ok(['hit_count' => $scan['hit_count'], 'document_count' => $scan['document_count'], 'batch_page' => $batchPage, 'batch_total' => $totalPages, 'batch_done' => true], 'dry_run');
        }

        $replaced = 0;
        $failed   = [];
        $docTouched = 0;
        $pageTouched = 0;

        try {
            Db::transaction(function () use (
                $slice,
                $fields,
                $rules,
                $options,
                &$replaced,
                &$failed,
                &$docTouched,
                &$pageTouched
            ): void {
                foreach ($slice as $item) {
                    try {
                        $n = $this->applyItem($item, $fields, $rules, $options);
                        if ($n < 1) {
                            continue;
                        }
                        $replaced += $n;
                        if ($item['type'] === 'site_page') {
                            $pageTouched++;
                        } else {
                            $docTouched++;
                        }
                    } catch (\Throwable $e) {
                        $failed[] = [
                            'type'  => $item['type'],
                            'id'    => $item['id'],
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            });
        } catch (\Throwable $e) {
            return ServiceResult::fail('批次事务失败：' . $e->getMessage());
        }

        $done = $batchPage >= $totalPages;
        if ($done) {
            $this->finalize($filter, $rules, $scan, $options, $fields);
        }

        return ServiceResult::ok(['replaced' => $replaced, 'documents' => $docTouched, 'site_pages' => $pageTouched, 'failed' => $failed, 'batch_page' => $batchPage, 'batch_total' => $totalPages, 'batch_done' => $done, 'hit_count' => $scan['hit_count'], 'document_count' => $scan['document_count'], 'regenerate_hint' => $done
                ? '已清理页面缓存；若启用静态 HTML，请到 SEO → HTML 生成 重新批量生成。'
                : ''], $done ? '全部批次已完成' : "第 {$batchPage}/{$totalPages} 批已完成");
    }

public function checkRateLimit(): ?string
    {
        $admin = Session::get('admin_user', []);
        $uid   = (int) ($admin['id'] ?? 0);
        if ($uid < 1) {
            return null;
        }
        $gateway = app(RateLimitGateway::class);
        $blocked = $gateway->check('admin.bulk_replace', (string) $uid, ['user_id' => $uid]);
        if ($blocked !== null) {
            return (string) ($blocked->msg ?? '操作过于频繁，请稍后再执行全库替换');
        }
        $gateway->markCooldown('admin.bulk_replace', (string) $uid, ['user_id' => $uid]);

        return null;
    }

public function scanMatches(array $filter, array $rules, array $fields, array $options): array
    {
        $items          = [];
        $hitCount       = 0;
        $documentCount  = 0;
        $sitePageCount  = 0;
        $scanned        = 0;
        $scanCapped     = false;
        $statsOnly      = ($options['stats_only'] ?? false) === true;

        foreach (app(ContentBulkReplaceQuery::class)->loadDocumentRows($filter) as $row) {
            if ($scanned >= \app\common\service\ContentBulkReplaceService::MAX_SCAN) {
                $scanCapped = true;
                break;
            }
            $scanned++;
            $analysis = $this->analyzeRow($row, 'document', $fields, $rules, $options, $statsOnly);
            if ($analysis === null) {
                continue;
            }
            $documentCount++;
            $hitCount += $analysis['hits'];
            $items[] = $analysis;
        }

        if (($filter['include_site_pages'] ?? false) === true) {
            foreach (app(ContentBulkReplaceQuery::class)->loadSitePageRows() as $row) {
                if ($scanned >= \app\common\service\ContentBulkReplaceService::MAX_SCAN) {
                    $scanCapped = true;
                    break;
                }
                $scanned++;
                $analysis = $this->analyzeRow($row, 'site_page', ['content'], $rules, $options, $statsOnly);
                if ($analysis === null) {
                    continue;
                }
                $sitePageCount++;
                $hitCount += $analysis['hits'];
                $items[] = $analysis;
            }
        }

        return [
            'items'           => $items,
            'hit_count'       => $hitCount,
            'document_count'  => $documentCount,
            'site_page_count' => $sitePageCount,
            'scanned'         => $scanned,
            'scan_capped'     => $scanCapped,
        ];
    }

public function analyzeRow(
        array $row,
        string $type,
        array $fields,
        array $rules,
        array $options,
        bool $statsOnly
    ): ?array {
        $hits     = 0;
        $snippets = [];
        foreach ($fields as $field) {
            $raw = (string) ($row[$field] ?? '');
            if ($raw === '') {
                continue;
            }
            $fieldHits = app(ContentBulkReplaceRules::class)->countHits($raw, $rules, $options, $field);
            if ($fieldHits < 1) {
                continue;
            }
            $hits += $fieldHits;
            if (!$statsOnly && count($snippets) < 3) {
                $snippets[] = app(ContentBulkReplaceRules::class)->buildSnippet($raw, $rules, $options, $field);
            }
        }
        if ($hits < 1) {
            return null;
        }

        $id    = (int) ($row['id'] ?? 0);
        $title = (string) ($row['title'] ?? '');

        return [
            'type'     => $type,
            'id'       => $id,
            'title'    => $title,
            'hits'     => $hits,
            'row'      => $row,
            'snippets' => $snippets,
            'edit_url' => $type === 'document'
                ? '/content/document/edit/' . $id
                : '/site/page/form/' . $id,
        ];
    }

public function applyItem(array $item, array $fields, array $rules, array $options): int
    {
        $row   = $item['row'] ?? [];
        $type  = (string) ($item['type'] ?? 'document');
        $id    = (int) ($item['id'] ?? 0);
        $total = 0;
        $payload = ['updated_at' => AppTime::now()];
        $changed = false;

        foreach ($fields as $field) {
            if ($type === 'site_page' && $field !== 'content') {
                continue;
            }
            $raw = (string) ($row[$field] ?? '');
            if ($raw === '') {
                continue;
            }
            [$new, $n] = app(ContentBulkReplaceRules::class)->replaceInField($raw, $field, $rules, $options);
            if ($n < 1 || $new === $raw) {
                continue;
            }
            if (($options['sanitize_after'] ?? true) === true) {
                $new = app(ContentBulkReplaceRules::class)->sanitizeField($field, $new);
            }
            $payload[$field] = $new;
            $row[$field]     = $new;
            $total          += $n;
            $changed         = true;
        }

        if (!$changed || $id < 1) {
            return 0;
        }

        if ($type === 'site_page') {
            SitePage::where('id', $id)->update($payload);

            return $total;
        }

        Document::where('id', $id)->update($payload);
        if (in_array('litpic', $fields, true) && isset($payload['litpic'])) {
            app(DocumentAssetSyncService::class)->syncAfterDocumentSave($id, $row);
        }

        return $total;
    }

public function buildSnapshotPayload(
        array $items,
        array $filter,
        array $fields,
        array $rules
    ): array {
        $docs = [];
        $pages = [];
        foreach ($items as $item) {
            $row = $item['row'] ?? [];
            $slice = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $row)) {
                    $slice[$f] = $row[$f];
                }
            }
            if (($item['type'] ?? '') === 'site_page') {
                $pages[] = ['id' => $item['id'], 'title' => $item['title'], 'fields' => $slice];
            } else {
                $docs[] = ['id' => $item['id'], 'title' => $item['title'], 'fields' => $slice];
            }
        }

        $body = [
            'version'   => 1,
            'exported'  => AppTime::format('c'),
            'filter'    => $filter,
            'rules'     => $rules,
            'documents' => $docs,
            'site_pages'=> $pages,
        ];

        return [
            'filename' => 'bulk_replace_snapshot_' . AppTime::format('Ymd_His') . '.json',
            'content'  => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
        ];
    }

public function finalize(
        array $filter,
        array $rules,
        array $scan,
        array $options,
        array $fields
    ): void {
        app(\app\common\service\site\SiteModeService::class)->clearPageCache();
        foreach ($scan['items'] as $item) {
            if (($item['type'] ?? '') !== 'document') {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) {
                app(\app\common\service\static\StaticHtmlDispatch::class)->afterArticleChange($id, 'edit');
                app(\app\common\service\seo\SitemapService::class)->syncAfterContentChange($id, 'update');
            }
        }

        app(\app\common\service\audit\AuditLogService::class)->operate('批量正文替换', 'admin.document', [
            'hit_count'       => $scan['hit_count'],
            'document_count'  => $scan['document_count'],
            'site_page_count' => $scan['site_page_count'],
            'rules'           => array_map(static fn (array $r): string => $r['find'], $rules),
            'fields'          => $fields,
            'regex'           => ($options['regex'] ?? false) ? 1 : 0,
        ]);
    }
}
