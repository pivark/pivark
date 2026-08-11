<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

final class TemplateTagdocumentsPrefetch
{

    public function warmFromHtml(string $html, ?string $theme = null): void
    {
        $expanded = app(TemplateIncludeExpander::class)->expandForPrefetch($html, $theme);
        $first    = !TemplateEngineState::$tagdocumentsPrefetched;
        TemplateEngineState::$tagdocumentsPrefetched = true;

        if ($first) {
            app(TemplateTagdocumentsBatchService::class)->warmFromHtml($expanded);
            app(TemplateTagcloudBatchService::class)->warmFromHtml($expanded);
        } else {
            app(TemplateTagdocumentsBatchService::class)->appendFromHtml($expanded);
            app(TemplateTagcloudBatchService::class)->appendFromHtml($expanded);
        }

        $this->prefetchNonMergeable($expanded);
    }

    private function prefetchNonMergeable(string $expanded): void
    {
        $expanded = app(TemplateTagParser::class)->normalizeTagAliases($expanded);
        if (!preg_match_all('/\{pv:arclist\b((?:[^{}]|\{\$[a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)*\})*)\}/i', $expanded, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $m) {
            $attrs  = app(TemplateTagParser::class)->parseAttrs($m[1]);
            $params = app(TemplateBlockRenderer::class)->listPublicParamsFromTagAttrs($attrs);
            if (app(TemplateTagdocumentsBatchService::class)->canMerge($params)) {
                continue;
            }
            app(\app\common\service\document\DocumentPublicService::class)->listPublic($params);
        }
    }
}
