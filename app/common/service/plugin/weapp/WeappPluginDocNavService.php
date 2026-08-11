<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\weapp;

use app\common\service\plugin\PluginManifestService;
use app\common\service\plugin\PluginService;
use app\common\service\product\ProductL1Access;

/** 后台插件文档 Tab 可见性（功能介绍 / 使用说明 / 升级日志） */
final class WeappPluginDocNavService
{
    /**
     * @param array<string, mixed> $manifest
     * @return array{guide:bool,usage:bool,changelog:bool}
     */
    public function tabsForAdmin(string $identifier, array $manifest = []): array
    {
        $identifier = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
        if ($identifier === '') {
            return ['guide' => false, 'usage' => false, 'changelog' => false];
        }

        if (ProductL1Access::isKernel($identifier)) {
            return ['guide' => false, 'usage' => false, 'changelog' => false];
        }

        if ($manifest === []) {
            $manifest = app(PluginService::class)->readManifest($identifier) ?? [];
        }

        return [
            'guide'     => app(WeappPluginDocService::class)->hasSection($identifier, WeappPluginDocService::SECTION_GUIDE),
            'usage'     => !$this->shouldHideUsageTab($identifier, $manifest)
                && app(WeappPluginDocService::class)->hasSection($identifier, WeappPluginDocService::SECTION_USAGE),
            'changelog' => app(WeappPluginChangelogService::class)->hasEntries($identifier),
        ];
    }

    /**
     * L4 经营应用且无前台模板标签 → 默认不展示「前台调用说明」（可用 admin.docs.hide_usage_tab 覆盖）
     *
     * @param array<string, mixed> $manifest
     */
    private function shouldHideUsageTab(string $identifier, array $manifest): bool
    {
        $docs = is_array($manifest['admin']['docs'] ?? null) ? $manifest['admin']['docs'] : [];
        if ((bool) ($docs['hide_usage_tab'] ?? false)) {
            return true;
        }

        $kind = strtolower(trim((string) ($manifest['kind'] ?? '')));
        if ($kind !== PluginManifestService::KIND_APPLICATION) {
            return false;
        }

        return !$this->manifestHasFrontendTemplateTags($manifest);
    }

    /** @param array<string, mixed> $manifest */
    private function manifestHasFrontendTemplateTags(array $manifest): bool
    {
        $surfaces = is_array($manifest['surfaces'] ?? null) ? $manifest['surfaces'] : [];
        $frontend = is_array($surfaces['frontend'] ?? null) ? $surfaces['frontend'] : [];
        $tags     = $frontend['template_tags'] ?? [];
        if (!is_array($tags) || $tags === []) {
            return false;
        }

        foreach ($tags as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                return true;
            }
            if (is_array($tag) && trim((string) ($tag['name'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
