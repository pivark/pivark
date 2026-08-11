<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\service\site\SiteNavService;

/** `{pv:nav}` 块标签：前台导航专用循环（非通用 foreach） */
class NavigationTemplateTagService
{

    /**
     * @param array<string, string> $attrs
     */
    public function renderBlock(array $attrs, string $tpl, array $pageVars): string
    {
        $tpl = trim($tpl);
        if ($tpl === '') {
            return app(TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                'nav',
                '块内 item=n，例：<a href="{$n.url}">{$n.title}</a>'
            );
        }

        $itemName     = trim((string) ($attrs['item'] ?? $attrs['id'] ?? 'nav'));
        $currentClass = trim((string) ($attrs['currentclass'] ?? 'active'));
        if ($itemName === '') {
            $itemName = 'nav';
        }
        if ($currentClass === '') {
            $currentClass = 'active';
        }

        $list = $this->resolveList($attrs, $pageVars);
        $list = $this->filterByContentKind($list, $attrs);
        $list = $this->applySlice($list, $attrs);
        if ($list === []) {
            return '';
        }

        $nav    = app(SiteNavService::class);
        $parser = app(TemplateTagParser::class);
        $out    = '';
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped    = $nav->mapPublicNodeForTemplate($row, $currentClass);
            $childVars = array_merge($pageVars, [$itemName => $mapped, 'field' => $mapped]);
            $chunk     = $parser->applyItemFieldVars($tpl, $itemName, $mapped);
            $out      .= $parser->parseTags($chunk, $childVars);
        }

        return $out;
    }

    /**
     * @param array<string, string> $attrs
     * @return list<array<string, mixed>>
     */
    private function resolveList(array $attrs, array $pageVars): array
    {
        $name = trim((string) ($attrs['name'] ?? 'site_nav'));
        if ($name === '' || $name === 'site_nav') {
            $list = app(SiteNavService::class)->listPublicTreeWithActive();
            $parentTitle = trim((string) ($attrs['parent_title'] ?? $attrs['parent'] ?? ''));
            if ($parentTitle !== '') {
                return app(SiteNavService::class)->listPublicChildrenByTitle($parentTitle);
            }
            if (isset($attrs['parent_id'])) {
                $parentId = (int) $attrs['parent_id'];
                $list     = $parentId > 0
                    ? app(SiteNavService::class)->listPublicChildrenOf($parentId)
                    : $list;
            }

            return $list;
        }

        $resolved = app(TemplateTagParser::class)->resolvePath($pageVars, $name);

        return is_array($resolved) ? $resolved : [];
    }

    /**
     * 侧栏可只展 document/product 真分类，排除首页/外链等。
     *
     * @param list<array<string, mixed>> $list
     * @param array<string, string>      $attrs
     * @return list<array<string, mixed>>
     */
    private function filterByContentKind(array $list, array $attrs): array
    {
        $kind = strtolower(trim((string) ($attrs['content_kind'] ?? $attrs['kind'] ?? '')));
        if ($kind === '' || $kind === 'all') {
            return $list;
        }
        $allowed = array_values(array_filter(array_map('trim', explode(',', $kind))));
        if ($allowed === []) {
            return $list;
        }

        return $this->filterTreeByContentKinds($list, $allowed);
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param list<string>               $allowed
     * @return list<array<string, mixed>>
     */
    private function filterTreeByContentKinds(array $list, array $allowed): array
    {
        $nav = app(SiteNavService::class);
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $children = $this->filterTreeByContentKinds(
                is_array($row['children'] ?? null) ? $row['children'] : [],
                $allowed
            );
            $rowKind = strtolower(trim((string) ($row['content_kind'] ?? '')));
            if ($rowKind === '') {
                $resolved = $nav->mapPublicNodeForTemplate($row, 'active');
                $rowKind = strtolower(trim((string) ($resolved['content_kind'] ?? '')));
            }
            $keep = in_array($rowKind, $allowed, true) || $children !== [];
            if (!$keep) {
                continue;
            }
            $row['children'] = $children;
            $row['has_children'] = $children !== [];
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param array<string, string>     $attrs
     * @return list<array<string, mixed>>
     */
    private function applySlice(array $list, array $attrs): array
    {
        $offset = max(0, (int) ($attrs['offset'] ?? 0));
        if ($offset > 0) {
            $list = array_slice($list, $offset);
        }
        $limit = (int) ($attrs['limit'] ?? $attrs['loop'] ?? 0);
        if ($limit > 0) {
            $list = array_slice($list, 0, $limit);
        }

        return $list;
    }
}
