<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\contract;

use app\common\service\weapp\WeappPluginGateway;
use think\Response;

trait PluginAdminUsageTrait
{
    public function usage(): Response
    {
        if (!$this->entitled()) {
            return $this->renderDisabled();
        }
        $manifest = app(WeappPluginGateway::class)->pluginReadManifest($this->pluginIdentifier) ?? [];

        return $this->renderPluginView('usage', $this->pluginContext($manifest, 'usage'));
    }

    protected function legacyAdminBase(): string
    {
        return '/admin/' . $this->pluginIdentifier;
    }
}
