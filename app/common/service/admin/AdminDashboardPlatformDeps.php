<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);



namespace app\common\service\admin;



use app\common\service\config\ConfigService;

use app\common\service\plugin\PluginService;

use app\common\service\release\CoreUpdateRemoteService;

use app\common\service\release\PivarkEditionService;



/** AdminDashboardService 平台/偏好/版本依赖包（冗余审计 §2 batch 8） */

final class AdminDashboardPlatformDeps

{

    public function __construct(

        public readonly PluginService $pluginService,

        public readonly AdminDashboardPreferenceService $adminDashboardPreferenceService,

        public readonly CoreUpdateRemoteService $coreUpdateRemoteService,

        public readonly PivarkEditionService $pivarkEditionService,

        public readonly ConfigService $configService,

    ) {

    }

}

