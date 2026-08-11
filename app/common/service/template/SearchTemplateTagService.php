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

/** `{pv:searchform}` 块标签：GET 搜索 form 壳 + 模板体 */
class SearchTemplateTagService
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
                'searchform',
                '块内写 input/button；例：<input type="search" name="{$search_param}" value="{$search_keyword}">'
            );
        }

        $parser = app(TemplateTagParser::class);
        $action = trim((string) ($attrs['action'] ?? ''));
        if ($action === '') {
            $action = (string) ($pageVars['search_url'] ?? SiteUrl::search());
        } else {
            $action = (string) $parser->resolveAttrValue($action, $pageVars);
        }
        if ($action === '') {
            $action = SiteUrl::search();
        }

        $method = strtolower(trim((string) ($attrs['method'] ?? 'get')));
        if (!in_array($method, ['get', 'post'], true)) {
            $method = 'get';
        }

        $param = trim((string) ($attrs['param'] ?? $attrs['name'] ?? 'q'));
        if ($param === '') {
            $param = 'q';
        }

        $formId    = trim((string) ($attrs['id'] ?? ''));
        $formClass = trim((string) ($attrs['class'] ?? ''));
        $role      = trim((string) ($attrs['role'] ?? 'search'));
        $hidden    = $this->renderHiddenInputs($attrs, $pageVars, $parser);

        $localVars = array_merge($pageVars, [
            'search_param'   => $param,
            'search_action'  => $action,
            'search_keyword' => (string) ($pageVars['search_keyword'] ?? ''),
        ]);
        $inner = $parser->parseTags($tpl, $localVars);
        $inner = $parser->applyPageVars($inner, $localVars);

        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attrsHtml = ' action="' . $h($action) . '" method="' . $h($method) . '" role="' . $h($role) . '"';
        if ($formId !== '') {
            $attrsHtml .= ' id="' . $h($formId) . '"';
        }
        if ($formClass !== '') {
            $attrsHtml .= ' class="' . $h($formClass) . '"';
        }

        return '<form' . $attrsHtml . '>' . $hidden . $inner . '</form>';
    }

    /**
     * @param array<string, string> $attrs
     */
    private function renderHiddenInputs(array $attrs, array $pageVars, TemplateTagParser $parser): string
    {
        $h   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '';
        foreach (['tag', 'kind', 'channel', 'sort', 'type'] as $key) {
            if (!isset($attrs[$key]) || trim((string) $attrs[$key]) === '') {
                continue;
            }
            $val = (string) $parser->resolveAttrValue((string) $attrs[$key], $pageVars);
            if ($val === '') {
                continue;
            }
            $out .= '<input type="hidden" name="' . $h($key) . '" value="' . $h($val) . '">';
        }

        $hiddenRaw = trim((string) ($attrs['hidden'] ?? ''));
        if ($hiddenRaw === '') {
            return $out;
        }
        foreach (preg_split('/[,&]/', $hiddenRaw) ?: [] as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name  = trim($name);
            $value = (string) $parser->resolveAttrValue(trim($value), $pageVars);
            if ($name === '') {
                continue;
            }
            $out .= '<input type="hidden" name="' . $h($name) . '" value="' . $h($value) . '">';
        }

        return $out;
    }
}
