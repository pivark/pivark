<?php
declare(strict_types=1);

namespace weapp\doc_comment\service;

final class CommentAdminSpaMeta
{
    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    public static function build(array $ctx): array
    {
        CommentService::ensureAutoload();
        $bridge = new CommentDocumentAddonBridge();
        $meta   = $bridge->adminMeta();

        return [
            'cfg'    => $meta['cfg'],
            'levels' => $meta['levels'],
            'stats'  => $meta['stats'],
        ];
    }
}