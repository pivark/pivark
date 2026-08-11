<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;

use app\common\service\media\MediaAssetRefService;
use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\plugin\registry\PluginExtensionRegistry;

/** 文档保存后同步媒体影子引用（封面、正文 uploads 路径、插件本地附件） */
class DocumentAssetSyncService
{

    public function __construct(
        private readonly MediaAssetRefService $mediaAssetRefService,
    ) {
    }

    /**
     * @param array<string, mixed> $saveData 含 content / content_mobile / litpic
     */
    public function syncAfterDocumentSave(int $documentId, array $saveData): void
    {
        if (!$this->mediaAssetRefService->isEnabled() || $documentId < 1) {
            return;
        }

        $this->mediaAssetRefService->releaseDocument($documentId);

        $litpic = trim((string) ($saveData['litpic'] ?? ''));
        if ($litpic !== '') {
            $this->mediaAssetRefService->attach(
                MediaAssetRefService::TYPE_LITPIC,
                $documentId,
                'litpic',
                $litpic,
            );
        }

        $paths = $this->mediaAssetRefService->extractUploadPathsFromHtml((string) ($saveData['content'] ?? ''));
        $paths = array_merge(
            $paths,
            $this->mediaAssetRefService->extractUploadPathsFromHtml((string) ($saveData['content_mobile'] ?? '')),
        );
        $this->mediaAssetRefService->attachPaths(
            MediaAssetRefService::TYPE_CONTENT,
            $documentId,
            'body',
            array_values(array_unique($paths)),
        );
    }

    public function syncDownloadForDocument(int $documentId): void
    {
        if (!$this->mediaAssetRefService->isEnabled() || $documentId < 1 || app(PluginExtensionRegistry::class)->documentAddonBridgeIdentifiers() === []) {
            return;
        }

        $this->mediaAssetRefService->releaseType($documentId, MediaAssetRefService::TYPE_DOWNLOAD);

        $bundles = [];
        foreach (app(PluginExtensionRegistry::class)->documentAddonBridgeIdentifiers() as $identifier) {
            if (!DocumentAddonBridgeAccess::isEnabled($identifier)) {
                continue;
            }
            $part = DocumentAddonBridgeAccess::invokeOr([], $identifier, 'listForDocument', [$documentId]);
            if (is_array($part) && $part !== []) {
                $bundles = array_merge($bundles, $part);
            }
        }
        if ($bundles === []) {
            return;
        }
        $i = 0;
        foreach ($bundles as $bundle) {
            foreach (($bundle['items'] ?? []) as $item) {
                if (!is_array($item) || ($item['source_type'] ?? '') !== 'local') {
                    continue;
                }
                $path = trim((string) ($item['file_path'] ?? ''));
                if ($path === '') {
                    continue;
                }
                $this->mediaAssetRefService->attach(
                    MediaAssetRefService::TYPE_DOWNLOAD,
                    $documentId,
                    'item_' . $i,
                    $path,
                );
                $i++;
            }
        }
    }

    public function syncVideoForDocument(int $documentId): void
    {
        if (!$this->mediaAssetRefService->isEnabled() || $documentId < 1 || DocumentAddonBridgeAccess::bridgeIdentifierForMeta('document_media_sync') === null) {
            return;
        }

        $this->mediaAssetRefService->releaseType($documentId, MediaAssetRefService::TYPE_VIDEO);
        $this->mediaAssetRefService->releaseType($documentId, MediaAssetRefService::TYPE_VIDEO_COVER);

                $videoId = DocumentAddonBridgeAccess::bridgeIdentifierForMeta('document_media_sync');
        if ($videoId === null) {
            return;
        }
        $items = DocumentAddonBridgeAccess::invokeOr([], $videoId, 'listForDocument', [$documentId]);
        if (!is_array($items)) {
            return;
        }
        $i = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['video_url'] ?? ''));
            if ($url !== '' && str_starts_with($url, '/uploads/')) {
                $this->mediaAssetRefService->attach(
                    MediaAssetRefService::TYPE_VIDEO,
                    $documentId,
                    'video_' . $i,
                    $url,
                );
            }
            $cover = trim((string) ($item['cover_path'] ?? ''));
            if ($cover !== '' && str_starts_with($cover, '/uploads/')) {
                $this->mediaAssetRefService->attach(
                    MediaAssetRefService::TYPE_VIDEO_COVER,
                    $documentId,
                    'cover_' . $i,
                    $cover,
                );
            }
            $i++;
        }
    }
}
