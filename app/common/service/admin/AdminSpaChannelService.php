<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\service\channel\MiniprogramChannelRegistry;
use app\common\service\channel\MiniprogramDeliveryService;
use app\common\service\channel\MiniprogramPageConfigService;
use app\common\service\plugin\PluginService;
use app\common\support\ServiceResult;

/** Vue 后台 SPA 小程序渠道（从 Spa 控制器 batch 10 下沉） */
class AdminSpaChannelService
{

    /** @return ServiceResult<array{path:string,filename:string}> */
    public function buildWechatSdkZip(?string $platform, ?string $edition): ServiceResult
    {
        $hub = app(MiniprogramChannelRegistry::class)->hubPlugin();
        if ($hub === '' || !app(PluginService::class)->isEnabled($hub)) {
            return ServiceResult::fail('请先在应用市场安装并启用小程序渠道插件');
        }

        app(PluginService::class)->registerAutoloadPublic($hub);
        $built = app(MiniprogramDeliveryService::class)->buildSdkZip($platform, $edition);
        if (!$built->isOk() || empty($built['path']) || !is_file((string) $built['path'])) {
            return ServiceResult::fail((string) ($built->message() ?? '打包失败'));
        }

        return ServiceResult::ok([
            'path'     => (string) $built['path'],
            'filename' => (string) ($built['filename'] ?? 'pivark-wechat-content.zip'),
        ], '');
    }

    /**
     * @param array<string, mixed> $theme
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    public function miniprogramPagePreview(array $theme, array $blocks, string $page): array
    {
        return app(MiniprogramPageConfigService::class)->buildAdminPreview($theme, $blocks, $page);
    }

    public function miniprogramPageSave(array $post = [], string $rawBody = ''): ServiceResult
    {
        return app(MiniprogramPageConfigService::class)->saveAdmin(
            app(MiniprogramPageConfigService::class)->parseAdminSavePayload($post, $rawBody)
        );
    }
}
