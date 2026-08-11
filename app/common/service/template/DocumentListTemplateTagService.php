<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\service\document\satellite\DocumentListTemplateService;
use app\common\service\theme\ThemeService;

/** `{pv:list}` 块标签：文档列表循环（非通用 foreach） */
class DocumentListTemplateTagService
{
    /**
     * 从列表页逻辑模板名读取首个 `{pv:list}` 的 row/loop/limit，供控制器分页取数。
     * 无标签或不写条数属性时返回 null（调用方回退默认 12）。
     */
    public function resolvePageSizeFromTpl(string $tpl): ?int
    {
        $tpl = trim($tpl);
        if ($tpl === '') {
            return null;
        }
        if (str_ends_with(strtolower($tpl), '.php')) {
            $tpl = substr($tpl, 0, -4);
        }

        $theme = app(ThemeService::class)->getCurrentTheme();
        $path  = app(ThemeService::class)->resolveSiteTemplatePathWithFallback($tpl . '.php', $theme);
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $raw = app(TemplateMetaService::class)->stripLeadMeta((string) file_get_contents($path));

        return $this->pageSizeFromListTagHtml($raw);
    }

    /**
     * 解析 HTML 中首个 `{pv:list …}` 的展示条数属性（row / loop / limit）。
     */
    public function pageSizeFromListTagHtml(string $html): ?int
    {
        if (!preg_match('/\{pv:(?:list|documentlist)\b([^}]*)\}/i', $html, $m)) {
            return null;
        }

        $attrs = app(TemplateTagParser::class)->parseAttrs(trim($m[1]));
        $limit = 0;
        if (isset($attrs['limit']) && preg_match('/^(\d+)\s*,\s*(\d+)$/', trim((string) $attrs['limit']), $lm)) {
            $limit = (int) $lm[2];
        } else {
            $limit = (int) ($attrs['row'] ?? $attrs['loop'] ?? 0);
            if ($limit <= 0 && isset($attrs['limit']) && preg_match('/^\d+$/', trim((string) $attrs['limit']))) {
                $limit = (int) trim((string) $attrs['limit']);
            }
        }

        return $limit > 0 ? $limit : null;
    }

    /**
     * @param array<string, string> $attrs
     */
    public function renderBlock(array $attrs, string $tpl, array $pageVars): string
    {
        $ctx = app(ListPageTemplateContextService::class);
        if (!$ctx->isActive($pageVars)) {
            return $ctx->rejectListTag($pageVars);
        }

        $tpl = trim($tpl);
        if ($tpl === '') {
            return app(TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                'list',
                '块标签 item=field，须 list_page=1；例：<span>{$field.title}</span>'
            );
        }

        $itemName = trim((string) ($attrs['item'] ?? $attrs['id'] ?? 'field'));
        if ($itemName === '') {
            $itemName = 'field';
        }

        $list = $this->resolveList($attrs, $pageVars);

        return $this->renderLoop($attrs, $tpl, $list, $pageVars, $itemName, true);
    }

    /**
     * 通用列表循环体渲染（list / arclist / renderItemLoop 共用）
     *
     * @param array<string, string>           $attrs
     * @param list<array<string, mixed>>       $items
     * @param array<string, mixed>            $pageVars
     */
    public function renderLoop(
        array $attrs,
        string $body,
        array $items,
        array $pageVars,
        string $itemName = 'field',
        bool $requireItemTpl = false,
    ): string {
        $parsed   = $this->parseLoopBody($body);
        $itemTpl  = $parsed['itemTpl'];
        $emptyTpl = $parsed['emptyTpl'];
        $seps     = $parsed['seps'];

        if ($requireItemTpl && $itemTpl === '' && $items !== []) {
            return app(TemplateTagBlockOnlyService::class)->rejectEmptyBlockBody(
                'list',
                '块标签 item=field，须 list_page=1；例：<span>{$field.title}</span>'
            );
        }

        if ($items === []) {
            $emptyOut = $emptyTpl;
            if ($emptyOut === '') {
                $emptyOut = trim((string) ($attrs['empty'] ?? ''));
            }
            if ($emptyOut === '') {
                return '';
            }

            return $this->wrapOutput(app(TemplateTagParser::class)->parseTags($emptyOut, $pageVars), $attrs);
        }

        $parser     = app(TemplateTagParser::class);
        $listSvc    = app(DocumentListTemplateService::class);
        $startIndex = $listSvc->resolveLoopStartFromAttrs($attrs);
        $out        = '';

        foreach ($items as $pos => $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = $listSvc->applyLoopIndexFields($row, (int) $pos, $startIndex, $attrs);
            $mapped = $listSvc->mapFieldAliases($mapped);
            $index  = (int) ($mapped['index'] ?? ($startIndex + (int) $pos));
            $vars   = array_merge($pageVars, [
                $itemName => $mapped,
                'field'   => $mapped,
                'item'    => $mapped,
            ]);

            foreach ($seps as $sep) {
                if ($sep['when'] === 'before' && $this->shouldRenderSeparator($index, $sep['every'], 'before')) {
                    $out .= $parser->parseTags($sep['tpl'], $vars);
                }
            }

            if ($itemTpl !== '') {
                $chunk  = $parser->applyItemFieldVars($itemTpl, $itemName, $mapped);
                $parsed = $parser->parseTags($chunk, $vars);
                $out   .= $parser->applyPageVars($parsed, $vars);
            }

            foreach ($seps as $sep) {
                if ($sep['when'] === 'after' && $this->shouldRenderSeparator($index, $sep['every'], 'after')) {
                    $out .= $parser->parseTags($sep['tpl'], $vars);
                }
            }
        }

        return $this->wrapOutput($out, $attrs);
    }

