<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\tag;

use app\common\service\template\TemplateTagParser;
use app\common\support\SiteUrl;

/** `{pv:tagnav}` 标签侧栏：自闭合快捷 HTML + 块标签自定义循环 */
class TagNavTemplateService
{

    /**
     * @param array<string, string> $attrs
     */
    public function renderBlock(array $attrs, string $tpl, array $pageVars): string
    {
        $tpl = trim($tpl);
        if ($tpl === '') {
            return app(\app\common\service\template\TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                'tagnav',
                '块标签 item=t，数据源 tags_nav；全站 Tag 目录用 {pv:tagcloud}'
            );
        }

        $itemName     = trim((string) ($attrs['item'] ?? $attrs['id'] ?? 't'));
        $currentClass = trim((string) ($attrs['currentclass'] ?? 'active'));
        if ($itemName === '') {
            $itemName = 't';
        }
        if ($currentClass === '') {
            $currentClass = 'active';
        }

        $parser = app(TemplateTagParser::class);
        $out    = '';

        $showAll = $this->flagEnabled($attrs['show_all'] ?? $attrs['all'] ?? '0');
        if ($showAll && $this->isRootList($attrs)) {
            $allRow = $this->buildAllRow($pageVars, $currentClass, $attrs);
            $childVars = array_merge($pageVars, [$itemName => $allRow, 'field' => $allRow]);
            $chunk     = $parser->applyItemFieldVars($tpl, $itemName, $allRow);
            $out      .= $parser->parseTags($chunk, $childVars);
        }

        $list = $this->resolveList($attrs, $pageVars);
        $list = $this->applySlice($list, $attrs);
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped    = $this->mapTagRow($row, $currentClass);
            $childVars = array_merge($pageVars, [$itemName => $mapped, 'field' => $mapped]);
            $chunk     = $parser->applyItemFieldVars($tpl, $itemName, $mapped);
            $out      .= $parser->parseTags($chunk, $childVars);
        }

        if ($this->flagEnabled($attrs['wrap'] ?? $attrs['shell'] ?? '0')) {
            return $this->wrapShell($out, $attrs);
        }

        return $out;
    }

    /**
     * @param array<string, string> $attrs
     */
    private function isRootList(array $attrs): bool
    {
        $name = trim((string) ($attrs['name'] ?? 'tags_nav'));

        return $name === '' || $name === 'tags_nav';
    }

    /**
     * @param array<string, mixed>  $pageVars
     * @param array<string, string> $attrs
     * @return array<string, mixed>
     */
    private function buildAllRow(array $pageVars, string $currentClass, array $attrs): array
    {
        $allClass = (string) ($pageVars['all_nav_class'] ?? '');
        if ($allClass === '') {
            // 「全部」是否高亮由控制器注入；勿默认 currentClass，避免每页都亮
            $allClass = '';
        } elseif ($allClass === 'btn-primary' || str_contains($allClass, 'btn-primary')) {
            $allClass = $currentClass !== '' ? $currentClass : 'active';
        } elseif (str_starts_with($allClass, 'btn-')) {
            $allClass = '';
        }

        return [
            'name'          => trim((string) ($attrs['all_label'] ?? '全部文档')),
            'slug'          => '',
            'url'           => (string) ($pageVars['documents_url'] ?? SiteUrl::documents()),
            'depth'         => 0,
            'nav_class'     => $allClass,
            'currentclass'  => $allClass,
            'is_all'        => 1,
            'has_children'  => 0,
            'children'      => [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapTagRow(array $row, string $currentClass): array
    {
        $navClass = trim((string) ($row['nav_class'] ?? ''));
        if ($navClass === '' && (int) ($row['is_active'] ?? 0) === 1) {
            $navClass = $currentClass;
        }
        // 侧栏列表高亮用 active；旧 btn-* 会与卡壳样式打架
        if ($navClass === 'btn-primary' || str_contains($navClass, 'btn-primary')) {
            $navClass = $currentClass !== '' ? $currentClass : 'active';
        } elseif (str_starts_with($navClass, 'btn-')) {
            $navClass = '';
        }
        $row['currentclass'] = $navClass;
        $row['nav_class']    = $navClass;
        if (!isset($row['name_display'])) {
            $row['name_display'] = (string) ($row['name'] ?? '');
        }
        $children = $row['children'] ?? [];
        $row['has_children'] = is_array($children) && $children !== [] ? 1 : 0;
        if (is_array($children) && $children !== []) {
            $mapped = [];
            foreach ($children as $child) {
                $mapped[] = is_array($child) ? $this->mapTagRow($child, $currentClass) : $child;
            }
            $row['children'] = $mapped;
        }

        return $row;
    }

    /**
     * @param array<string, string> $attrs
     * @param array<string, mixed>  $pageVars
     * @return list<array<string, mixed>>
     */
    private function resolveList(array $attrs, array $pageVars): array
    {
        $name = trim((string) ($attrs['name'] ?? 'tags_nav'));
        if ($name === '' || $name === 'tags_nav') {
            $list = $pageVars['tags_nav'] ?? [];

            return is_array($list) ? $list : [];
        }

        $resolved = app(TemplateTagParser::class)->resolvePath($pageVars, $name);

        return is_array($resolved) ? $resolved : [];
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param array<string, string>       $attrs
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

    /**
     * @param list<array<string, mixed>> $tags
     * @param callable(string): string   $h
     */
    private function renderBuiltInLinks(array $tags, callable $h, string $currentClass, int $depth = 0): string
    {
        $html = '';
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $mapped = $this->mapTagRow($tag, $currentClass);
            $depthVal = max($depth, (int) ($mapped['depth'] ?? 0));
            $pad = $depthVal > 0 ? ' style="padding-left:' . (12 + $depthVal * 14) . 'px"' : '';
            $label = (string) ($mapped['name_display'] ?? $mapped['name'] ?? '');
            $html .= '<a href="' . $h((string) ($mapped['url'] ?? '')) . '" class="channel-link '
                . $h((string) ($mapped['nav_class'] ?? '')) . '"' . $pad . '>';
            $html .= '<i class="bi bi-tag"></i> ' . $h($label) . '</a>';
            if (!empty($mapped['children']) && is_array($mapped['children'])) {
                $html .= $this->renderBuiltInLinks($mapped['children'], $h, $currentClass, $depthVal + 1);
            }
        }

        return $html;
    }

    /**
     * @param array<string, string> $attrs
     */
    private function wrapShell(string $inner, array $attrs): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title   = trim((string) ($attrs['title'] ?? '栏目导航'));
        $titleIcon = trim((string) ($attrs['title_icon'] ?? 'bi-grid-3x3-gap'));
        $navClass  = trim((string) ($attrs['nav_class'] ?? 'channel-nav'));
        $wrapClass = trim((string) ($attrs['wrap_class'] ?? 'sidebar-card pv-tags-nav'));
        $aria      = trim((string) ($attrs['aria_label'] ?? $title));
        $heading = $title !== ''
            ? '<h3 class="sidebar-title"><i class="bi ' . $h($titleIcon) . '"></i> ' . $h($title) . '</h3>'
            : '';

        return '<div class="' . $h($wrapClass) . '">' . $heading
            . '<nav class="' . $h($navClass) . '" aria-label="' . $h($aria) . '">' . $inner . '</nav></div>';
    }

    private function flagEnabled(mixed $raw): bool
    {
        if ($raw === true || $raw === 1) {
            return true;
        }
        $s = strtolower(trim((string) $raw));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
