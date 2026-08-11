<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\gateway;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\product\ProductL1Access;

/** 报价桥等 L1 门面：权益检测 + weapp autoload（AD-026 · 无 bridge 子目录） */
trait WeappPluginBridgeTrait
{
    protected function bridgeEnabled(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if (ProductL1Access::isKernel($identifier)) {
            return ProductL1Access::allowsFrontBridge();
        }

        return app(EntitlementService::class)->can($identifier)
            && is_dir(ROOT_PATH . 'weapp/' . $identifier);
    }

    protected function registerWeappAutoload(string $identifier): void
    {
        app(PluginService::class)->registerAutoloadPublic(strtolower(trim($identifier)));
    }
}
