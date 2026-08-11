<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\contract;

use app\common\service\weapp\WeappAdminGateway;
use think\facade\Request;
use think\Response;

/** 插件后台 AdminController 基类能力（经 WeappAdminGateway） */
trait PluginAdminBaseTrait
{
    protected string $pluginIdentifier = '';

    /** @param array<string, mixed> $vars */
    protected function renderPluginView(string $view, array $vars = []): Response
    {
        $query = Request::get();

        return app(WeappAdminGateway::class)->pluginAdminRenderPluginView(
            $this->pluginIdentifier,
            $view,
            is_array($query) ? $query : [],
            $vars,
        );
    }

    protected function pluginAdminHref(string $view): string
    {
        return app(WeappAdminGateway::class)->pluginAdminHref($this->pluginIdentifier, $view);
    }

    protected function entitled(): bool
    {
        return app(WeappAdminGateway::class)->pluginAdminEntitled($this->pluginIdentifier);
    }

    protected function renderDisabled(): Response
    {
        return $this->renderPluginView('index', []);
    }

    /** @return array<string, mixed> */
    protected function pluginContext(array $manifest, string $navKey = 'settings'): array
    {
        return app(WeappAdminGateway::class)->pluginAdminContext($this->pluginIdentifier, $manifest, $navKey);
    }

    protected function assignAdminSession(): void
    {
        app(WeappAdminGateway::class)->pluginAdminAssignSession();
    }
}
