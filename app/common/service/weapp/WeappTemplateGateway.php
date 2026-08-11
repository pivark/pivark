<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappTemplateGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\template\TemplateEngine;
use app\common\service\template\TemplateTagParser;

final class WeappTemplateGateway
{

    public function __construct(
        private readonly TemplateEngine $templateEngine,
        private readonly TemplateTagParser $templateTagParser,
    ) {
    }

    /**
     * @param callable|array{0:class-string|object,1:string} $handler
     */
    /** @param callable|array{0:class-string|object,1:string} $handler */
    public function templateRegisterExtensionTag(string $name, callable|array $handler): void
    {
        $this->templateEngine->registerPluginTag($name, $handler);
    }

    /** @param array<string, mixed> $pageVars */
    public function templateParseTags(string $tpl, array $pageVars): string
    {
        return $this->templateEngine->parseTags($tpl, $pageVars);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $pageVars
     * @param array<string, mixed>       $loopAttrs
     */
    public function templateRenderItemLoop(
        string $tpl,
        array $items,
        array $pageVars,
        string $fieldName = 'field',
        array $loopAttrs = [],
    ): string {
        return $this->templateTagParser->renderItemLoop($tpl, $items, $pageVars, $fieldName, $loopAttrs);
    }
}
