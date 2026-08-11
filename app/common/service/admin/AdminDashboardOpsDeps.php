<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);
namespace app\common\service\admin;
use app\common\service\document\DocumentAdminService;
use app\common\service\member\MemberOpsService;
use app\common\service\plugin\entitlement\PluginEntitlementReminderService;
use app\common\service\site\SiteFormService;

/** AdminDashboardService 内容/运营待办依赖包（冗余审计 §2 batch 8） */

final class AdminDashboardOpsDeps

{

    public function __construct(

        public readonly DocumentAdminService $documentService,

        public readonly MemberOpsService $memberOpsService,

        public readonly SiteFormService $siteFormService,

        public readonly PluginEntitlementReminderService $pluginEntitlementReminderService,

    ) {

    }

}

