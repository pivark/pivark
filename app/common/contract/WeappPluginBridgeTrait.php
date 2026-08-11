<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — document-addon / 报价桥插件共用（contract · 经 WeappPluginGateway）
 */
declare(strict_types=1);

namespace app\common\contract;

use app\common\service\weapp\WeappPluginGateway;

/** 权益检测 + weapp autoload（AD-026 · 插件侧唯一入口） */
trait WeappPluginBridgeTrait
{
    protected function bridgeEnabled(string $identifier): bool
    {
        return app(WeappPluginGateway::class)->pluginBridgeEnabled($identifier);
    }

    protected function registerWeappAutoload(string $identifier): void
    {
        app(WeappPluginGateway::class)->pluginRegisterAutoloadPublic(strtolower(trim($identifier)));
    }
}
