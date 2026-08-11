<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\front;

use app\common\service\template\TemplateEngine;

/** 模板标签 {pv:frontassets} — 输出本页登记的按需脚本 */
final class FrontAssetTagService
{

    public function __construct(
        private readonly TemplateEngine $templateEngine,
        private readonly FrontAssetRegistry $frontAssetRegistry,
    ) {
    }

    public function boot(): void
    {
        $this->templateEngine->registerKernelTag(
            'frontassets',
            fn () => $this->frontAssetRegistry->renderHtml()
        );
    }
}
