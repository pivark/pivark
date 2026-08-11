<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);



namespace app\common\service\static;



use app\common\service\document\DocumentPublicService;

use app\common\service\front\FrontRenderService;

use app\common\service\front\FrontUrlBuilder;

use app\common\service\site\SitePageService;

use app\common\service\tag\TagService;



/** StaticHtmlGeneratorService 内容渲染/查询依赖包（冗余审计 §2 batch 10） */

final class StaticHtmlGeneratorContentDeps

{

    public function __construct(

        public readonly TagService $tagService,

        public readonly DocumentPublicService $documentService,

        public readonly FrontUrlBuilder $frontUrlBuilder,

        public readonly FrontRenderService $frontRenderService,

        public readonly SitePageService $sitePageService,

        public readonly StaticHtmlDocumentQuery $staticHtmlDocumentQuery,

    ) {

    }

}

