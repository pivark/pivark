<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;

use app\common\support\AppTime;
use app\common\support\OpsLog;
use app\common\model\Document;


use app\common\service\config\ConfigService;
use app\common\service\search\SearchIndexService;
use app\common\service\seo\SitemapService;

/** 文档定时发布 / 下架（阶段 5 A-01） */
class DocumentScheduleService
{

    public function __construct(
        private readonly SearchIndexService $searchIndexService,
        private readonly ConfigService $configService,
        private readonly SitemapService $sitemapService,
    ) {
    }

    /**
     * @return array{published:int,offline:int,msg:string}
     */
    public function processDue(): array
    {
        $now = AppTime::now();
        $published = 0;
        $offline     = 0;

        $pubRows = Document::where('status', 0)
            ->whereNull('deleted_at')
            ->whereNotNull('schedule_publish_at')
            ->where('schedule_publish_at', '<=', $now)
            ->field('id')
            ->select()
            ->toArray();

        foreach ($pubRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            Document::where('id', $id)->update([
                'status'              => 1,
                'published_at'        => $now,
                'schedule_publish_at' => null,
                'updated_at'          => $now,
            ]);
            $published++;
            $this->searchIndexService->syncDocumentById($id);
            if ((string) $this->configService->get('seo_sitemap_auto_update', '1') === '1') {
                try {
                    $this->sitemapService->rebuildAdmin();
                } catch (\Throwable $e) { OpsLog::businessWarning('document_schedule_optional_failed', ['msg' => $e->getMessage()]); }
            }
            if (trim((string) $this->configService->get('seo_baidu_push_token', '')) !== '') {
                try {
                    $this->sitemapService->pushDocumentAdmin($id);
                } catch (\Throwable $e) { OpsLog::businessWarning('document_schedule_optional_failed', ['msg' => $e->getMessage()]); }
            }
        }

        $offRows = Document::where('status', 1)
            ->whereNull('deleted_at')
            ->whereNotNull('schedule_offline_at')
            ->where('schedule_offline_at', '<=', $now)
            ->field('id')
            ->select()
            ->toArray();

        foreach ($offRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            Document::where('id', $id)->update([
                'status'             => 0,
                'schedule_offline_at' => null,
                'updated_at'         => $now,
            ]);
            $offline++;
            $this->searchIndexService->removeDocument($id);
        }

        return [
            'published' => $published,
            'offline'   => $offline,
            'msg'       => "定时发布 {$published} 篇，下架 {$offline} 篇",
        ];
    }
}
