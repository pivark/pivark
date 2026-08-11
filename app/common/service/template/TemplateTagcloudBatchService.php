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

/** 同页多个 `{pv:tagcloud}` 预取：合并 `TagPublicService::listPublicQuery` 请求 */
final class TemplateTagcloudBatchService
{

    /** @var array<string, array<string, mixed>> */
    private static array $paramSets = [];

    public function reset(): void
    {
        self::$paramSets = [];
    }

    public function warmFromHtml(string $html): void
    {
        $this->reset();
        $this->appendFromHtml($html);
    }

    public function appendFromHtml(string $html): void
    {
        $html = app(TemplateTagParser::class)->normalizeTagAliases($html);
        if (!preg_match_all('/\{pv:tagcloud\b((?:[^{}]|\{\$[a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)*\})*)\}/i', $html, $matches, PREG_SET_ORDER)) {
            return;
        }

        $added = false;
        foreach ($matches as $m) {
            $params = app(TemplateBlockRenderer::class)->tagCatalogParamsFromAttrs(
                app(TemplateTagParser::class)->parseAttrs($m[1])
            );
            $key = $this->paramKey($params);
            if (isset(self::$paramSets[$key])) {
                continue;
            }
            self::$paramSets[$key] = $params;
            $added                 = true;
        }

        if (!$added) {
            return;
        }

        $this->execute();
    }

    private function execute(): void
    {
        foreach (self::$paramSets as $params) {
            app(TagService::class)->listPublicQuery($params);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function paramKey(array $params): string
    {
        ksort($params);

        return md5(json_encode($params, JSON_UNESCAPED_UNICODE) ?: '');
    }
}
