<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace install\support;

use app\common\support\WeappIdentifierAlias;

/**
 * 安装向导演示种子上下文（InstallSeedService 写入，install/assets/seed 只读）
 *
 * 装完可随 install/ 删除；日常后台勿依赖本类。
 * identifier 别名归一 → WeappIdentifierAlias（app 常驻）
 */
final class InstallSeedContext
{
    /** @var list<string>|null */
    private static ?array $plugins = null;

    private static ?bool $importDemo = null;

    /** @var array<string, list<string>> */
    private const PLUGIN_TAG_SLUGS = [
        'doc_bundle' => ['pv-demo-download'],
        'doc_gallery'    => ['pv-demo-gallery'],
        'doc_vod'  => ['pv-demo-video'],
        'doc_ask'     => [],
        'doc_comment' => [],
        'doc_thumb' => [],
    ];

    /** @var list<string> */
    private const CORE_TAG_SLUGS = [
        'pv-demo-news',
        'pv-demo-product',
        'pv-demo-cat-digital',
        'pv-demo-cat-service',
        'pv-demo-cat-resource',
    ];

    /**
     * @param list<string> $plugins
     */
    public static function begin(array $plugins, bool $importDemo): void
    {
        self::$plugins = array_values(array_unique(array_filter(array_map(
            static fn (string $id): string => strtolower(trim($id)),
            $plugins
        ))));
        self::$importDemo = $importDemo;
    }

    public static function reset(): void
    {
        self::$plugins    = null;
        self::$importDemo = null;
    }

    public static function isActive(): bool
    {
        return self::$importDemo !== null;
    }

    public static function importDemo(): bool
    {
        if (self::$importDemo !== null) {
            return self::$importDemo;
        }

        $raw = getenv('PIVARK_INSTALL_IMPORT_DEMO');
        if (is_string($raw) && $raw !== '') {
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        }

        return true;
    }

    public static function hasPlugin(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }
        $selected = self::selectedPlugins();
        if ($selected === []) {
            return false;
        }

        return in_array($identifier, $selected, true);
    }

    /**
     * @return list<string>
     */
    public static function selectedPlugins(): array
    {
        if (self::$plugins !== null) {
            return self::$plugins;
        }

        $raw = getenv('PIVARK_INSTALL_PLUGINS');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $id): string => strtolower(trim($id)),
            explode(',', $raw)
        )));
    }

    /**
     * 华仪智控完整演示站应写入的栏目 slug（核心 + 已选插件栏目）
     *
     * @return list<string>
     */
    public static function allowedTagSlugs(): array
    {
        if (!self::importDemo()) {
            return [];
        }

        $slugs = self::CORE_TAG_SLUGS;
        foreach (self::selectedPlugins() as $pluginId) {
            foreach (self::PLUGIN_TAG_SLUGS[WeappIdentifierAlias::normalize($pluginId)] ?? [] as $slug) {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    public static function allowsDocumentTags(array $tagSlugs): bool
    {
        if (!self::importDemo()) {
            return false;
        }
        $allowed = array_fill_keys(self::allowedTagSlugs(), true);
        foreach ($tagSlugs as $slug) {
            $slug = trim((string) $slug);
            if ($slug !== '' && isset($allowed[$slug])) {
                return true;
            }
        }

        return false;
    }

    /** @deprecated 用 WeappIdentifierAlias::normalize；保留给装机脚本兼容 */
    public static function normalizePluginIdentifier(string $pluginId): string
    {
        return WeappIdentifierAlias::normalize($pluginId);
    }
}
