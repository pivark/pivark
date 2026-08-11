<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document;

use app\common\service\content\EditorContentService;
use app\common\service\document\satellite\DocumentFavoriteService;
use app\common\service\front\FrontAuthService;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberUxService;
use app\common\service\tag\TagService;
use app\common\service\theme\ThemeTemplateCatalogService;
use app\common\service\infra\UrlPathService;

/** DocumentPublicService 展示/格式化依赖包（冗余审计 §2） */
final class DocumentPublicPresentationDeps
{
    public function __construct(
        public readonly DocumentAttrFlagIndexService $attrFlags,
        public readonly TagService $tags,
        public readonly DocumentFormatService $format,
        public readonly FrontAuthService $frontAuth,
        public readonly MemberLevelService $memberLevels,
        public readonly MemberUxService $memberUx,
        public readonly EditorContentService $editor,
        public readonly UrlPathService $urlPath,
        public readonly ThemeTemplateCatalogService $themeCatalog,
        public readonly DocumentFavoriteService $favorites,
    ) {
    }
}
