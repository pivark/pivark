<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

/** `{pv:page}`（原 pagelist）块标签：列表页上一页/下一页与页码（须 list_page=1，与 {pv:list} 同页） */
class PaginationTemplateTagService
{

    /**
     * @param array<string, string> $attrs
     */
    public function renderBlock(array $attrs, string $tpl, array $pageVars, string $contextTag = 'page'): string
    {
        $ctx = app(ListPageTemplateContextService::class);
        if ($contextTag === 'tagpage') {
            if (!$ctx->isTagCatalogActive($pageVars)) {
                return $ctx->rejectTagPageTag($pageVars);
            }
        } elseif (!$ctx->isPaginationActive($pageVars)) {
            return $ctx->rejectPageTag($pageVars, $contextTag);
        }

        if ((int) ($pageVars['pagination_show'] ?? 0) !== 1) {
            $empty = trim((string) ($attrs['empty'] ?? ''));

            return $empty;
        }

        $tpl = trim($tpl);
        if ($tpl === '') {
            return app(TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                $contextTag,
                '块标签 item=pg，例：<li>{$pg.label}</li>'
            );
        }

        $itemName = trim((string) ($attrs['item'] ?? $attrs['id'] ?? 'pg'));
        if ($itemName === '') {
            $itemName = 'pg';
        }

        $parser = app(TemplateTagParser::class);
        $out    = '';
        if ($this->flagEnabled($attrs['with_prevnext'] ?? $attrs['prevnext'] ?? '1')) {
            $out .= $this->renderPrevNextShell($attrs, $pageVars, 'prev');
        }

        $list = $this->resolveItems($attrs, $pageVars);
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped    = $this->mapPageItem($row, $attrs);
            $childVars = array_merge($pageVars, [$itemName => $mapped, 'field' => $mapped]);
            $chunk     = $parser->applyItemFieldVars($tpl, $itemName, $mapped);
            $out      .= $parser->parseTags($chunk, $childVars);
        }

        if ($this->flagEnabled($attrs['with_prevnext'] ?? $attrs['prevnext'] ?? '1')) {
            $out .= $this->renderPrevNextShell($attrs, $pageVars, 'next');
        }

        $summary = '';
        if ($this->flagEnabled($attrs['show_summary'] ?? '1')) {
            $summary = $this->renderSummary($pageVars, $attrs);
        }

        $wrap = trim((string) ($attrs['wrap'] ?? $attrs['shell'] ?? ''));
        if ($wrap !== '') {
            return '<div class="' . htmlspecialchars($wrap, ENT_QUOTES, 'UTF-8') . '">' . $out . $summary . '</div>';
        }

