<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\service\tag\TagService;

/** `{pv:tag}` 块标签：按 tagid/slug/名称等定位**唯一** Tag，输出名称/链接/描述等（与频道页 `{$tag_*}` 同源） */
class TagTemplateTagService
{

    /**
     * @param array<string, string> $attrs
     * @param array<string, mixed>  $pageVars
     */
    public function renderBlock(array $attrs, string $tpl, array $pageVars): string
    {
        $tpl = trim($tpl);
        if ($tpl === '') {
            return app(TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                'tag',
                '块内 item=t，例：<a href="{$t.url}">{$t.name}</a>；多条请用 {pv:tagcloud}'
            );
        }

        $slugs = app(ArclistTagResolveService::class)->resolveSlugList($attrs, $pageVars);
        if ($slugs === []) {
            return app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'tag',
                '须唯一 tagid / tags / tagname / navid / navtarget 等；多条请用 {pv:tagcloud}'
            );
        }
        if (count($slugs) > 1) {
            return app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'tag',
                '单 Tag 定位须唯一（当前解析到多个 slug）；列表请用 {pv:tagcloud}'
            );
        }

        $row = app(TagService::class)->findRowBySlug($slugs[0]);
        if (!is_array($row)) {
            return '';
        }

        $itemName = trim((string) ($attrs['item'] ?? $attrs['id'] ?? 't'));
        if ($itemName === '') {
            $itemName = 't';
        }

        $mapped    = $this->mapItemVars($row);
        $childVars = array_merge($pageVars, [$itemName => $mapped, 'field' => $mapped]);
        $chunk     = app(TemplateTagParser::class)->applyItemFieldVars($tpl, $itemName, $mapped);

        return app(TemplateTagParser::class)->parseTags($chunk, $childVars);
    }

    /**
     * @param array<string, mixed> $row tags 表行
     * @return array<string, mixed>
     */
    private function mapItemVars(array $row): array
    {
        $view = app(TagService::class)->buildPublicListViewVars($row);
        $tid  = (int) ($view['tag_id'] ?? 0);
        $docCount = 0;
        if ($tid > 0) {
            $counts   = app(TagService::class)->countPublishedDocumentsByTagIds([$tid]);
            $docCount = (int) ($counts[$tid] ?? 0);
        }

        return array_merge($view, [
            'id'               => $tid,
            'name'             => (string) ($view['tag_name'] ?? ''),
            'slug'             => (string) ($view['tag_slug'] ?? ''),
            'url'              => (string) ($view['tag_url'] ?? ''),
            'description'      => (string) ($view['tag_description'] ?? ''),
            'litpic'           => (string) ($view['tag_litpic'] ?? ''),
            'kind'             => (string) ($view['tag_kind'] ?? ''),
            'document_count'   => $docCount,
        ]);
    }
}
