<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

/** `{pv:breadcrumb}` 块标签：循环 `{$breadcrumbs}`，结构由模板写 */
class BreadcrumbTemplateTagService
{

    /**
     * @param array<string, string> $attrs
     */
    public function renderBlock(array $attrs, string $tpl, array $pageVars): string
    {
        $tpl = trim($tpl);
        if ($tpl === '') {
            return app(TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                'breadcrumb',
                '块标签 item=bc，例：<li>{$bc.title}</li>'
            );
        }

        $itemName = trim((string) ($attrs['item'] ?? $attrs['id'] ?? 'bc'));
        if ($itemName === '') {
            $itemName = 'bc';
        }

        $name = trim((string) ($attrs['name'] ?? 'breadcrumbs'));
        $list = $name === '' || $name === 'breadcrumbs'
            ? ($pageVars['breadcrumbs'] ?? [])
            : app(TemplateTagParser::class)->resolvePath($pageVars, $name);
        if (!is_array($list) || $list === []) {
            return '';
        }

        $parser = app(TemplateTagParser::class);
        $out    = '';
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped    = $this->mapRow($row);
            $childVars = array_merge($pageVars, [$itemName => $mapped, 'field' => $mapped]);
            $chunk     = $parser->applyItemFieldVars($tpl, $itemName, $mapped);
            $out      .= $parser->parseTags($chunk, $childVars);
        }

        $wrap = trim((string) ($attrs['wrap'] ?? ''));
        if ($wrap !== '') {
            return '<div class="' . htmlspecialchars($wrap, ENT_QUOTES, 'UTF-8') . '">' . $out . '</div>';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $active = (int) ($row['active'] ?? 0) === 1;
        $row['active']        = $active ? 1 : 0;
        $row['is_active']     = $active ? 1 : 0;
        $row['currentclass']  = $active ? trim((string) ($row['currentclass'] ?? 'active')) : '';
        $row['url_empty']     = trim((string) ($row['url'] ?? '')) === '' ? 1 : 0;

        return $row;
    }
}
