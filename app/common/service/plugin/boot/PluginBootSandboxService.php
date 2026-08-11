<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\boot;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\extension\PluginExtensionManifestLoader;
use app\common\service\event\EventBusService;
use app\common\service\hook\HookService;
use app\common\support\ServiceResult;

/** 启用前隔离试 boot：模拟完整 boot 链后回滚 Registry，不写 enabled=1 */
final class PluginBootSandboxService
{
    public function trialBoot(string $identifier): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }

        PluginService::registerAutoloadPublic($identifier);

        try {
            $plugin = app(PluginBootService::class)->loadPluginClass($identifier);
            if ($plugin === null) {
                return ServiceResult::fail('Plugin.php 不存在或类无法加载');
            }

            ob_start();
            try {
                $plugin->boot();
                $manifest = app(PluginService::class)->readManifest($identifier);
                app(PluginExtensionManifestLoader::class)->applyForIdentifier($identifier, $manifest);
                app(EventBusService::class)->registerManifestSubscribes($identifier, $manifest);
                app(HookService::class)->fire('app_init', ['identifier' => $identifier]);
            } finally {
                ob_end_clean();
            }

            return ServiceResult::ok(null, '沙箱试 boot 成功');
        } catch (\Throwable $e) {
            return ServiceResult::fail('沙箱试 boot 失败：' . $e->getMessage());
        } finally {
            app(PluginBootRollbackService::class)->purgeForIdentifier($identifier);
        }
    }
}
