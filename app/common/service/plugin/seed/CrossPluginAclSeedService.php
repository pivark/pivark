<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\seed;

use app\common\service\auth\CrossPluginAclRegistry;

/** 插件安装/卸载后刷新跨插件 ACL 注册表缓存 */
final class CrossPluginAclSeedService
{
    public function __construct(
        private readonly EnterprisePermissionSeedService $enterprisePermissionSeed,
        private readonly CrossPluginAclRegistry $crossPluginAclRegistry,
    ) {
    }

    public function afterEnterprisePluginChange(string $identifier): void
    {
        if (!$this->enterprisePermissionSeed->isEnterpriseApplication($identifier)) {
            return;
        }
        $this->crossPluginAclRegistry->rebuildFromInstalledPlugins();
    }
}
