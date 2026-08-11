<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\service\site\SiteModeService;

/**
 * `{pv:list}` / `{pv:page}` 列表页上下文：仅文档/搜索/频道列表控制器注入 `list_page=1` 后可用。
 * `{pv:list}` 输出控制器 `{$list}`；`{pv:page}` 输出同页上一页/下一页与页码条。
 * 首页、详情等请用 `{pv:arclist}`；标签索引等非文档列表页勿用 `{pv:page}`。
 */
class ListPageTemplateContextService
{

    public const VAR_KEY = 'list_page';

    public const TAG_CATALOG_VAR_KEY = 'tag_catalog_page';

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public function mark(array $vars): array
    {
        $vars[self::VAR_KEY] = 1;

        return $vars;
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public function markTagCatalog(array $vars): array
    {
        $vars[self::TAG_CATALOG_VAR_KEY] = 1;

        return $vars;
    }

    /**
     * @param array<string, mixed> $pageVars
     */
    public function isActive(array $pageVars): bool
    {
        return (int) ($pageVars[self::VAR_KEY] ?? 0) === 1;
    }

    /**
     * @param array<string, mixed> $pageVars
     */
    public function isTagCatalogActive(array $pageVars): bool
    {
        return (int) ($pageVars[self::TAG_CATALOG_VAR_KEY] ?? 0) === 1;
    }

    /**
     * @param array<string, mixed> $pageVars
     */
    public function isPaginationActive(array $pageVars): bool
    {
        return $this->isActive($pageVars) || $this->isTagCatalogActive($pageVars);
    }

    /**
     * 非列表页调用 `{pv:list}` 时的输出（dev 注释 / 线上空串）。
     *
     * @param array<string, mixed> $pageVars
     */
    public function rejectListTag(array $pageVars, string $tag = 'list'): string
    {
        return $this->rejectUnlessListPage($pageVars, $tag, '首页/侧栏请用 {pv:arclist}');
    }

    /**
     * 非列表页调用 `{pv:page}`（原 pagelist）时的输出。
     *
     * @param array<string, mixed> $pageVars
     */
    public function rejectPageTag(array $pageVars, string $tag = 'page'): string
    {
        return $this->rejectUnlessPaginationPage($pageVars, $tag, '仅文档列表（list_page=1）或标签索引（tag_catalog_page=1）');
    }

    /**
     * @param array<string, mixed> $pageVars
     */
    public function rejectTagPageTag(array $pageVars): string
    {
        return $this->rejectUnlessTagCatalogPage($pageVars, 'tagpage', '标签索引页 tag_catalog_page=1；文档列表请用 {pv:page}');
    }

    /**
     * 非标签索引页调用 `{pv:tagindex}` 时的输出。
     *
     * @param array<string, mixed> $pageVars
     */
    public function rejectTagIndexTag(array $pageVars): string
    {
        return $this->rejectUnlessTagCatalogPage($pageVars, 'tagindex', '标签索引页 tag_catalog_page=1；侧栏请用 {pv:tagcloud}');
    }

    /**
     * @param array<string, mixed> $pageVars
     */
    private function rejectUnlessPaginationPage(array $pageVars, string $tag, string $hint): string
    {
        if ($this->isPaginationActive($pageVars)) {
            return '';
        }

        if (app(SiteModeService::class)->isDev()) {
            return '<!-- pv:' . $tag . ' 缺少 list_page=1 或 tag_catalog_page=1；' . $hint . ' -->';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $pageVars
     */
    private function rejectUnlessTagCatalogPage(array $pageVars, string $tag, string $hint): string
    {
        if ($this->isTagCatalogActive($pageVars)) {
            return '';
        }

        if (app(SiteModeService::class)->isDev()) {
            return '<!-- pv:' . $tag . ' 缺少 tag_catalog_page=1；' . $hint . ' -->';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $pageVars
     */
    private function rejectUnlessListPage(array $pageVars, string $tag, string $hint): string
    {
        if ($this->isActive($pageVars)) {
            return '';
        }

        if (app(SiteModeService::class)->isDev()) {
            return '<!-- pv:' . $tag . ' 仅文档/搜索/频道列表页（list_page=1）；' . $hint . ' -->';
        }

        return '';
    }
}
