<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 媒体资产影子引用：多篇文档可共用同一实体文件，删文档只释放引用。
 */
declare(strict_types=1);

namespace app\common\service\media;

use app\common\support\AppTime;
use app\common\service\media\MediaOrphanQueueService;
use app\common\service\media\MediaAssetService;
use app\common\service\media\MediaLibraryPathService;
use app\common\support\DbTable;
use app\common\model\MediaAssetAlias;
use app\common\model\MediaAssetRef;
use app\common\model\MediaAsset;
use app\common\model\Document;
use app\common\support\OpsLog;

class MediaAssetRefService
{

    public function __construct(
        private readonly MediaAssetService $mediaAssetService,
        private readonly MediaOrphanQueueService $mediaOrphanQueueService,
        private readonly MediaLibraryPathService $mediaLibraryPathService,
    ) {
    }

    public const TYPE_LITPIC = 'document_litpic';
    public const TYPE_CONTENT = 'document_content';
    public const TYPE_DOWNLOAD = 'document_download';
    public const TYPE_VIDEO = 'document_video';
    public const TYPE_VIDEO_COVER = 'document_video_cover';
    public const TYPE_LIBRARY = 'library';

    private static ?bool $tableReady = null;

    public function tableExists(): bool
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }
        self::$tableReady = DbTable::modelExists(MediaAssetRef::class);

        return self::$tableReady;
    }

    public function isEnabled(): bool
    {
        return $this->tableExists();
    }

    /**
     * @return array{ok:bool,count:int,refs?:list<array<string,mixed>>}
     */
    public function canDeletePath(string $pathOrUrl): array
    {
        $usage = $this->describePathUsage($pathOrUrl);

        return [
            'ok'    => (bool) ($usage['ok'] ?? false),
            'count' => (int) ($usage['count'] ?? 0),
            'refs'  => $usage['raw_refs'] ?? [],
        ];
    }

    /**
     * 删除前占用详情（按文档聚合，供素材库提示）
     *
     * @return array{
     *   ok:bool,
     *   count:int,
     *   media_asset_id:int,
     *   ref_tracking:bool,
     *   usages:list<array{
     *     document_id:int,
     *     title:string,
     *     in_recycle:bool,
     *     slots:list<string>
     *   }>,
     *   raw_refs?:list<array<string,mixed>>
     * }
     */
    public function describePathUsage(string $pathOrUrl, int $limit = 100): array
    {
        $path = $this->normalizePath($pathOrUrl);
        $asset = $path !== '' ? $this->mediaAssetService->findByPath($path) : null;
        $assetId = $asset ? (int) ($asset['id'] ?? 0) : 0;

        if (!$this->isEnabled()) {
            return [
                'ok'             => true,
                'count'          => 0,
                'media_asset_id' => $assetId,
                'ref_tracking'   => false,
                'usages'         => [],
            ];
        }

        if ($path === '') {
            return [
                'ok'             => true,
                'count'          => 0,
                'media_asset_id' => $assetId,
                'ref_tracking'   => true,
                'usages'         => [],
            ];
        }

        $count = $this->countRefsForPath($path);
        if ($count === 0) {
            return [
                'ok'             => true,
                'count'          => 0,
                'media_asset_id' => $assetId,
                'ref_tracking'   => true,
                'usages'         => [],
            ];
        }

        $refs = MediaAssetRef::where('path_snapshot', $path)
            ->field('ref_type,ref_id,field_key')
            ->limit(max(1, min(200, $limit)))
            ->select()
            ->toArray();

        return [
            'ok'             => false,
            'count'          => $count,
            'media_asset_id' => $assetId,
            'ref_tracking'   => true,
            'usages'         => $this->groupRefsForDisplay($refs),
            'raw_refs'       => $refs,
        ];
    }

    /**
     * @param list<array<string,mixed>> $refs
     * @return list<array{document_id:int,title:string,in_recycle:bool,slots:list<string>}>
     */
    private function groupRefsForDisplay(array $refs): array
    {
        $docIds = [];
        foreach ($refs as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type  = (string) ($row['ref_type'] ?? '');
            $refId = (int) ($row['ref_id'] ?? 0);
            if ($this->isDocumentRefType($type) && $refId > 0) {
                $docIds[] = $refId;
            }
        }

        $titles = $this->fetchDocumentTitles(array_values(array_unique($docIds)));
        $groups = [];

        foreach ($refs as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type      = (string) ($row['ref_type'] ?? '');
            $refId     = (int) ($row['ref_id'] ?? 0);
            $fieldKey  = (string) ($row['field_key'] ?? '');
            $slotLabel = $this->refSlotLabel($type, $fieldKey);

            if ($this->isDocumentRefType($type) && $refId > 0) {
                $key = 'doc_' . $refId;
                if (!isset($groups[$key])) {
                    $meta = $titles[$refId] ?? [];
                    $groups[$key] = [
                        'document_id' => $refId,
                        'title'       => (string) ($meta['title'] ?? ('文档 #' . $refId)),
                        'in_recycle'  => !empty($meta['deleted_at']),
                        'slots'       => [],
                    ];
                }
                $groups[$key]['slots'][] = $slotLabel;
            } else {
                $key = 'other_' . $type . '_' . $refId;
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'document_id' => 0,
                        'title'       => $this->nonDocumentRefTitle($type, $refId),
                        'in_recycle'  => false,
                        'slots'       => [],
                    ];
                }
                $groups[$key]['slots'][] = $slotLabel;
            }
        }

        $out = [];
        foreach ($groups as $group) {
            $group['slots'] = array_values(array_unique($group['slots']));
            $out[]          = $group;
        }

        usort($out, static function (array $a, array $b): int {
            $aid = (int) ($a['document_id'] ?? 0);
            $bid = (int) ($b['document_id'] ?? 0);
            if ($aid > 0 && $bid > 0) {
                return $aid <=> $bid;
            }
            if ($aid > 0) {
                return -1;
            }
            if ($bid > 0) {
                return 1;
            }

            return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{title:string,deleted_at?:string|null}>
     */
    private function fetchDocumentTitles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            $rows = Document::whereIn('id', $ids)
                ->field('id,title,deleted_at')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('media_asset_ref_document_titles_failed', ['msg' => $e->getMessage()]);

            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $map[$id] = [
                'title'      => (string) ($row['title'] ?? ('文档 #' . $id)),
                'deleted_at' => $row['deleted_at'] ?? null,
            ];
        }

        return $map;
    }

    private function isDocumentRefType(string $type): bool
    {
        return in_array($type, [
            self::TYPE_LITPIC,
            self::TYPE_CONTENT,
            self::TYPE_DOWNLOAD,
            self::TYPE_VIDEO,
            self::TYPE_VIDEO_COVER,
        ], true);
    }

    private function refSlotLabel(string $type, string $fieldKey): string
    {
        return match ($type) {
            self::TYPE_LITPIC      => '封面',
            self::TYPE_CONTENT     => str_starts_with($fieldKey, 'body') ? '正文插图' : '正文',
            self::TYPE_DOWNLOAD    => '下载附件',
            self::TYPE_VIDEO       => '视频',
            self::TYPE_VIDEO_COVER => '视频封面',
            self::TYPE_LIBRARY     => '素材库',
            default                => $type !== '' ? $type : '业务引用',
        };
    }

    private function nonDocumentRefTitle(string $type, int $refId): string
    {
        if ($type === self::TYPE_LIBRARY) {
            return '素材库引用';
        }

        return $refId > 0
            ? ('业务引用 #' . $refId)
            : '其它业务引用';
    }

    public function countRefsForPath(string $pathOrUrl): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }
        $path = $this->normalizePath($pathOrUrl);

        return $path === ''
            ? 0
            : (int) MediaAssetRef::where('path_snapshot', $path)->count();
    }

    /**
     * 一次性加载所有仍被引用的 uploads 路径（O(1) 查找，避免逐文件 COUNT）
     *
     * @return array<string, true>
     */
    public function referencedPathLookup(): array
    {
        $lookup = [];

        if ($this->isEnabled()) {
            $paths = MediaAssetRef::distinct(true)->column('path_snapshot');
            foreach ($paths as $path) {
                $path = $this->normalizePath((string) $path);
                if ($path !== '') {
                    $lookup[$path] = true;
                }
            }
        }

        try {
            $assets = MediaAsset::where('ref_count', '>', 0)
                ->field('path')
                ->select();
            foreach ($assets as $row) {
                $path = $this->normalizePath((string) ($row['path'] ?? ''));
                if ($path !== '') {
                    $lookup[$path] = true;
                }
            }
        } catch (\Throwable $e) {
            OpsLog::businessWarning('media_asset_path_lookup_failed', ['msg' => $e->getMessage()]);
        }

        return $lookup;
    }

    /**
     * @param list<string> $paths
     */
    public function attachPaths(string $refType, int $refId, string $fieldPrefix, array $paths): void
    {
        if (!$this->isEnabled() || $refId < 1) {
            return;
        }
        $i = 0;
        foreach ($paths as $path) {
            $normalized = $this->normalizePath((string) $path);
            if ($normalized === '') {
                continue;
            }
            $field = $fieldPrefix === '' ? ('p' . $i) : ($fieldPrefix . ($i > 0 ? '_' . $i : ''));
            $this->attach($refType, $refId, $field, $normalized);
            $i++;
        }
    }

    public function attach(string $refType, int $refId, string $fieldKey, string $pathOrUrl): void
    {
        if (!$this->isEnabled() || $refId < 1) {
            return;
        }
        $path = $this->normalizePath($pathOrUrl);
        if ($path === '') {
            return;
        }

        $assetId = $this->mediaAssetService->ensureRegisteredForPath($path);
        if ($assetId < 1) {
            return;
        }

        $exists = MediaAssetRef::where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->where('field_key', $fieldKey)
            ->find();
        if ($exists) {
            MediaAssetRef::where('id', (int) $exists['id'])->update([
                'media_asset_id'  => $assetId,
                'path_snapshot'   => $path,
            ]);
        } else {
            MediaAssetRef::insert([
                'media_asset_id' => $assetId,
                'ref_type'       => $refType,
                'ref_id'         => $refId,
                'field_key'      => mb_substr($fieldKey, 0, 64),
                'path_snapshot'  => $path,
                'created_at'     => AppTime::now(),
            ]);
        }

        $this->recalcRefCount($assetId);
    }

    public function releaseDocument(int $documentId): void
    {
        if (!$this->isEnabled() || $documentId < 1) {
            return;
        }

        $types = [
            self::TYPE_LITPIC,
            self::TYPE_CONTENT,
            self::TYPE_DOWNLOAD,
            self::TYPE_VIDEO,
            self::TYPE_VIDEO_COVER,
        ];

        $assetIds = MediaAssetRef::whereIn('ref_type', $types)
            ->where('ref_id', $documentId)
            ->column('media_asset_id');

        MediaAssetRef::whereIn('ref_type', $types)
            ->where('ref_id', $documentId)
            ->delete();

        foreach (array_unique(array_map('intval', $assetIds)) as $assetId) {
            if ($assetId > 0) {
                $this->recalcRefCount($assetId);
            }
        }
    }

    public function releaseType(int $refId, string $refType): void
    {
        if (!$this->isEnabled() || $refId < 1) {
            return;
        }
        $assetIds = MediaAssetRef::where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->column('media_asset_id');
        MediaAssetRef::where('ref_type', $refType)->where('ref_id', $refId)->delete();
        foreach (array_unique(array_map('intval', $assetIds)) as $assetId) {
            if ($assetId > 0) {
                $this->recalcRefCount($assetId);
            }
        }
    }

    public function recalcRefCount(int $mediaAssetId): void
    {
        if (!$this->mediaAssetService->tableExists() || $mediaAssetId < 1) {
            return;
        }
        $count = (int) MediaAssetRef::where('media_asset_id', $mediaAssetId)->count();
        MediaAsset::where('id', $mediaAssetId)->update([
            'ref_count'  => max(0, $count),
            'updated_at' => AppTime::now(),
        ]);

        $asset = MediaAsset::where('id', $mediaAssetId)->field('path')->find();
        $path  = is_array($asset) ? (string) ($asset['path'] ?? '') : '';
        if ($path === '') {
            return;
        }
        if ($count < 1) {
            $this->mediaOrphanQueueService->enqueue($path, $mediaAssetId, 'ref_zero');
        } else {
            $this->mediaOrphanQueueService->remove($path);
        }
    }

    /**
     * ref_count 为 0 时删除实体文件与索引（调用前需已释放所有 refs）
     */
    public function deletePhysicalIfUnreferenced(string $pathOrUrl): bool
    {
        $path = $this->normalizePath($pathOrUrl);
        if ($path === '' || $this->countRefsForPath($path) > 0) {
            return false;
        }

        $row = MediaAsset::where('path', $path)->find();
        if ($row && (int) ($row['ref_count'] ?? 0) > 0) {
            return false;
        }

        $real = $this->mediaLibraryPathService->resolveFileRealPathPublic($path);
        if ($real !== null && is_file($real)) {
            unlink($real);
        }
        $this->mediaAssetService->deleteByPath($path);
        MediaAssetAlias::where('media_asset_id', (int) ($row['id'] ?? 0))->delete();
        $this->mediaOrphanQueueService->remove($path);

        return true;
    }

    public function addAlias(int $mediaAssetId, string $displayName, int $createdBy = 0): void
    {
        if (!$this->isEnabled() || $mediaAssetId < 1) {
            return;
        }
        $name = trim($displayName);
        if ($name === '') {
            return;
        }
        try {
            MediaAssetAlias::insert([
                'media_asset_id' => $mediaAssetId,
                'display_name'   => mb_substr($name, 0, 255),
                'created_by'     => max(0, $createdBy),
                'created_at'     => AppTime::now(),
            ]);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('media_asset_alias_insert_failed', [
                'asset_id' => $mediaAssetId,
                'msg'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    public function extractUploadPathsFromHtml(string $html): array
    {
        if ($html === '') {
            return [];
        }
        $paths = [];
        if (preg_match_all('#/(?:uploads/[^"\'\s<>]+)#i', $html, $m)) {
            foreach ($m[0] as $url) {
                $paths[] = $this->normalizePath($url);
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    public function normalizePath(string $pathOrUrl): string
    {
        $path = trim(str_replace('\\', '/', $pathOrUrl));
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parts = parse_url($path);
            $path  = (string) ($parts['path'] ?? '');
        }
        $path = ltrim($path, '/');
        if ($path === '') {
            return '';
        }
        if (!str_starts_with($path, 'uploads/')) {
            if (str_contains($path, 'uploads/')) {
                $pos = strpos($path, 'uploads/');
                $path = substr($path, $pos);
            } else {
                return '';
            }
        }

        return $path;
    }
}
