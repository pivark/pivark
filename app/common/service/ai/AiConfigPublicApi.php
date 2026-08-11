<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\contract\PluginApiCallableTrait;
use app\common\contract\PluginApiInterface;
use app\common\service\config\AiConfigService;
use app\common\service\search\SearchConfigService;
use app\common\service\search\SmartSearchConfigService;

/** L1 ai_config 对外 API（跨插件经 PluginApiRegistry 调用） */
final class AiConfigPublicApi implements PluginApiInterface
{
    use PluginApiCallableTrait;

    public function pluginIdentifier(): string
    {
        return 'ai_config';
    }

    public function isActive(): bool
    {
        return app(AiConfigService::class)->isEnabled();
    }

    public function isKnowledgeSearchEnabled(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return app(SearchConfigService::class)->isAiAnswerEnabled()
            || app(SmartSearchConfigService::class)->fallbackEnabled();
    }
}
