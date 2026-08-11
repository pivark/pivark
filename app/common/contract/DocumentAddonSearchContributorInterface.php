<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\contract;

/**
 * 文档扩展型插件向超级搜索贡献可检索文本 / 可解析附件
 * 实现类放在 weapp/{id}/service/ 并在 plugin.json 声明 document_search.contributor
 */
interface DocumentAddonSearchContributorInterface
{
    /**
     * @return list<array{label:string,text:string}>
     */
    public function searchSections(int $documentId): array;

    /**
     * 供 ai_document 入库解析的本地文件（PDF/docx 等）
     *
     * @return list<array{path:string,mime:string,name:string}>
     */
    public function searchAttachments(int $documentId): array;
}
