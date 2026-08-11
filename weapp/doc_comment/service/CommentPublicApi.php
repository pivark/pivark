<?php
declare(strict_types=1);

namespace weapp\doc_comment\service;

use app\common\contract\PluginApiCallableTrait;
use app\common\contract\PluginApiInterface;

/** 评论插件对外 API（跨插件经 PluginApiRegistry 调用） */
final class CommentPublicApi implements PluginApiInterface
{
    use PluginApiCallableTrait;

    public function pluginIdentifier(): string
    {
        return 'doc_comment';
    }

    public function isActive(): bool
    {
        CommentService::ensureAutoload();

        return CommentService::isActive();
    }

    /**
     * @return array{code:int,msg:string,list:list<array<string,mixed>>,total:int}
     */
    public function listForDocument(int $documentId, int $page = 1, int $parentId = 0): array
    {
        CommentService::ensureAutoload();

        return CommentService::listPublic($documentId, $page, $parentId);
    }
}
