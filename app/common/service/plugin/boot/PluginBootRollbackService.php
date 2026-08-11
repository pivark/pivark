<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\boot;

use app\common\service\plugin\registry\HubCapabilityRegistry;
use app\common\service\plugin\registry\PluginApiRegistry;
use app\common\service\plugin\registry\PluginExtensionRegistry;
use app\common\service\plugin\registry\PluginFrontTemplateRegistry;
use app\common\service\plugin\registry\PluginSeoRegistry;
use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\service\admin\AdminSpaMetaRegistry;
use app\common\service\admin\WeappAdminNavRegistry;
use app\common\service\admin\AdminPluginSidebarRegistry;
use app\common\service\auth\SocialAuthCapabilityRegistry;
use app\common\service\channel\MiniprogramChannelRegistry;
use app\common\service\channel\MiniprogramFeatureRegistry;
use app\common\service\front\PluginFrontAssetRegistry;
use app\common\service\cron\PluginCronTaskRegistry;
use app\common\service\member\MemberCenterPageRegistry;
use app\common\service\member\PluginMemberConsumptionRegistry;
use app\common\service\enterprise\EnterpriseAssetBackendRegistry;
use app\common\service\event\EventBusService;
use app\common\service\hook\HookService;
use app\common\service\admin\AdminPermissionExtensionRegistry;
use app\common\service\admin\AdminPluginRouteRegistry;
use app\common\service\admin\AdminSpaExplicitRouteRegistry;
use app\common\service\front\FrontPluginPathRegistry;
use app\common\service\plugin\registry\PluginRouteService;
use app\common\support\OpsLog;

/** boot 失败时回滚该插件已写入的 Registry 条目（避免半注册状态） */
final class PluginBootRollbackService
{
    public function purgeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }

        app(PluginExtensionRegistry::class)->removeForIdentifier($identifier);
        app(PluginApiRegistry::class)->removeForIdentifier($identifier);
        app(PluginCronTaskRegistry::class)->removeForIdentifier($identifier);
        app(WeappAdminNavRegistry::class)->removeForIdentifier($identifier);
        app(AdminSpaMetaRegistry::class)->removeForIdentifier($identifier);
        app(MemberCenterPageRegistry::class)->removeForIdentifier($identifier);
        app(PluginFrontTemplateRegistry::class)->removeForIdentifier($identifier);
        app(PluginFrontAssetRegistry::class)->removeForIdentifier($identifier);
        app(PluginOfferBridgeRegistry::class)->removeForIdentifier($identifier);
        app(MiniprogramFeatureRegistry::class)->removeForIdentifier($identifier);
        app(AdminPluginSidebarRegistry::class)->removeForIdentifier($identifier);
        app(PluginMemberConsumptionRegistry::class)->removeForIdentifier($identifier);
        app(HookService::class)->removeForIdentifier($identifier);
        app(EventBusService::class)->removeForIdentifier($identifier);
        app(AdminPluginRouteRegistry::class)->removeForIdentifier($identifier);
        app(AdminSpaExplicitRouteRegistry::class)->removeForIdentifier($identifier);
        app(HubCapabilityRegistry::class)->removeForIdentifier($identifier);
        app(PluginSeoRegistry::class)->removeForIdentifier($identifier);
        app(MiniprogramChannelRegistry::class)->removeForIdentifier($identifier);
        app(SocialAuthCapabilityRegistry::class)->removeForIdentifier($identifier);
        app(EnterpriseAssetBackendRegistry::class)->removeForIdentifier($identifier);
        app(PluginRouteService::class)->removeForIdentifier($identifier);
        app(FrontPluginPathRegistry::class)->removeForIdentifier($identifier);
        app(AdminPermissionExtensionRegistry::class)->removeForIdentifier($identifier);

        OpsLog::businessWarning('plugin_boot_rollback_purged', ['identifier' => $identifier]);
    }
}
