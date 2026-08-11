<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\content;

use app\common\contract\ImportIntentDelegateInterface;
use app\common\service\plugin\registry\PluginExtensionRegistry;

/** 导入意图：插件 catalog + kind 解析（L1 · 无 per-plugin 业务） */
final class ImportIntentResolveService
{

    /**
     * @return list<array{
     *   identifier:string,
     *   label:string,
     *   enabled:bool,
     *   intent_kinds:list<string>,
     *   channels:list<string>,
     *   priority:int
     * }>
     */
    public function catalog(): array
    {
        return app(PluginExtensionRegistry::class)->importIntentDelegateCatalog();
    }

    /**
     * @return list<string> plugin identifiers，按 priority 排序
     */
    public function suggestPluginsForKind(string $intentKind, string $channel = 'paste'): array
    {
        $intentKind = strtolower(trim($intentKind));
        $channel    = strtolower(trim($channel));
        if ($intentKind === '') {
            return [];
        }

        return app(PluginExtensionRegistry::class)->importIntentSuggestPlugins($intentKind, $channel);
    }

    public function getDelegate(string $identifier): ?ImportIntentDelegateInterface
    {
        return app(PluginExtensionRegistry::class)->getImportIntentDelegate($identifier);
    }
}
