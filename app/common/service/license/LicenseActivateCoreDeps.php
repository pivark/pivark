<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);



namespace app\common\service\license;



use app\common\service\audit\AuditLogService;

use app\common\service\config\ConfigService;

use app\common\service\plugin\entitlement\EntitlementService;

use app\common\service\release\CoreUpdateRemoteService;

use app\common\service\release\PivarkEditionService;

use app\common\service\site\SiteCoreLicenseService;



/** LicenseActivateService 本地授权状态/审计依赖包（冗余审计 §2 batch 10） */

final class LicenseActivateCoreDeps

{

    public function __construct(

        public readonly ConfigService $configService,

        public readonly CoreUpdateRemoteService $coreUpdateRemoteService,

        public readonly PivarkEditionService $pivarkEditionService,

        public readonly EntitlementService $entitlementService,

        public readonly SiteCoreLicenseService $siteCoreLicenseService,

        public readonly AuditLogService $auditLogService,

    ) {

    }

}

