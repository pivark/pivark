<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;
use app\common\service\front\FrontRenderService;
use app\common\service\site\SiteModeService;
use app\common\service\template\TemplateEngine;
use app\common\support\HttpResponseFinish;
use app\common\support\OpsLog;

final class PageCacheWarmupService
{

    public function __construct(
        private readonly FrontRenderService $frontRender,
        private readonly TemplateEngine $templateEngine,
        private readonly SiteModeService $siteMode,
    ) {
    }

    private static bool $hooked = false;

    /** @var list<string> */
    private static array $pendingTemplates = [];

    public function scheduleDefault(): void
    {
        if (!(bool) config('pivark.page_cache_warm_enabled', true)) {
            return;
        }
        $templates = config('pivark.page_cache_warm_templates');
        if (!is_array($templates) || $templates === []) {
            $templates = ['home'];
        }
        foreach ($templates as $tpl) {
            $tpl = trim((string) $tpl);
            if ($tpl !== '') {
                $this->scheduleTemplate($tpl);
            }
        }
    }

    public function scheduleTemplate(string $template): void
    {
        if ($template === '') {
            return;
        }
        if (!in_array($template, self::$pendingTemplates, true)) {
            self::$pendingTemplates[] = $template;
        }
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function(function (): void {
                $this->runPending();
            });
        }
    }

    private function runPending(): void
    {
        $jobs = self::$pendingTemplates;
        self::$pendingTemplates = [];
        self::$hooked           = false;
        // 先结束 HTTP，再渲染预热，避免栏目删/存 AJAX 被拖到 10s 超时
        HttpResponseFinish::finishIfPossible();
        foreach ($jobs as $template) {
            try {
                if ($template === 'home') {
                    $this->frontRender->htmlHome();
                    continue;
                }
                $html = $this->templateEngine->renderUncached($template, []);
                if ($html !== '') {
                    $this->siteMode->setPageCache($template, [], $html);
                }
            } catch (\Throwable $e) { OpsLog::businessWarning('page_cache_warmup_optional_failed', ['msg' => $e->getMessage()]); }
        }
    }
}
