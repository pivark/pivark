<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\weapp;

use app\common\service\plugin\PluginService;
use think\Response;

trait PluginAdminUsage
{
    public function usage(): Response
    {
        if (!$this->entitled()) {
            return $this->renderDisabled();
        }
        $manifest = app(PluginService::class)->readManifest($this->pluginIdentifier) ?? [];

        return $this->renderPluginView('usage', $this->pluginContext($manifest, 'usage'));
    }

    protected function legacyAdminBase(): string
    {
        return '/admin/' . $this->pluginIdentifier;
    }
}
