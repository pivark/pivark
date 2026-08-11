<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\kernel;

use app\common\service\front\FrontAssetTagService;
use app\common\service\hook\HookService;
use app\common\service\item\ItemTemplateTagService;
use app\common\service\member\MemberListTagService;
use app\common\service\plugin\registry\PluginApiRegistry;
use app\common\service\site\FloatContactTemplateTagService;
use app\common\service\template\TemplateFragmentHookService;

/** KernelBootstrapService 模板标签/Hook/API 依赖包 */
final class KernelBootstrapSurfaceDeps
{
    public function __construct(
        public readonly TemplateFragmentHookService $templateFragmentHookService,
        public readonly ItemTemplateTagService $itemTemplateTagService,
        public readonly FloatContactTemplateTagService $floatContactTemplateTagService,
        public readonly FrontAssetTagService $frontAssetTagService,
        public readonly MemberListTagService $memberListTagService,
        public readonly HookService $hookService,
        public readonly PluginApiRegistry $pluginApiRegistry,
    ) {
    }
}
