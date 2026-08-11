<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\infra\PageCacheWarmupService;
use app\common\service\theme\ThemeService;
use app\common\support\HttpResponseFinish;
use app\common\support\OpsLog;
use think\facade\Cache;

/**
 * 取数标签预热：对齐 PageCacheWarmupService，挂 FrontCacheInvalidator 之后静默调度。
 * 不另造失效策略；键带 fc_gen + theme_id。
 */
final class TemplateTagDataWarmupService
{
    private const MAX_PAGE_TEMPLATES = 24;

    private const WARM_MARK_TTL = 600;

    private static bool $hooked = false;

    private static bool $pending = false;

    public function __construct(
        private readonly ThemeTemplateTagInventoryService $inventory,
        private readonly PageCacheWarmupService $pageCacheWarmup,
        private readonly ThemeService $themeService,
    ) {
    }

    /** invalidate 之后调用：shutdown 中扫清单 + 预取（先 finish HTTP，不阻塞后台 AJAX） */
    public function scheduleAfterInvalidate(): void
    {
        self::$pending = true;
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function(function (): void {
                $this->runPending();
            });
        }
    }

    private function runPending(): void
    {
        if (!self::$pending) {
            return;
        }
        self::$pending = false;
        self::$hooked = false;
        HttpResponseFinish::finishIfPossible();
        try {
            $inv = $this->inventory->scanCurrent();
            $this->feedPageWarmup($inv['templates']);
            $this->prefetchArclistData($inv['tags'], $inv['theme_id']);
            $this->markWarmed($inv['theme_id']);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('template_tag_data_warmup_failed', [
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /** @internal 门禁/探针可直接调用 */
    public function warmGuestPublic(?string $themeId = null): void
    {
        $themeId = $themeId ?? $this->themeService->getCurrentTheme();
        $inv = $this->inventory->scanCurrent($themeId);
        $this->feedPageWarmup($inv['templates']);
        $this->prefetchArclistData($inv['tags'], $themeId);
        $this->markWarmed($themeId);
    }

    /**
     * @param list<string> $templates
     */
    private function feedPageWarmup(array $templates): void
    {
        $this->pageCacheWarmup->scheduleTemplate('home');
        $n = 0;
        foreach ($templates as $tpl) {
            $tpl = trim($tpl);
            if ($tpl === '' || $tpl === 'home') {
                continue;
            }
            $this->pageCacheWarmup->scheduleTemplate($tpl);
            $n++;
            if ($n >= self::MAX_PAGE_TEMPLATES) {
                break;
            }
        }
    }

    /**
     * @param list<array{tag: string, attrs: array<string, string>, file: string, tpl: string}> $tags
     */
    private function prefetchArclistData(array $tags, string $themeId): void
    {
        $pageVars = $this->guestResolveVars();
        $parser = app(TemplateTagParser::class);
        $renderer = app(TemplateBlockRenderer::class);
        $batch = app(TemplateTagdocumentsBatchService::class);
        $batch->reset();

        foreach ($tags as $hit) {
            if (($hit['tag'] ?? '') !== 'arclist') {
                continue;
            }
            try {
                $attrs = $hit['attrs'] ?? [];
                $resolved = [];
                $skip = false;
                foreach ($attrs as $k => $v) {
                    $rv = $parser->resolveAttrValue((string) $v, $pageVars);
                    if ($this->attrNeedsValue((string) $k) && $rv === '' && $this->attrLooksDynamic((string) $v)) {
                        $skip = true;
                        break;
                    }
                    $resolved[(string) $k] = $rv;
                }
                if ($skip) {
                    continue;
                }
                if ($this->attrsLookMemberOnly($resolved)) {
                    continue;
                }
                $params = $renderer->listPublicParamsFromTagAttrs($resolved, $pageVars);
                if ($batch->canMerge($params)) {
                    $batch->registerFromAttrs($resolved);
                } else {
                    app(\app\common\service\document\DocumentPublicService::class)->listPublic($params);
                }
            } catch (\Throwable $e) {
                OpsLog::businessWarning('template_tag_data_warmup_tag_failed', [
                    'tag'  => 'arclist',
                    'file' => (string) ($hit['file'] ?? ''),
                    'msg'  => $e->getMessage(),
                ]);
            }
        }

        try {
            $batch->execute();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('template_tag_data_warmup_batch_failed', [
                'theme' => $themeId,
                'msg'   => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function guestResolveVars(): array
    {
        try {
            $cfg = app(\app\common\service\config\ConfigService::class)->getAll();

            return app(HomeBlockSiteVars::class)->vars(is_array($cfg) ? $cfg : []);
        } catch (\Throwable) {
            return [];
        }
    }

    private function attrNeedsValue(string $key): bool
    {
        $key = strtolower($key);

        return in_array($key, ['navid', 'nav_id', 'typeid', 'tagid', 'id'], true);
    }

    private function attrLooksDynamic(string $value): bool
    {
        return str_contains($value, '{$') || str_starts_with(ltrim($value), '$');
    }

    /** @param array<string, string> $attrs */
    private function attrsLookMemberOnly(array $attrs): bool
    {
        foreach (['member', 'login', 'vip', 'price'] as $hint) {
            foreach ($attrs as $k => $v) {
                if (str_contains(strtolower((string) $k), $hint) || str_contains(strtolower((string) $v), $hint)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function markWarmed(string $themeId): void
    {
        $gen = app(FrontCacheInvalidator::class)->generation();
        $key = 'pv_tag_data_warm_g' . $gen . '_' . preg_replace('/[^a-z0-9_\-]/i', '_', $themeId);
        try {
            Cache::set($key, 1, self::WARM_MARK_TTL);
        } catch (\Throwable) {
            // ignore
        }
    }

    public function wasWarmedForCurrent(?string $themeId = null): bool
    {
        $themeId = $themeId ?? $this->themeService->getCurrentTheme();
        $gen = app(FrontCacheInvalidator::class)->generation();
        $key = 'pv_tag_data_warm_g' . $gen . '_' . preg_replace('/[^a-z0-9_\-]/i', '_', $themeId);
        try {
            $v = Cache::get($key);

            return $v !== null && $v !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
