<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);



namespace app\common\service\license;



use app\common\service\site\SiteKeyService;



/** LicenseActivateService 远程授权/门户依赖包（冗余审计 §2 batch 10） */

final class LicenseActivateRemoteDeps

{

    public function __construct(

        public readonly SiteKeyService $siteKeyService,

        public readonly LicenseRemoteClientService $licenseRemoteClientService,

        public readonly LicensePortalService $licensePortalService,

    ) {

    }

}

