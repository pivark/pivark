<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);



namespace app\common\service\kernel;



use app\common\service\access\AccessStatsService;

use app\common\service\event\EventBusService;

use app\common\service\favorite\FavoriteService;

use app\common\service\infra\DbOpsLogService;

use app\common\service\site\SiteFormService;



/** KernelBootstrapService 核心 L1 启动依赖包（冗余审计 §2 batch 9） */

final class KernelBootstrapCoreDeps

{

    public function __construct(

        public readonly DbOpsLogService $dbOpsLogService,

        public readonly AccessStatsService $accessStatsService,

        public readonly FavoriteService $favoriteService,

        public readonly SiteFormService $siteFormService,

        public readonly EventBusService $eventBusService,

    ) {

    }

}

