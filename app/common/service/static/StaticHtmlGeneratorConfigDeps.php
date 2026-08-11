<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);



namespace app\common\service\static;



use app\common\service\seo\SeoStaticConfigService;

use app\common\service\site\SiteUrlModeService;



/** StaticHtmlGeneratorService 静态配置/落盘依赖包（冗余审计 §2 batch 10） */

final class StaticHtmlGeneratorConfigDeps

{

    public function __construct(

        public readonly SiteUrlModeService $siteUrlModeService,

        public readonly StaticHtmlManifestService $staticHtmlManifestService,

        public readonly SeoStaticConfigService $seoStaticConfigService,

        public readonly StaticHtmlPathService $staticHtmlPathService,

        public readonly StaticHtmlSkipSupport $staticHtmlSkipSupport,

    ) {

    }

}

