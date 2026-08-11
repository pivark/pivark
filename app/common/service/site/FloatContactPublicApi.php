<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\contract\PluginApiCallableTrait;
use app\common\contract\PluginApiInterface;

/** 悬浮联系对外 API（跨模块经 PluginApiRegistry 调用） */
final class FloatContactPublicApi implements PluginApiInterface
{
    use PluginApiCallableTrait;

    public function pluginIdentifier(): string
    {
        return 'float_contact';
    }

    public function isActive(): bool
    {
        return app(FloatContactConfigService::class)->isEnabled();
    }

    /** @return list<array<string, mixed>> */
    public function listPublic(): array
    {
        return app(FloatContactService::class)->listPublic();
    }
}
