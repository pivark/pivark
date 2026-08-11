<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\model\Document;
use app\common\support\SiteUrl;
use app\common\support\AppTime;
use app\common\support\DbTable;
use app\common\support\ServiceResult;
use think\facade\Db;

/** 文档手动「相关阅读」（产品 Tab 第 6 步；有配置时优先于 TAG 自动关联） */
final class DocumentRelatedRefService
{
    public static function isAvailable(): bool
    {
        return DbTable::exists('document_related_refs');
    }

    /** @return list<int> */
    public static function idsForDocument(int $documentId): array
    {
        if ($documentId < 1 || !self::isAvailable()) {
            return [];
        }
        $ids = [];
        foreach (
            Db::name('document_related_refs')
                ->where('document_id', $documentId)
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->column('related_document_id') ?: [] as $raw
        ) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{id:int,title:string,url:string}>
     */
    public static function listPublicForDocument(int $documentId, int $limit = 12): array
    {
        $ids = self::idsForDocument($documentId);
        if ($ids === []) {
            return [];
        }
        if ($limit > 0) {
            $ids = array_slice($ids, 0, $limit);
        }
        $rows = Document::whereIn('id', $ids)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->select()
            ->toArray();
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $byId[$id] = [
                'id'    => $id,
                'title' => (string) ($row['title'] ?? ''),
                'url'   => SiteUrl::documentFromRow($row),
            ];
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * @return list<array{id:int,title:string,status:int}>
     */
    public static function listAdminForDocument(int $documentId): array
    {
        $ids = self::idsForDocument($documentId);
        if ($ids === []) {
            return [];
        }
        $rows = Document::whereIn('id', $ids)->whereNull('deleted_at')->select()->toArray();
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $byId[$id] = [
                'id'     => $id,
                'title'  => (string) ($row['title'] ?? ''),
                'status' => (int) ($row['status'] ?? 0),
            ];
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $relatedDocumentIds
     */
    public static function syncForDocument(int $documentId, array $relatedDocumentIds): ServiceResult
    {
        if ($documentId < 1) {
            return ServiceResult::fail('文档无效');
        }
        if (!self::isAvailable()) {
            return ServiceResult::fail('相关文档表未就绪');
        }

        $ordered = [];
        $seen    = [];
        foreach ($relatedDocumentIds as $raw) {
            $id = (int) $raw;
            if ($id < 1 || $id === $documentId || isset($seen[$id])) {
                continue;
            }
            if (!Document::where('id', $id)->whereNull('deleted_at')->find()) {
                continue;
            }
            $seen[$id]    = true;
            $ordered[] = $id;
        }

        Db::startTrans();
        try {
            Db::name('document_related_refs')->where('document_id', $documentId)->delete();
            $sort = 0;
            foreach ($ordered as $relatedId) {
                Db::name('document_related_refs')->insert([
                    'document_id'          => $documentId,
                    'related_document_id'  => $relatedId,
                    'sort'                 => $sort++,
                    'created_at'           => AppTime::now(),
                ]);
            }
            Db::commit();

            return ServiceResult::ok(null, 'ok');
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail('相关文档保存失败：' . $e->getMessage());
        }
    }

    public static function purgeForDocument(int $documentId): void
    {
        if ($documentId < 1 || !self::isAvailable()) {
            return;
        }
        Db::name('document_related_refs')->where('document_id', $documentId)->delete();
    }
}
