<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 文档回收站：还原、彻底删除、清空
 */
declare(strict_types=1);

namespace app\common\service\document;

use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\plugin\registry\PluginExtensionRegistry;
use app\common\support\AppTime;

use app\common\support\ServiceResult;

use app\common\model\Document;
use think\facade\Db;

final class DocumentRecycleService
{

    public function __construct(
        private readonly DocumentRecycleOpsDeps $ops,
    ) {
    }

    /**
     * @param list<int> $ids
     * @return ServiceResult
     */
    public function batchRestoreAdmin(array $ids): ServiceResult
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ServiceResult::fail('请选择文章');
        }

        $now   = AppTime::now();
        $count = 0;
        Db::transaction(function () use ($ids, $now, &$count): void {
            foreach ($ids as $id) {
                $updated = Document::where('id', $id)->whereNotNull('deleted_at')->update([
                    'deleted_at' => null,
                    'updated_at' => $now,
                ]);
                if ($updated > 0) {
                    $count++;
                }
            }
        });

        if ($count < 1) {
            return ServiceResult::fail('没有可还原的文章');
        }

        $this->ops->auditLogService->operate('批量还原文档', 'admin.document', ['ids' => $ids, 'count' => $count]);
        $this->ops->siteModeService->clearPageCache();
        foreach ($ids as $id) {
            $this->ops->searchIndexService->syncDocumentById((int) $id);
            $this->ops->staticHtmlDispatch->afterArticleChange((int) $id, 'publish');
        }

        return ServiceResult::ok(['count' => $count], "已还原 {$count} 篇文章");
    }

    /**
     * 回收站：彻底删除（仅 deleted_at 非空）
     *
     * @param list<int> $ids
     * @return ServiceResult
     */
    public function purgeRecycleAdmin(array $ids): ServiceResult
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ServiceResult::fail('请选择文章');
        }

        $count = 0;
        Db::transaction(function () use ($ids, &$count): void {
            foreach ($ids as $id) {
                if ($this->purgeDocumentPermanently($id)) {
                    $count++;
                }
            }
        });

        if ($count < 1) {
            return ServiceResult::fail('没有可彻底删除的文章');
        }

        $this->ops->auditLogService->operate('回收站彻底删除', 'admin.document', ['ids' => $ids, 'count' => $count]);
        $this->ops->siteModeService->clearPageCache();

        return ServiceResult::ok(['count' => $count], "已彻底删除 {$count} 篇文章");
    }

    /** 清空回收站（彻底删除全部软删文档） */
    public function emptyRecycleBinAdmin(): ServiceResult
    {
        $ids = Document::whereNotNull('deleted_at')->column('id');
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ServiceResult::fail('回收站已是空的');
        }

        return $this->purgeRecycleAdmin($ids);
    }

    public function purgeDocumentPermanently(int $id): bool
    {
        $article = Document::where('id', $id)->whereNotNull('deleted_at')->find()?->toArray();
        if (!$article) {
            return false;
        }

        $this->ops->eventBusService->dispatch('document.deleted', ['document_id' => $id]);

        $this->ops->mediaAssetRefService->releaseDocument($id);
        $this->ops->tagService->detachDocumentTags($id);
        foreach (app(PluginExtensionRegistry::class)->enabledDocumentAddonBridges() as $bridge) {
            $pluginId = $bridge->identifier();
            if ($pluginId !== '' && method_exists($bridge, 'purgeForDocument')) {
                DocumentAddonBridgeAccess::invoke($pluginId, 'purgeForDocument', [$id]);
            }
        }
        Document::where('id', $id)->delete();
        $this->ops->staticHtmlDispatch->afterArticleDelete($id, $article);
        $this->ops->searchIndexService->removeDocument($id);

        return true;
    }
}
