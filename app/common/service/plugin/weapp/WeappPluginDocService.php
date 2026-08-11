<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\weapp;

use app\common\support\LocalFile;
use app\common\support\ProjectPaths;
use app\common\support\WeappPluginReadmeParser;
use app\common\support\WeappPublicAsset;

/**
 * 插件用户文档 SSOT
 *
 * 1. weapp/{id}/README.md → ## 功能介绍 / ## 使用说明（后台 Tab + 应用市场，无需发布）
 * 2. 同文件 ## 升级日志 → WeappPluginChangelogService（后台 / 市场「升级日志」Tab）
 * 3. 兼容旧版 admin/view/_guide_body.php · _usage_body.php · 独立 CHANGELOG.md
 */
final class WeappPluginDocService
{
    public const SECTION_GUIDE = 'guide';
    public const SECTION_USAGE = 'usage';

    /** @var list<string> */
    private const GUIDE_SECTION_TITLES = ['功能介绍', '产品介绍', '插件介绍'];

    /** @var list<string> README 非标准标题时合并为「商品详情」 */
    private const GUIDE_FALLBACK_SECTION_TITLES = ['能做什么', '交付状态', '核心能力', '功能特性', '产品能力'];

    /** @var list<string> */
    private const USAGE_SECTION_TITLES = ['使用说明', '前台调用', '模板调用', 'API 与集成'];

    /** @var list<string> */
    private const USAGE_FALLBACK_SECTION_TITLES = ['后台路径', '交付门禁', 'SVG 管线', '安装与开发', '快速开始', '五步上手'];

    public function html(string $identifier, string $section = self::SECTION_GUIDE): string
    {
        $plugin = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
        if ($plugin === '') {
            return '';
        }

        $section = $section === self::SECTION_USAGE ? self::SECTION_USAGE : self::SECTION_GUIDE;

        $fromReadme = $this->htmlFromReadme($plugin, $section);
        if ($fromReadme !== '') {
            return $fromReadme;
        }

        $path = $this->legacySectionPath($plugin, $section);
        if ($path === null) {
            if ($section === self::SECTION_GUIDE && $this->hasSection($plugin, self::SECTION_USAGE)) {
                return $this->html($plugin, self::SECTION_USAGE);
            }

            return '';
        }

        ob_start();
        include $path;
        $html = (string) ob_get_clean();

        return WeappPublicAsset::normalizeDocHtml($html, $plugin);
    }

    public function hasSection(string $identifier, string $section = self::SECTION_GUIDE): bool
    {
        $plugin = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
        if ($plugin === '') {
            return false;
        }

        $section = $section === self::SECTION_USAGE ? self::SECTION_USAGE : self::SECTION_GUIDE;
        if ($this->readmeSectionRaw($plugin, $section) !== '') {
            return true;
        }

        return $this->legacySectionPath($plugin, $section) !== null;
    }

    private function htmlFromReadme(string $plugin, string $section): string
    {
        $raw = $this->readmeSectionRaw($plugin, $section);
        if ($raw === '') {
            return '';
        }

        $html = WeappPluginReadmeParser::toHtml($raw);

        return WeappPublicAsset::normalizeDocHtml($html, $plugin);
    }

    private function readmeSectionRaw(string $plugin, string $section): string
    {
        $path = $this->readmePath($plugin);
        if ($path === null) {
            return '';
        }

        $markdown = trim(LocalFile::getContents($path) ?: '');
        if ($markdown === '') {
            return '';
        }

        if ($section === self::SECTION_USAGE) {
            $raw = WeappPluginReadmeParser::sectionContent($markdown, self::USAGE_SECTION_TITLES);
            if ($raw !== '') {
                return $raw;
            }

            return WeappPluginReadmeParser::mergedSectionsContent(
                $markdown,
                self::USAGE_FALLBACK_SECTION_TITLES,
                true
            );
        }

        $raw = WeappPluginReadmeParser::sectionContent($markdown, self::GUIDE_SECTION_TITLES);
        if ($raw !== '') {
            return $raw;
        }

        return WeappPluginReadmeParser::mergedSectionsContent(
            $markdown,
            self::GUIDE_FALLBACK_SECTION_TITLES,
            true
        );
    }

    private function readmePath(string $plugin): ?string
    {
        $weapp = ProjectPaths::root() . '/weapp/' . $plugin . '/README.md';
        if (is_readable($weapp)) {
            return $weapp;
        }

        if (!$this->isL1KernelModule($plugin)) {
            return null;
        }

        $kernel = ProjectPaths::root() . '/app/common/service/' . $plugin . '/README.md';

        return is_readable($kernel) ? $kernel : null;
    }

    private function isL1KernelModule(string $plugin): bool
    {
        static $ids = null;
        if ($ids === null) {
            $path = ProjectPaths::root() . '/config/kernel/l1_modules.php';
            $raw  = is_file($path) ? include $path : [];
            $ids  = is_array($raw) ? array_values(array_map(
                static fn ($id): string => strtolower(trim((string) $id)),
                $raw
            )) : [];
        }

        return in_array($plugin, $ids, true);
    }

    private function legacySectionPath(string $plugin, string $section): ?string
    {
        $file = $section === self::SECTION_USAGE ? '_usage_body.php' : '_guide_body.php';
        $path = ProjectPaths::root() . '/weapp/' . $plugin . '/admin/view/' . $file;
        if (!is_readable($path)) {
            return null;
        }

        return $path;
    }
}
