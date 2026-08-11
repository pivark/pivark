<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\model\Plugin;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\product\ProductL1Access;
use app\common\service\plugin\PluginService;
use app\common\support\ServiceResult;

/** Vue 后台 SPA 插件启用/授权守卫（从 Spa 控制器 batch 10 下沉） */
class AdminSpaPluginAccessService
{

    /** L2：插件已安装、已启用、已授权且 weapp 目录存在。L1 product：并入产品中心内核，走 ProductCenterGateService 档位门禁 */
    public function isEnabledForAdmin(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }
        if (ProductL1Access::isKernel($identifier)) {
            return ProductL1Access::allowsAdminApi();
        }
        if (!is_dir(app(PluginService::class)->weappRoot() . $identifier)) {
            return false;
        }
        if (!app(EntitlementService::class)->can($identifier)) {
            return false;
        }
        $row = Plugin::where('identifier', $identifier)->where('installed', 1)->find();

        return $row !== null && (int) ($row['enabled'] ?? 0) === 1;
    }

    public function requireEnabled(string $identifier, string $notFoundMessage): ServiceResult
    {
        if ($this->isEnabledForAdmin($identifier)) {
            return ServiceResult::ok(null, '');
        }

        return ServiceResult::notFound($notFoundMessage);
    }
}
