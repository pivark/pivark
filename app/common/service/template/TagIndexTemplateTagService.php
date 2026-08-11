<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\support\SiteUrl;

/** `{pv:tagindex}` 块标签：标签索引页循环控制器注入的 `{$tags}` */
class TagIndexTemplateTagService
{

    /**
     * @param array<string, string> $attrs
     * @param array<string, mixed>  $pageVars
     */
    public function renderBlock(array $attrs, string $tpl, array $pageVars): string
    {
        $ctx = app(ListPageTemplateContextService::class);
        if (!$ctx->isTagCatalogActive($pageVars)) {
            return $ctx->rejectTagIndexTag($pageVars);
        }

        $tpl = trim($tpl);
        if ($tpl === '') {
            return app(TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                'tagindex',
                '块内 item=tag，例：<a href="{$tag.url}">{$tag.name}</a>'
            );
        }

        $itemName = trim((string) ($attrs['item'] ?? $attrs['id'] ?? 'tag'));
        if ($itemName === '') {
            $itemName = 'tag';
        }

        $name = trim((string) ($attrs['name'] ?? 'tags'));
        $list = $name === '' || $name === 'tags'
            ? ($pageVars['tags'] ?? [])
            : app(TemplateTagParser::class)->resolvePath($pageVars, $name);
        if (!is_array($list) || $list === []) {
            return '';
        }

        $list = $this->applySlice($list, $attrs);
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
        $limit = (int) ($attrs['limit'] ?? $attrs['loop'] ?? $attrs['row'] ?? 0);
        if ($limit > 0) {
            $list = array_slice($list, 0, $limit);
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $url = trim((string) ($row['url'] ?? ''));
        if ($url === '') {
            $row['url'] = SiteUrl::tagFromRow($row);
        }
        $tid = (int) ($row['id'] ?? 0);
        if ($tid > 0 && !isset($row['document_count'])) {
            $row['document_count'] = (int) ($row['use_count'] ?? 0);
        }

        return $row;
    }
}
