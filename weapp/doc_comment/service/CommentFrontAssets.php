<?php
declare(strict_types=1);

namespace weapp\doc_comment\service;

use app\common\service\weapp\WeappFrontGateway;

final class CommentFrontAssets
{
    public static function registerWidget(): void
    {
        $gw = app(WeappFrontGateway::class);
        $gw->frontAssetRegisterWeappStylesheet('doc_comment', 'pv-comment-css', 'assets/comment-widget.css');
        $gw->frontAssetRegisterWeappScript('doc_comment', 'pv-comment-widget', 'assets/comment-widget.js');
    }
}
