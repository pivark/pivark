<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\model\Document;
use app\common\model\Form;
use app\common\model\FormSubmission;
use app\common\model\FavoriteStat;
use app\common\service\access\AccessStatsService;
use app\common\service\site\SiteFormService;
use app\common\support\AppTime;
use app\common\support\DbTable;

/** 后台站点运营驾驶舱聚合（访问/表单/待办） */
class AdminCockpitService
{

    public function __construct(
        private readonly AccessStatsService $accessStatsService,
        private readonly SiteFormService $siteFormService,
        private readonly AdminDashboardService $adminDashboardService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $dash = $this->accessStatsService->dashboard();
        $ext  = $this->accessStatsService->analyticsDashboard();

        $forms = 0;
        $submissions = 0;
        if (DbTable::modelExists(Form::class)) {
            $forms = (int) Form::count();
        }
        if (DbTable::modelExists(FormSubmission::class)) {
            $submissions = (int) FormSubmission::count();
        }

        $favoriteLikes = 0;
        $favoriteCollects = 0;
        if (DbTable::modelExists(FavoriteStat::class)) {
            $favoriteLikes = (int) FavoriteStat::sum('like_count');
            $favoriteCollects = (int) FavoriteStat::sum('collect_count');
        }

        $pendingInquiry = $this->siteFormService->countPendingForSlug('contact');

        return [
            'generated_at' => AppTime::now(),
            'overview'     => $this->adminDashboardService->overviewStats(),
            'stats'        => $dash,
            'analytics'    => $ext,
            'forms'        => ['total' => $forms, 'submissions' => $submissions],
            'favorite'     => ['likes' => $favoriteLikes, 'collects' => $favoriteCollects],
            'inquiries'    => ['pending' => $pendingInquiry],
            'schedule'     => [
                'pending_publish' => (int) Document::where('status', 0)
                    ->whereNull('deleted_at')
                    ->whereNotNull('schedule_publish_at')
                    ->where('schedule_publish_at', '>', AppTime::now())
                    ->count(),
                'pending_offline' => (int) Document::where('status', 1)
                    ->whereNull('deleted_at')
                    ->whereNotNull('schedule_offline_at')
                    ->where('schedule_offline_at', '>', AppTime::now())
                    ->count(),
            ],
        ];
    }
}
