<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

/** `{pv:seo}` 块标签：默认 `{pv:seo /}` 一次输出 canonical/OG/Twitter；块体非空时可自定义 */
class SeoTemplateTagService
{

    /**
     * @param array<string, mixed> $pageVars
     */
    public function renderAuto(array $pageVars): string
    {
        return app(\app\common\service\seo\SeoTemplateService::class)->renderMetaHtml($pageVars);
    }

    /**
     * @param array<string, string> $attrs
     * @param array<string, mixed>  $pageVars
     */
    public function renderBlock(array $attrs, string $tpl, array $pageVars): string
    {
        unset($attrs);
        if (trim($tpl) === '') {
            return $this->renderAuto($pageVars);
        }

        $parser = app(TemplateTagParser::class);
        if (!preg_match('/\{pv:/i', $tpl)) {
            return $parser->applyPageVars($tpl, $pageVars);
        }

        return $parser->parseTags($tpl, $pageVars);
    }
}
