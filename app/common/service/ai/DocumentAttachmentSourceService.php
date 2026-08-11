<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\service\search\DocumentAddonAttachmentRegistry;

/**
 * 收集文档关联的可解析附件路径（后台入库用，前台不调用）
 * 实现已迁至 Core DocumentAddonAttachmentRegistry（覆盖全部 document-addon）
 */
class DocumentAttachmentSourceService
{

    public function __construct(
        private readonly DocumentAddonAttachmentRegistry $attachments,
    ) {
    }

    /**
     * @return list<array{path:string,mime:string,name:string}>
     */
    public function listForDocument(int $documentId): array
    {
        return $this->attachments->listForDocument($documentId);
    }

    public function resolveAbsolutePath(string $pathOrUrl): string
    {
        return $this->attachments->resolveAbsolutePath($pathOrUrl);
    }
}
