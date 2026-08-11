<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\static;

/** 静态同步：异步队列 or 同步直写 */
final class StaticHtmlDispatch
{

    public function afterArticleChange(int $id, string $scene = 'publish'): void
    {
        if (!app(StaticHtmlService::class)->enabled()) {
            return;
        }
        if (app(StaticBuildQueueService::class)->asyncBuildEnabled()) {
            app(StaticBuildQueueService::class)->enqueueAfterArticleChange($id, $scene);

            return;
        }
        app(StaticHtmlService::class)->syncAfterArticleChange($id, $scene);
    }

    /**
     * @param list<int> $ids
     */
    public function afterBatchArticleChange(array $ids, string $scene = 'edit'): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if (!app(StaticHtmlService::class)->enabled() || $ids === []) {
            return;
        }
        if (app(StaticBuildQueueService::class)->asyncBuildEnabled()) {
            foreach ($ids as $id) {
                app(StaticBuildQueueService::class)->enqueueAfterArticleChange($id, $scene);
            }

            return;
        }
        foreach ($ids as $id) {
            app(StaticHtmlService::class)->syncAfterArticleChange($id, $scene);
        }
    }

    public function afterArticleDelete(int $id, ?array $row = null): void
    {
        if (!app(StaticHtmlService::class)->enabled()) {
            return;
        }
        if (app(StaticBuildQueueService::class)->asyncBuildEnabled()) {
            if (is_array($row)) {
                app(StaticBuildQueueService::class)->enqueueWork(
                    ['t' => 'doc', 'id' => $id],
                    5
                );
            }
            app(StaticBuildQueueService::class)->enqueueWork(['t' => 'sys', 'k' => 'documents'], 80);

            return;
        }
        app(StaticHtmlService::class)->syncAfterArticleDelete($id, $row);
    }

    /**
     * @param list<int>              $ids
     * @param array<int, array<string, mixed>> $rowsById
     */
    public function afterBatchArticleDelete(array $ids, array $rowsById = []): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if (!app(StaticHtmlService::class)->enabled() || $ids === []) {
            return;
        }
        if (app(StaticBuildQueueService::class)->asyncBuildEnabled()) {
            foreach ($ids as $id) {
                $row = $rowsById[$id] ?? null;
                if (is_array($row)) {
                    app(StaticBuildQueueService::class)->enqueueWork(['t' => 'doc', 'id' => $id], 5);
                }
            }
            app(StaticBuildQueueService::class)->enqueueWork(['t' => 'sys', 'k' => 'documents'], 80);

            return;
        }
        foreach ($ids as $id) {
            app(StaticHtmlService::class)->syncAfterArticleDelete($id, $rowsById[$id] ?? null);
        }
    }

    public function afterTagChange(int $id): void
    {
        if (!app(StaticHtmlService::class)->enabled() || $id < 1) {
            return;
        }
        if (app(StaticBuildQueueService::class)->asyncBuildEnabled()) {
            app(StaticBuildQueueService::class)->enqueueTag($id);
            app(StaticBuildQueueService::class)->enqueueWork(['t' => 'home'], 90);

            return;
        }
        app(StaticHtmlService::class)->syncAfterTagChange($id);
    }

    public function afterTagDelete(?array $row): void
    {
        if (!app(StaticHtmlService::class)->enabled()) {
            return;
        }
        if (app(StaticBuildQueueService::class)->asyncBuildEnabled()) {
            app(StaticBuildQueueService::class)->enqueueWork(['t' => 'home'], 90);

            return;
        }
        app(StaticHtmlService::class)->syncAfterTagDelete($row);
    }

    public function afterNavOrSlideChange(): void
    {
        if (!app(StaticHtmlService::class)->enabled()) {
            return;
        }
        $queue = app(StaticBuildQueueService::class);
        if ($queue->asyncBuildEnabled()) {
            // 只入队首页 + 列表壳；禁 seedFramework（逐单页 upsert 拖慢 AJAX）
            $queue->enqueueWork(['t' => 'home'], 90);
            $queue->enqueueWork(['t' => 'sys', 'k' => 'documents'], 80);
            $queue->enqueueWork(['t' => 'sys', 'k' => 'tags'], 80);

            return;
        }
        // 无异步队列：只刷首页，避免栏目保存/删除同步重刷全站超时
        app(StaticHtmlService::class)->syncAfterNavChange();
    }

    /** 浮动联系等全站嵌入块：刷全部静态 HTML（先 purge，避免「未改跳过」漏页） */
    public function afterGlobalEmbedChange(): void
    {
        if (!app(StaticHtmlService::class)->enabled()) {
            return;
        }
        if (app(StaticBuildQueueService::class)->asyncBuildEnabled()) {
            app(StaticBuildQueueService::class)->scheduleFullRebuild();

            return;
        }
        app(StaticHtmlService::class)->syncAfterGlobalEmbedChange();
    }

    /** 品项 slug/状态变更：详情静态页待专用 work 类型；当前刷新首页队列 */
    public function afterProductItemChange(string $slug, string $status = ''): void
    {
        if (!app(StaticHtmlService::class)->enabled() || trim($slug) === '') {
            return;
        }
        if (app(StaticBuildQueueService::class)->asyncBuildEnabled()) {
            app(StaticBuildQueueService::class)->enqueueWork(['t' => 'home'], 85);

            return;
        }
    }
}
