<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 后台「清除缓存」统一入口（顶栏按钮 / index/clearCache）
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\support\OpsLog;
use app\common\support\ServiceResult;



use app\common\service\site\SiteModeService;
use app\common\service\static\StaticBuildQueueService;
use app\common\service\static\StaticHtmlService;
use think\facade\Cache;

class AdminCacheService
{

    public function __construct(
        private readonly SiteModeService $siteModeService,
        private readonly StaticHtmlService $staticHtmlService,
        private readonly StaticBuildQueueService $staticBuildQueueService,
    ) {
    }

    /**
     * @return ServiceResult
     */
    public function clearAdminCaches(): ServiceResult
    {
        app(AdminSpaMenuRouteCacheService::class)->bustAll();
        $cleared  = [];
        $warnings = [];

        try {
            $this->siteModeService->clearRuntimeCaches();
            $cleared[] = '运行时缓存';
        } catch (\Throwable $e) {
            $warnings[] = '运行时缓存: ' . $e->getMessage();
            OpsLog::businessWarning('admin_cache_clear_runtime_failed', ['msg' => $e->getMessage()]);
        }

        try {
            Cache::clear();
            $cleared[] = '应用缓存';
        } catch (\Throwable $e) {
            $warnings[] = '应用缓存: ' . $e->getMessage();
            OpsLog::businessWarning('admin_cache_clear_app_failed', ['msg' => $e->getMessage()]);
        }

        try {
            if ($this->staticHtmlService->enabled()) {
                if ($this->staticBuildQueueService->asyncBuildEnabled()) {
                    $this->staticBuildQueueService->scheduleFullRebuild();
                    $cleared[] = '静态 HTML（已入队重建）';
                } else {
                    $purgeStats = ['deleted' => 0];
                    $this->staticHtmlService->purgeAll($purgeStats);
                    $cleared[] = '静态 HTML 索引';
                    $warnings[] = '请到「HTML 生成」Tab 执行整站生成';
                }
            } else {
                $purgeStats = ['deleted' => 0];
                $this->staticHtmlService->purgeAll($purgeStats);
                if (($purgeStats['deleted'] ?? 0) > 0) {
                    $cleared[] = '静态 HTML 残留';
                }
            }
        } catch (\Throwable $e) {
            $warnings[] = '静态页: ' . $e->getMessage();
            OpsLog::businessWarning('admin_cache_clear_static_failed', ['msg' => $e->getMessage()]);
        }

        if (function_exists('opcache_reset')) {
            try {
                if (@opcache_reset()) {
                    $cleared[] = 'Opcode 缓存';
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Opcode: ' . $e->getMessage();
                OpsLog::businessWarning('admin_cache_clear_opcache_failed', ['msg' => $e->getMessage()]);
            }
        }

        if ($cleared === [] && $warnings === []) {
            return ServiceResult::ok(null, '无需清除的内容');
        }

        $msg = '已清除：' . implode('、', $cleared);
        if ($warnings !== []) {
            $msg .= '（提示：' . implode('；', array_slice($warnings, 0, 2)) . '）';
        }

        return ServiceResult::ok(null, $msg);
    }
}