    /**
     * 从循环体拆出 item 模板、空态块、分隔块（仅 list/arclist 块体内有效）
     *
     * @return array{itemTpl:string,emptyTpl:string,seps:list<array{every:int,tpl:string,when:string}>}
     */
    public function parseLoopBody(string $body): array
    {
        $emptyTpl = '';
        if (preg_match('/\{pv:empty\}(.*)\{\/pv:empty\}/is', $body, $m)) {
            $emptyTpl = trim($m[1]);
            $body     = str_replace($m[0], '', $body);
        }

        $seps = [];
        $body = preg_replace_callback(
            '/\{pv:sep\b([^}]*)\}(.*)\{\/pv:sep\}/is',
            function (array $m) use (&$seps): string {
                $attrs = app(TemplateTagParser::class)->parseAttrs(trim($m[1]));
                $every = max(1, (int) ($attrs['every'] ?? $attrs['step'] ?? $attrs['mod'] ?? 5));
                $when  = strtolower(trim((string) ($attrs['when'] ?? $attrs['pos'] ?? 'after')));
                if (!in_array($when, ['after', 'before'], true)) {
                    $when = 'after';
                }
                $seps[] = [
                    'every' => $every,
                    'tpl'   => $m[2],
                    'when'  => $when,
                ];

                return '';
            },
            $body
        ) ?? $body;

        return [
            'itemTpl'  => trim($body),
            'emptyTpl' => $emptyTpl,
            'seps'     => $seps,
        ];
    }

    public function shouldRenderSeparator(int $index, int $every, string $when): bool
    {
        if ($every <= 0 || $index <= 0) {
            return false;
        }

        return $when === 'before'
            ? (($index - 1) % $every) === 0
            : ($index % $every) === 0;
    }

    /**
     * @param array<string, string> $attrs
     */
    private function wrapOutput(string $out, array $attrs): string
    {
        $wrap = trim((string) ($attrs['wrap'] ?? ''));
        if ($wrap === '') {
            return $out;
        }

        return '<div class="' . htmlspecialchars($wrap, ENT_QUOTES, 'UTF-8') . '">' . $out . '</div>';
    }

    /**
     * @param array<string, string> $attrs
     * @return list<array<string, mixed>>
     */
    private function resolveList(array $attrs, array $pageVars): array
    {
        $name = trim((string) ($attrs['name'] ?? 'list'));
        if ($name !== '' && $name !== 'list') {
            return [];
        }

        $list = $pageVars['list'] ?? [];

        if (!is_array($list)) {
            return [];
        }

        return $this->sliceList($list, $attrs);
    }

    /**
     * 列表页展示切片（row/limit/offset）。
     * 频道分页取数已由前端 `PaginationService::frontListPageSize($tpl)` 读同一 `row`；
     * 此处再切片是兼容「取数后只要前 N 条展示」的写法。
     *
     * @param list<array<string, mixed>> $list
     * @param array<string, string>     $attrs
     * @return list<array<string, mixed>>
     */
    public function sliceList(array $list, array $attrs): array
    {
        $offset = max(0, (int) ($attrs['offset'] ?? 0));
        if (isset($attrs['limit']) && preg_match('/^(\d+)\s*,\s*(\d+)$/', trim((string) $attrs['limit']), $lm)) {
            $offset = (int) $lm[1];
            $limit  = (int) $lm[2];
        } else {
            $limit = (int) ($attrs['row'] ?? $attrs['loop'] ?? 0);
            if ($limit <= 0 && isset($attrs['limit']) && preg_match('/^\d+$/', trim((string) $attrs['limit']))) {
                $limit = (int) trim((string) $attrs['limit']);
            }
        }

        if ($offset > 0) {
            $list = array_slice($list, $offset);
        }
        if ($limit > 0) {
            $list = array_slice($list, 0, $limit);
        }

        return $list;
    }
}