        return $out . $summary;
    }

    /**
     * @deprecated 模板须用 `{pv:page}` / `{pv:tagpage}` 块标签；仅 WeappPaginationGateway 等 PHP 调用保留
     *
     * @param array<string, mixed>  $pageVars
     * @param array<string, string> $attrs
     */
    public function renderHtml(array $pageVars, array $attrs = []): string
    {
        $ctx = app(ListPageTemplateContextService::class);
        if (!$ctx->isPaginationActive($pageVars)) {
            return $ctx->rejectPageTag($pageVars);
        }

        if ((int) ($pageVars['pagination_show'] ?? 0) !== 1) {
            return '';
        }

        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // show_summary 只挂在 </ul> 外；禁止透传进 renderBlock（否则会进 <ul> 再输出一次）
        $blockAttrs = array_merge([
            'with_prevnext' => '1',
        ], $attrs, [
            'show_summary' => '0',
        ]);
        $lis = $this->renderBlock($blockAttrs, self::legacyPageItemTpl(), $pageVars);

        $navClass = trim((string) ($attrs['class'] ?? 'pagination-nav mt-4'));
        $ulClass  = trim((string) ($attrs['list_class'] ?? 'pagination justify-content-center mb-0'));
        $aria     = trim((string) ($attrs['aria_label'] ?? '分页'));
        $html     = '<nav aria-label="' . $h($aria) . '" class="' . $h($navClass) . '">'
            . '<ul class="' . $h($ulClass) . '">' . $lis . '</ul>';

        if ($this->flagEnabled($attrs['show_summary'] ?? '1')) {
            $html .= $this->renderSummary($pageVars, $attrs);
        }

        return $html . '</nav>';
    }

    private static function legacyPageItemTpl(): string
    {
        return <<<'TPL'
{pv:if name="pg.ellipsis" value="1"}
<li class="page-item disabled"><span class="page-link">{$pg.label}</span></li>
{pv:else}
{pv:if name="pg.active" value="1"}
<li class="page-item {$pg.currentclass}"><span class="page-link" aria-current="page">{$pg.label}</span></li>
{pv:else}
<li class="page-item"><a class="page-link" href="{$pg.url}">{$pg.label}</a></li>
{/pv:if}
{/pv:if}
TPL;
    }

    /**
     * @param array<string, string> $attrs
     * @param array<string, mixed>  $pageVars
     */
    private function renderPrevNextShell(array $attrs, array $pageVars, string $which): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($which === 'prev') {
            $disabled = (int) ($pageVars['pagination_has_prev'] ?? 0) !== 1 ? ' disabled' : '';
            $label    = trim((string) ($attrs['prev_label'] ?? '上一页'));

            return '<li class="page-item' . $disabled . '"><a class="page-link" href="'
                . $h((string) ($pageVars['pagination_prev_url'] ?? '#')) . '" aria-label="' . $h($label)
                . '"><span aria-hidden="true">&laquo;</span></a></li>';
        }

        $disabled = (int) ($pageVars['pagination_has_next'] ?? 0) !== 1 ? ' disabled' : '';
        $label    = trim((string) ($attrs['next_label'] ?? '下一页'));

        return '<li class="page-item' . $disabled . '"><a class="page-link" href="'
            . $h((string) ($pageVars['pagination_next_url'] ?? '#')) . '" aria-label="' . $h($label)
            . '"><span aria-hidden="true">&raquo;</span></a></li>';
    }

    /**
     * @param array<string, mixed>  $pageVars
     * @param array<string, string> $attrs
     */
    private function renderSummary(array $pageVars, array $attrs): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $summaryClass = trim((string) ($attrs['summary_class'] ?? 'text-center text-muted small mt-2 mb-0'));
        $summary = '共 ' . (int) ($pageVars['pagination_total'] ?? 0) . ' 条，第 '
            . (int) ($pageVars['pagination_page'] ?? 1) . ' / '
            . (int) ($pageVars['pagination_total_pages'] ?? 1) . ' 页';

        return '<p class="' . $h($summaryClass) . '">' . $h($summary) . '</p>';
    }

    /**
     * @param array<string, string> $attrs
     * @return list<array<string, mixed>>
     */
    private function resolveItems(array $attrs, array $pageVars): array
    {
        $name = trim((string) ($attrs['name'] ?? 'pagination_items'));
        $list = $name === '' || $name === 'pagination_items'
            ? ($pageVars['pagination_items'] ?? [])
            : app(TemplateTagParser::class)->resolvePath($pageVars, $name);

        if (!is_array($list)) {
            return [];
        }

        return $this->applySlice($list, $attrs);
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<string, string> $attrs
     * @return array<string, mixed>
     */
    private function mapPageItem(array $row, array $attrs): array
    {
        $activeClass = trim((string) ($attrs['currentclass'] ?? 'active'));
        $isActive    = (int) ($row['active'] ?? 0) === 1;
        $navClass    = $isActive ? $activeClass : '';
        $row['currentclass'] = $navClass;
        $row['nav_class']    = $navClass;

        return $row;
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

    private function flagEnabled(mixed $raw): bool
    {
        if ($raw === true || $raw === 1) {
            return true;
        }
        $s = strtolower(trim((string) $raw));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
