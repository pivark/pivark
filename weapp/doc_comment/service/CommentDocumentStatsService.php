<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace weapp\doc_comment\service;

use app\common\service\weapp\WeappSupportGateway;
use weapp\doc_comment\model\WeappDocComment;

final class CommentDocumentStatsService
{
    /**
     * @param list<int> $documentIds
     * @return array{approved:int,total:int,active:bool}
     */
    public static function countForDocuments(array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds))));
        if ($documentIds === []) {
            return ['approved' => 0, 'total' => 0, 'active' => false];
        }

        try {
            return [
                'approved' => (int) WeappDocComment::whereIn('document_id', $documentIds)
                    ->where('status', CommentService::STATUS_APPROVED)
                    ->count(),
                'total'    => (int) WeappDocComment::whereIn('document_id', $documentIds)->count(),
                'active'   => true,
            ];
        } catch (\Throwable $e) {
            app(WeappSupportGateway::class)->kernelOpsLog('document_comment_stats_failed', [
                'document_ids' => count($documentIds),
                'msg'          => $e->getMessage(),
            ]);

            return ['approved' => 0, 'total' => 0, 'active' => true];
        }
    }
}