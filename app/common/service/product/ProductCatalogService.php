<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\item\ItemService;
use app\common\service\weapp\WeappTagGateway;
use app\common\service\weapp\WeappEventGateway;
use app\common\service\weapp\WeappAdminGateway;
use app\common\service\weapp\WeappItemGateway;

use app\common\support\AppTime;
use app\common\support\ServiceResult;

use app\common\model\Item;
use think\facade\Db;

/** 品项目录：导入 / 导出 / 批量状态（产品展示运营） */
class ProductCatalogService
{
    public static function entitled(): bool
    {
        return ProductCenterGateService::publicSurfaceOpen();
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<int> $ids
     * @return array{content:string,filename:string}
     */
    public static function exportCsvAdmin(array $filters = [], array $ids = []): array
    {
        if (!self::entitled()) {
            return ['content' => "\xEF\xBB\xBFcode,msg\n,-,产品中心未授权\n", 'filename' => 'items.csv'];
        }
        $paramDefs = ProductService::listParamDefs();
        $headers   = ['code', 'name', 'slug', 'item_type', 'status', 'sort', 'primary_document_id', 'litpic', 'tag_slugs'];
        foreach ($paramDefs as $def) {
            $headers[] = (string) ($def['param_key'] ?? '');
        }

        $rows = app(ItemService::class)->listAdminExportRows($filters, $ids);
        $litpicByDoc = self::litpicByPrimaryDocumentIds($rows);

        $lines = [self::csvLine($headers)];
        foreach ($rows as $row) {
            $tagSlugs = self::tagSlugsForIds((array) ($row['tag_ids'] ?? []));
            $docId = (int) ($row['primary_document_id'] ?? 0);
            $line = [
                (string) ($row['code'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['slug'] ?? ''),
                (string) ($row['item_type'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['sort'] ?? '0'),
                (string) $docId,
                (string) ($litpicByDoc[$docId] ?? ''),
                implode('|', $tagSlugs),
            ];
            $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
            foreach ($paramDefs as $def) {
                $key   = (string) ($def['param_key'] ?? '');
                $line[] = (string) ($attrs[$key] ?? '');
            }
            $lines[] = self::csvLine($line);
        }

        return [
            'content'  => "\xEF\xBB\xBF" . implode("\n", $lines) . "\n",
            'filename' => 'items_' . AppTime::format('Ymd_His') . '.csv',
        ];
    }

    /**
     * 导入预检（不落库）
     *
     * @return ServiceResult
     */
    public static function importPreviewAdmin(string $csvText): ServiceResult
    {
        if (!self::entitled()) {
            return ServiceResult::fail('产品中心未授权');
        }
        $parsed = self::parseImportRows($csvText);
        if (!$parsed->isOk()) {
            return $parsed;
        }
        $preview = [];
        $errors  = [];
        $valid   = 0;
        foreach ($parsed['rows'] as $i => $payload) {
            $lineNo = $i + 2;
            $err    = self::validateImportPayload($payload);
            if ($err !== null) {
                $errors[] = '第 ' . $lineNo . ' 行：' . $err;
                continue;
            }
            $valid++;
            $code   = trim((string) ($payload['code'] ?? ''));
            $exists = Item::where('code', $code)->find();
            $preview[] = [
                'line'   => $lineNo,
                'code'   => $code,
                'name'   => (string) ($payload['name'] ?? ''),
                'action' => $exists ? 'update' : 'create',
            ];
        }

        return ServiceResult::ok(['preview' => array_slice($preview, 0, 50), 'total' => $valid, 'errors' => $errors], '预检完成');
    }

    /**
     * @return ServiceResult
     */
    public static function importCsvAdmin(string $csvText, bool $onlyValid = false): ServiceResult
    {
        if (!self::entitled()) {
            return ServiceResult::fail('产品中心未授权');
        }
        $parsed = self::parseImportRows($csvText);
        if (!$parsed->isOk()) {
            return $parsed;
        }
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($parsed['rows'] as $i => $payload) {
            $lineNo = $i + 2;
            $err    = self::validateImportPayload($payload);
            if ($err !== null) {
                if ($onlyValid) {
                    $skipped++;
                    continue;
                }
                $errors[] = '第 ' . $lineNo . ' 行：' . $err;
                continue;
            }
            $existing = Item::where('code', trim((string) ($payload['code'] ?? '')))->find()?->toArray();
            $litpic = trim((string) ($payload['litpic'] ?? ''));
            unset($payload['litpic']);
            $res      = app(WeappItemGateway::class)->itemSaveAdmin($payload);
            if (!$res->isOk()) {
                $errors[] = '第 ' . $lineNo . ' 行：' . (string) ($res->message() ?? '失败');
                continue;
            }
            $itemId = (int) ($res->dataArray()['id'] ?? ($existing['id'] ?? 0));
            self::applyImportLitpicToPrimaryDocument($itemId, (int) ($payload['primary_document_id'] ?? 0), $litpic);
            if ($existing) {
                $updated++;
            } else {
                $created++;
            }
        }

        $msg = "导入完成：新增 {$created}，更新 {$updated}";
        if ($onlyValid && $skipped > 0) {
            $msg .= "，跳过 {$skipped} 行无效数据";
        }
        if ($errors !== []) {
            $msg .= '，部分行失败';
        }

        return ServiceResult::ok(['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors], $msg);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function validateImportPayload(array $payload): ?string
    {
        $code = trim((string) ($payload['code'] ?? ''));
        if ($code === '') {
            return '货号为空';
        }
        $attrCheck = ProductParamValidator::normalizeAttrs(
            is_array($payload['attrs'] ?? null) ? $payload['attrs'] : [],
        );
        if (!$attrCheck->isOk()) {
            return (string) ($attrCheck->message() ?? '参数无效');
        }

        return null;
    }

    /**
     * @param list<int> $ids
     * @return ServiceResult
     */
    public static function bulkStatusAdmin(array $ids, string $status): ServiceResult
    {
        if (!self::entitled()) {
            return ServiceResult::fail('产品中心未授权');
        }

        return app(ItemService::class)->batchStatusAdmin($ids, $status);
    }

    /**
     * @param list<int> $ids
     * @return ServiceResult
     */
    public static function bulkDeleteAdmin(array $ids): ServiceResult
    {
        if (!self::entitled()) {
            return ServiceResult::fail('产品中心未授权');
        }

        return app(ItemService::class)->batchDeleteAdmin($ids);
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<int> $ids
     * @return ServiceResult
     */
    public static function exportAsyncStartAdmin(array $filters = [], array $ids = []): ServiceResult
    {
        if (!self::entitled()) {
            return ServiceResult::fail('产品中心未授权');
        }
        $paramDefs = ProductService::listParamDefs();
        $headers   = ['code', 'name', 'slug', 'item_type', 'status', 'sort', 'primary_document_id', 'litpic', 'tag_slugs'];
        foreach ($paramDefs as $def) {
            $headers[] = (string) ($def['param_key'] ?? '');
        }
        $total = count(app(ItemService::class)->listAdminExportRows($filters, $ids));
        if ($total < 1) {
            return ServiceResult::fail('没有可导出的品项');
        }
        if ($total <= 500) {
            return ServiceResult::ok(['sync' => true, 'total' => $total], 'sync');
        }

        return app(WeappAdminGateway::class)->adminAsyncExportStart(
            'product_items',
            'items',
            $headers,
            static function (int $page, int $limit) use ($filters, $ids, $paramDefs): array {
                $rows    = app(ItemService::class)->listAdminExportRows($filters, $ids);
                $total   = count($rows);
                $offset  = ($page - 1) * $limit;
                $slice   = array_slice($rows, $offset, $limit);
                $litpicByDoc = self::litpicByPrimaryDocumentIds($slice);
                $csvRows = [];
                foreach ($slice as $row) {
                    $tagSlugs = self::tagSlugsForIds((array) ($row['tag_ids'] ?? []));
                    $docId = (int) ($row['primary_document_id'] ?? 0);
                    $line = [
                        (string) ($row['code'] ?? ''),
                        (string) ($row['name'] ?? ''),
                        (string) ($row['slug'] ?? ''),
                        (string) ($row['item_type'] ?? ''),
                        (string) ($row['status'] ?? ''),
                        (string) ($row['sort'] ?? '0'),
                        (string) $docId,
                        (string) ($litpicByDoc[$docId] ?? ''),
                        implode('|', $tagSlugs),
                    ];
                    $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
                    foreach ($paramDefs as $def) {
                        $key    = (string) ($def['param_key'] ?? '');
                        $line[] = (string) ($attrs[$key] ?? '');
                    }
                    $csvRows[] = $line;
                }
                $done = $offset + count($slice) >= $total;

                return ['rows' => $csvRows, 'done' => $done, 'total' => $total];
            },
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<int> $ids
     * @return ServiceResult
     */
    public static function exportAsyncStepAdmin(string $jobId, array $filters = [], array $ids = []): ServiceResult
    {
        if (!self::entitled()) {
            return ServiceResult::fail('产品中心未授权');
        }
        $paramDefs = ProductService::listParamDefs();

        return app(WeappAdminGateway::class)->adminAsyncExportStep(
            $jobId,
            static function (int $page, int $limit) use ($filters, $ids, $paramDefs): array {
                $rows    = app(ItemService::class)->listAdminExportRows($filters, $ids);
                $total   = count($rows);
                $offset  = ($page - 1) * $limit;
                $slice   = array_slice($rows, $offset, $limit);
                $litpicByDoc = self::litpicByPrimaryDocumentIds($slice);
                $csvRows = [];
                foreach ($slice as $row) {
                    $tagSlugs = self::tagSlugsForIds((array) ($row['tag_ids'] ?? []));
                    $docId = (int) ($row['primary_document_id'] ?? 0);
                    $line = [
                        (string) ($row['code'] ?? ''),
                        (string) ($row['name'] ?? ''),
                        (string) ($row['slug'] ?? ''),
                        (string) ($row['item_type'] ?? ''),
                        (string) ($row['status'] ?? ''),
                        (string) ($row['sort'] ?? '0'),
                        (string) $docId,
                        (string) ($litpicByDoc[$docId] ?? ''),
                        implode('|', $tagSlugs),
                    ];
                    $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
                    foreach ($paramDefs as $def) {
                        $key    = (string) ($def['param_key'] ?? '');
                        $line[] = (string) ($attrs[$key] ?? '');
                    }
                    $csvRows[] = $line;
                }
                $done = $offset + count($slice) >= $total;

                return ['rows' => $csvRows, 'done' => $done, 'total' => $total];
            },
        );
    }

    /** @return array{filename:string,content:string}|null */
    public static function exportAsyncDownloadAdmin(string $jobId): ?array
    {
        return app(WeappAdminGateway::class)->adminAsyncExportConsumeDownload($jobId);
    }

    /**
     * @return ServiceResult
     */
    private static function parseImportRows(string $csvText): ServiceResult
    {
        $csvText = trim($csvText);
        if ($csvText === '') {
            return ServiceResult::fail('CSV 内容为空');
        }
        if (str_starts_with($csvText, "\xEF\xBB\xBF")) {
            $csvText = substr($csvText, 3);
        }
        $lines = preg_split('/\r\n|\r|\n/', $csvText) ?: [];
        if ($lines === []) {
            return ServiceResult::fail('无有效行');
        }
        $header = self::parseCsvLine((string) array_shift($lines));
        if (!in_array('code', $header, true) || !in_array('name', $header, true)) {
            return ServiceResult::fail('表头须含 code、name 列');
        }
        $paramDefs = ProductService::listParamDefs();
        $rows      = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cells = self::parseCsvLine($line);
            $data  = [];
            foreach ($header as $idx => $col) {
                $data[$col] = (string) ($cells[$idx] ?? '');
            }
            $attrs = [];
            foreach ($paramDefs as $def) {
                $key = (string) ($def['param_key'] ?? '');
                if ($key !== '' && array_key_exists($key, $data)) {
                    $attrs[$key] = $data[$key];
                }
            }
            $tagIds = [];
            $tagPart = trim((string) ($data['tag_slugs'] ?? ''));
            if ($tagPart !== '') {
                foreach (preg_split('/[|,;]+/', $tagPart) ?: [] as $slug) {
                    $slug = trim((string) $slug);
                    if ($slug === '') {
                        continue;
                    }
                    $tag = app(WeappTagGateway::class)->tagFindBySlug($slug);
                    if ($tag !== null) {
                        $tagIds[] = (int) ($tag['id'] ?? 0);
                    }
                }
            }
            $existing = Item::where('code', trim((string) ($data['code'] ?? '')))->find()?->toArray();
            $rows[]   = [
                'id'                  => $existing ? (int) ($existing['id'] ?? 0) : 0,
                'code'                => trim((string) ($data['code'] ?? '')),
                'name'                => trim((string) ($data['name'] ?? '')),
                'slug'                => trim((string) ($data['slug'] ?? '')),
                'item_type'           => trim((string) ($data['item_type'] ?? ItemService::TYPE_PHYSICAL)),
                'status'              => trim((string) ($data['status'] ?? ItemService::STATUS_DRAFT)),
                'sort'                => (int) ($data['sort'] ?? 0),
                'primary_document_id' => (int) ($data['primary_document_id'] ?? 0),
                'litpic'              => trim((string) ($data['litpic'] ?? '')),
                'tag_ids'             => $tagIds,
                'attrs'               => $attrs,
            ];
        }
        if ($rows === []) {
            return ServiceResult::fail('无数据行');
        }

        return ServiceResult::ok(['rows' => $rows], 'ok');
    }

    /**
     * CSV litpic 列 = 主文档 documents.litpic（存储路径，非 cover_url）
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, string> document_id => litpic
     */
    private static function litpicByPrimaryDocumentIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['primary_document_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $map = \app\common\model\Document::whereIn('id', array_keys($ids))->column('litpic', 'id');

        $out = [];
        foreach ($map as $id => $litpic) {
            $out[(int) $id] = trim((string) $litpic);
        }

        return $out;
    }

    /**
     * CSV 导入的 litpic → 主文档 documents.litpic（品项表无封面列）
     */
    private static function applyImportLitpicToPrimaryDocument(int $itemId, int $primaryDocId, string $litpic): void
    {
        $litpic = trim($litpic);
        if ($itemId < 1 || $litpic === '') {
            return;
        }
        $docId = max(0, $primaryDocId);
        if ($docId < 1) {
            $docId = (int) (Item::where('id', $itemId)->value('primary_document_id') ?: 0);
        }
        if ($docId < 1) {
            return;
        }
        $path = app(\app\common\service\media\MediaUrlService::class)->formatForStorage(
            \app\common\support\HtmlSanitizer::cleanUrl($litpic)
        );
        if ($path === '') {
            return;
        }
        \app\common\model\Document::where('id', $docId)->update([
            'litpic'     => mb_substr($path, 0, 512),
            'updated_at' => AppTime::now(),
        ]);
        if (\app\common\support\DbTable::exists('document_product_images')) {
            $n = (int) Db::name('document_product_images')->where('document_id', $docId)->count();
            if ($n < 1) {
                app(\app\common\service\document\DocumentProductImageService::class)
                    ->replaceForDocument($docId, [], $path);
            }
        }
    }

    /** @param list<string> $cells */
    private static function csvLine(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            $s = (string) $cell;
            if (str_contains($s, ',') || str_contains($s, '"') || str_contains($s, "\n")) {
                $s = '"' . str_replace('"', '""', $s) . '"';
            }
            $out[] = $s;
        }

        return implode(',', $out);
    }

    /** @return list<string> */
    private static function parseCsvLine(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [];
        }
        return str_getcsv($line);
    }

    /**
     * @param list<int|string> $tagIds
     * @return list<string>
     */
    private static function tagSlugsForIds(array $tagIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $tagIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $rows  = app(WeappTagGateway::class)->tagSlugIndexRowsByIds($ids);
        $slugs = [];
        foreach ($ids as $tagId) {
            $slug = trim((string) ($rows[$tagId]['slug'] ?? ''));
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }
}
