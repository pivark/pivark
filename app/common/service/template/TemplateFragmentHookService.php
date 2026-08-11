<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;
use app\common\service\template\TemplateFragmentInvalidationMap;

use app\common\service\event\EventBusService;
use app\common\service\infra\FrontCacheInvalidator;

/** 内核：领域事件 → {pv:cache} 按 name 失效 */
final class TemplateFragmentHookService
{

    public function __construct(
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly TemplateFragmentInvalidationMap $templateFragmentInvalidationMap,
        private readonly EventBusService $eventBusService,
    ) {
    }

    private static bool $booted = false;

    public function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $invalidateDocumentFragments = function (): void {
            $this->frontCacheInvalidator->invalidateTemplateFragments(
                $this->templateFragmentInvalidationMap->namesForDocumentSave(),
            );
        };

        $this->eventBusService->listen('document.after_save', $invalidateDocumentFragments);
        $this->eventBusService->listen('document.deleted', $invalidateDocumentFragments);
    }
}
