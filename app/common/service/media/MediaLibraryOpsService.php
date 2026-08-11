<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split from MediaLibraryService — 浏览/删除/清理
 */
declare(strict_types=1);

namespace app\common\service\media;

use app\common\enum\ApiErrorCode;

use app\common\support\ServiceResult;
use app\common\service\media\MediaPurgeBatchService;
use app\common\service\media\MediaAssetService;
use app\common\service\media\MediaOrphanQueueService;
use app\common\service\media\MediaAssetRefService;
use app\common\service\media\MediaLibraryPathService;
use app\common\model\MediaAsset;
use app\common\model\MediaAssetAlias;
use app\common\model\Document;
use app\common\support\OpsLog;

use app\common\service\config\ConfigService;
use think\facade\Config;

class MediaLibraryOpsService
{

    public function __construct(
        private readonly MediaLibraryPathService $mediaLibraryPathService,
        private readonly MediaAssetRefService $mediaAssetRefService,
        private readonly MediaOrphanQueueService $mediaOrphanQueueService,
        private readonly MediaAssetService $mediaAssetService,
        private readonly MediaPurgeBatchService $mediaPurgeBatchService,
    ) {
    }

    /** 同步清理上限；超出则走分批任务 */
    private const SYNC_PURGE_MAX = 100;

    /** 预览接口建议走异步的阈值 */
    private const ASYNC_RECOMMEND_THRESHOLD = 100;

    /** @var array<string, true>|null */
    private static ?array $referencedPathCache = null;

    /**
     * @param array{page?:int,limit?:int,keyword?:string,folder?:string,upload_date?:string} $params
     * @return array{total:int,list:list<array>,folders:list<array{id:string,name:string,count:int}>,move_targets?:list<array{id:string,name:string}>}
     */
    public function listImages(array $params): array
    {
        $root = $this->mediaLibraryPathService->uploadRoot();
        if (!is_dir($root)) {
            return ['total' => 0, 'list' => [], 'folders' => [['id' => '', 'name' => '全部图片', 'count' => 0]]];
        }

        $allowed = $this->mediaLibraryPathService->allowedExtensions();

        $all = [];
        $this->mediaLibraryPathService->scanDir($root, $root, $allowed, $all);

        return $this->filterAndPaginateImageList($all, $params);
    }

    /**
     * 前台会员素材库：仅本人上传（media_asset_aliases.created_by）
     *
     * @param array{page?:int,limit?:int,keyword?:string,folder?:string,upload_date?:string} $params
     * @return array{total:int,list:list<array>,folders:list<array{id:string,name:string,count:int}>,move_targets?:list<array{id:string,name:string}>,page:int,limit:int}
     */
    public function listImagesForMember(int $memberId, array $params): array
    {
        $page  = max(1, (int) ($params['page'] ?? 1));
        $limit = max(1, min(60, (int) ($params['limit'] ?? 20)));
        $empty = [
            'total'        => 0,
            'list'         => [],
            'folders'      => [['id' => '', 'name' => '全部图片', 'count' => 0]],
            'move_targets' => [],
            'page'         => $page,
            'limit'        => $limit,
        ];
        if ($memberId < 1 || !$this->mediaAssetRefService->isEnabled()) {
            return $empty;
        }

        $aliasTable = (new MediaAssetAlias())->getTable();
        $paths = MediaAsset::alias('ma')
            ->join($aliasTable . ' a', 'a.media_asset_id = ma.id')
            ->where('a.created_by', $memberId)
            ->group('ma.path')
            ->column('ma.path');
        if ($paths === []) {
            return $empty;
        }

        $all = [];
        foreach ($paths as $path) {
            $relative = ltrim(str_replace('\\', '/', (string) $path), '/');
            if ($relative === '') {
                continue;
            }
            $real = $this->mediaLibraryPathService->resolveFileRealPathPublic('/' . $relative);
            if ($real === null || !is_file($real)) {
                continue;
            }
            $name = basename($real);
            $parts = explode('/', $relative);
            $top   = count($parts) > 1 ? (string) $parts[0] : '';
            $all[] = [
                'url'         => app(MediaUrlService::class)->formatForStorage('/uploads/' . $relative),
                'path'        => 'uploads/' . $relative,
                'name'        => $name,
                'folder_top'  => $top,
                'folder_path' => dirname($relative) === '.' ? '' : dirname($relative),
                'size'        => (int) filesize($real),
                'mtime'       => (int) filemtime($real),
            ];
        }

        return $this->filterAndPaginateImageList($all, $params);
    }

    public function memberOwnsUploadPath(int $memberId, string $pathOrUrl): bool
    {
        if ($memberId < 1 || !$this->mediaAssetRefService->isEnabled()) {
            return false;
        }
        $path = $this->mediaAssetRefService->normalizePath($pathOrUrl);
        if ($path === '') {
            return false;
        }
        $aliasTable = (new MediaAssetAlias())->getTable();

        return MediaAsset::alias('ma')
            ->join($aliasTable . ' a', 'a.media_asset_id = ma.id')
            ->where('a.created_by', $memberId)
            ->where('ma.path', $path)
            ->count() > 0;
    }

    /**
     * @param list<array<string,mixed>> $all
     * @param array{page?:int,limit?:int,keyword?:string,folder?:string,upload_date?:string} $params
     * @return array{total:int,list:list<array>,folders:list<array{id:string,name:string,count:int}>,move_targets?:list<array{id:string,name:string}>,page:int,limit:int}
     */
    private function filterAndPaginateImageList(array $all, array $params): array
    {
        $keyword = strtolower(trim((string) ($params['keyword'] ?? '')));
        $folder  = trim(str_replace('\\', '/', (string) ($params['folder'] ?? '')), '/');
        if (str_contains($folder, '..')) {
            $folder = '';
        }

        $folderCounts = ['' => 0];
        foreach ($all as $item) {
            $folderCounts[''] = ($folderCounts[''] ?? 0) + 1;
            $top = (string) ($item['folder_top'] ?? '');
            if ($top !== '') {
                $folderCounts[$top] = ($folderCounts[$top] ?? 0) + 1;
            }
        }

        if ($folder !== '') {
            $all = array_values(array_filter($all, static fn ($i) => ($i['folder_top'] ?? '') === $folder));
        }

        if ($keyword !== '') {
            $all = array_values(array_filter($all, static fn ($i) => str_contains(strtolower((string) ($i['name'] ?? '')), $keyword)));
        }

        $uploadDate = trim((string) ($params['upload_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $uploadDate) === 1) {
            $start = strtotime($uploadDate . ' 00:00:00');
            $end   = strtotime($uploadDate . ' 23:59:59');
            if ($start !== false && $end !== false) {
                $all = array_values(array_filter(
                    $all,
                    static fn ($i) => ($i['mtime'] ?? 0) >= $start && ($i['mtime'] ?? 0) <= $end
                ));
            }
        }

        usort($all, static fn ($a, $b) => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));

        $page  = max(1, (int) ($params['page'] ?? 1));
        $limit = max(1, min(60, (int) ($params['limit'] ?? 20)));
        $total = count($all);
        $list  = array_slice($all, ($page - 1) * $limit, $limit);

        $folders = [['id' => '', 'name' => '全部图片', 'count' => $folderCounts[''] ?? 0]];
        unset($folderCounts['']);
        ksort($folderCounts);
        foreach ($folderCounts as $id => $count) {
            $id = (string) $id;
            $folders[] = [
                'id'    => $id,
                'name'  => $this->mediaLibraryPathService->folderLabel($id),
                'count' => $count,
            ];
        }

        return [
            'total'         => $total,
            'list'          => $list,
            'folders'       => $folders,
            'move_targets'  => $this->mediaLibraryPathService->moveTargets($folders),
            'page'          => $page,
            'limit'         => $limit,
        ];
    }

    /**
     * @param array{page?:int,limit?:int,keyword?:string,folder?:string,upload_date?:string} $params
     * @return array{total:int,list:list<array>,folders:list<array{id:string,name:string,count:int}>,move_targets?:list<array{id:string,name:string}>}
     */
    public function listSoftware(array $params): array
    {
        $root = $this->mediaLibraryPathService->uploadRoot();
        if (!is_dir($root)) {
            return ['total' => 0, 'list' => [], 'folders' => [['id' => '', 'name' => '全部附件', 'count' => 0]]];
        }

        $allowed = $this->mediaLibraryPathService->allowedAttachmentPickerExtensions();
        $keyword = strtolower(trim((string) ($params['keyword'] ?? '')));
        $folder  = trim(str_replace('\\', '/', (string) ($params['folder'] ?? '')), '/');
        if (str_contains($folder, '..')) {
            $folder = '';
        }

        $all = [];
        $this->mediaLibraryPathService->scanDir($root, $root, $allowed, $all);

        $folderCounts = ['' => 0];
        foreach ($all as $item) {
            $folderCounts[''] = ($folderCounts[''] ?? 0) + 1;
            $top = $item['folder_top'];
            if ($top !== '') {
                $folderCounts[$top] = ($folderCounts[$top] ?? 0) + 1;
            }
        }

        if ($folder !== '') {
            $all = array_values(array_filter($all, static fn ($i) => $i['folder_top'] === $folder));
        }

        if ($keyword !== '') {
            $all = array_values(array_filter($all, static fn ($i) => str_contains(strtolower($i['name']), $keyword)));
        }

        $uploadDate = trim((string) ($params['upload_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $uploadDate) === 1) {
            $start = strtotime($uploadDate . ' 00:00:00');
            $end   = strtotime($uploadDate . ' 23:59:59');
            if ($start !== false && $end !== false) {
                $all = array_values(array_filter(
                    $all,
                    static fn ($i) => ($i['mtime'] ?? 0) >= $start && ($i['mtime'] ?? 0) <= $end
                ));
            }
        }

        usort($all, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        $page  = max(1, (int) ($params['page'] ?? 1));
        $limit = max(1, min(60, (int) ($params['limit'] ?? 20)));
        $total = count($all);
        $list  = array_slice($all, ($page - 1) * $limit, $limit);

        $folders = [['id' => '', 'name' => '全部附件', 'count' => $folderCounts[''] ?? 0]];
        unset($folderCounts['']);
        ksort($folderCounts);
        foreach ($folderCounts as $id => $count) {
            $id = (string) $id;
            $folders[] = [
                'id'    => $id,
                'name'  => $this->mediaLibraryPathService->folderLabel($id),
                'count' => $count,
            ];
        }

        return [
            'total'         => $total,
            'list'          => $list,
            'folders'       => $folders,
            'move_targets'  => $this->mediaLibraryPathService->moveTargets($folders),
            'page'          => $page,
            'limit'         => $limit,
        ];
    }

    /**
     * @param string $pathOrUrl 相对 path 或 URL
     * @return ServiceResult
     */
    public function deleteImage(string $pathOrUrl, bool $force = false): ServiceResult
    {
        return $this->deleteFile($pathOrUrl, 'image', $force);
    }

    /**
     * @return ServiceResult
     */
    public function deleteFile(string $pathOrUrl, string $kind = 'image', bool $force = false): ServiceResult
    {
        $allowed = $kind === 'software'
            ? $this->mediaLibraryPathService->allowedAttachmentPickerExtensions()
            : $this->mediaLibraryPathService->allowedExtensions();
        $real = $this->mediaLibraryPathService->resolveFileRealPath($pathOrUrl, $allowed);
        if ($real === null) {
            return ServiceResult::fail('文件不存在或路径无效');
        }

        if ($this->mediaAssetRefService->isEnabled() && !$force) {
            $usage = $this->mediaAssetRefService->describePathUsage($pathOrUrl);
            if (!$usage['ok']) {
                return ServiceResult::fail($this->formatBlockedDeleteMessage($usage), ApiErrorCode::VALIDATION, [
                        'ref_count'      => (int) ($usage['count'] ?? 0),
                        'media_asset_id' => (int) ($usage['media_asset_id'] ?? 0),
                        'usages'         => $usage['usages'] ?? [],
                    ]);
            }
        }

        if (!is_file($real) || !unlink($real)) {
            return ServiceResult::fail('删除失败，请检查文件权限');
        }

        $this->mediaAssetRefService->deletePhysicalIfUnreferenced($pathOrUrl);
        $this->mediaOrphanQueueService->remove($pathOrUrl);

        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * 删除前预览：是否可删、资产 id、引用文档列表
     *
     * @return array{
     *   ok:bool,
     *   exists:bool,
     *   path:string,
     *   name:string,
     *   media_asset_id:int,
     *   ref_count:int,
     *   ref_tracking:bool,
     *   usages:list<array<string,mixed>>
     * }
     */
    public function previewDelete(string $pathOrUrl, string $kind = 'image'): array
    {
        $allowed = $kind === 'software'
            ? $this->mediaLibraryPathService->allowedAttachmentPickerExtensions()
            : $this->mediaLibraryPathService->allowedExtensions();
        $real = $this->mediaLibraryPathService->resolveFileRealPath($pathOrUrl, $allowed);
        $path = $this->mediaAssetRefService->normalizePath($pathOrUrl);

        if ($real === null) {
            return [
                'ok'             => false,
                'exists'         => false,
                'path'           => $path,
                'name'           => $path !== '' ? basename($path) : '',
                'media_asset_id' => 0,
                'ref_count'      => 0,
                'ref_tracking'   => $this->mediaAssetRefService->isEnabled(),
                'usages'         => [],
            ];
        }

        $usage = $this->mediaAssetRefService->describePathUsage($pathOrUrl);
        $normalized = $this->mediaAssetRefService->normalizePath($pathOrUrl);

        return [
            'ok'             => (bool) ($usage['ok'] ?? false),
            'exists'         => true,
            'path'           => $normalized !== '' ? $normalized : str_replace('\\', '/', ltrim(str_replace($this->mediaLibraryPathService->uploadRoot(), '', $real), '/\\')),
            'name'           => basename($real),
            'media_asset_id' => (int) ($usage['media_asset_id'] ?? 0),
            'ref_count'      => (int) ($usage['count'] ?? 0),
            'ref_tracking'   => (bool) ($usage['ref_tracking'] ?? false),
            'usages'         => $usage['usages'] ?? [],
        ];
    }

    /**
     * @param list<string> $paths
     * @return array{
     *   deletable:list<array<string,mixed>>,
     *   blocked:list<array<string,mixed>>,
     *   missing:list<string>
     * }
     */
    public function previewDeletes(array $paths): array
    {
        $paths = array_values(array_unique(array_filter(array_map('strval', $paths))));
        $deletable = [];
        $blocked   = [];
        $missing   = [];

        foreach ($paths as $path) {
            $preview = $this->previewDelete($path, $this->mediaLibraryPathService->kindFromPath($path));
            if (!$preview['exists']) {
                $missing[] = $path;
                continue;
            }
            if ($preview['ok']) {
                $deletable[] = $preview;
            } else {
                $blocked[] = $preview;
            }
        }

        return [
            'deletable' => $deletable,
            'blocked'   => $blocked,
            'missing'   => $missing,
        ];
    }

    /**
     * @param array<string,mixed> $usage
     */
    private function formatBlockedDeleteMessage(array $usage): string
    {
        $count = (int) ($usage['count'] ?? 0);
        $lines = ['仍有 ' . $count . ' 处引用，无法删除该文件：'];
        foreach (($usage['usages'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = (string) ($row['title'] ?? '未知');
            if (!empty($row['in_recycle'])) {
                $title .= '（回收站）';
            }
            $slots = $row['slots'] ?? [];
            $slotText = is_array($slots) && $slots !== []
                ? implode('、', array_map('strval', $slots))
                : '引用';
            $docId = (int) ($row['document_id'] ?? 0);
            $lines[] = $docId > 0
                ? ('· 《' . $title . '》— ' . $slotText)
                : ('· ' . $title . ' — ' . $slotText);
        }

        return implode("\n", $lines);
    }

    /**
     * 扫描未被业务引用的 uploads 文件（可安全清理）
     *
     * @param array{kind?:string,folder?:string} $params
     * @return array{
     *   count:int,
     *   total_size:int,
     *   ref_tracking:bool,
     *   blocked:bool,
     *   block_reason?:string,
     *   async_recommended:bool,
     *   sample:list<array{name:string,path:string,url:string,size:int}>
     * }
     */
    public function defaultPurgeScanMode(): string
    {
        $mode = strtolower(trim((string) Config::get('upload.purge.scan_mode', 'disk')));

        return in_array($mode, ['disk', 'db', 'queue'], true) ? $mode : 'disk';
    }

    public function resolvePurgeScanMode(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, ['disk', 'db', 'queue'], true) ? $mode : $this->defaultPurgeScanMode();
    }

    public function scanInvalidResources(array $params): array
    {
        $params['mode'] = $this->resolvePurgeScanMode($params['mode'] ?? null);
        $scan           = $this->scanInvalidResourcesInternal($params);
        unset($scan['paths']);

        return $scan;
    }

    /**
     * 内部扫描：含完整 paths 列表（仅供分批任务使用，勿直接返回给 HTTP）
     *
     * @param array{kind?:string,folder?:string} $params
     * @return array{
     *   count:int,
     *   total_size:int,
     *   ref_tracking:bool,
     *   blocked:bool,
     *   block_reason?:string,
     *   async_recommended:bool,
     *   sample:list<array{name:string,path:string,url:string,size:int}>,
     *   paths:list<string>
     * }
     */
    public function scanInvalidResourcesInternal(array $params): array
    {
        $kind   = ($params['kind'] ?? 'image') === 'software' ? 'software' : 'image';
        $folder = trim(str_replace('\\', '/', (string) ($params['folder'] ?? '')), '/');
        $mode   = $this->resolvePurgeScanMode($params['mode'] ?? null);

        if ($mode === 'db') {
            return $this->scanInvalidFromDb($kind, $folder);
        }
        if ($mode === 'queue') {
            return $this->scanInvalidFromQueue($kind, $folder);
        }

        return $this->scanInvalidFromDisk($kind, $folder);
    }

    /**
     * 纯 DB：ref_count=0 且磁盘存在（漏掉从未入库文件，极快）
     *
     * @return array<string, mixed>
     */
    private function scanInvalidFromDb(string $kind, string $folder): array
    {
        if (!$this->mediaAssetService->tableExists()) {
            return $this->blockedScanResult('未创建 media_assets 表，无法使用 db 模式。请先执行 migrate_media_assets.php。');
        }

        $query = MediaAsset::where('ref_count', 0);
        if ($folder !== '') {
            $query->whereLike('path', 'uploads/' . $folder . '%');
        }

        $invalid = [];
        foreach ($query->field('path,file_size')->select()->toArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = (string) ($row['path'] ?? '');
            if ($path === '' || $this->mediaLibraryPathService->kindFromPath($path) !== $kind) {
                continue;
            }
            $real = $this->mediaLibraryPathService->resolveFileRealPathPublic($path);
            if ($real === null) {
                continue;
            }
            $invalid[] = [
                'path' => $path,
                'url'  => '/' . ltrim($path, '/'),
                'name' => basename($real),
                'size' => (int) (@filesize($real) ?: (int) ($row['file_size'] ?? 0)),
            ];
        }

        return $this->formatScanResult($invalid, 'db', true);
    }

    /**
     * 增量队列：仅消费 media_orphan_queue（上传/删文档时维护）
     *
     * @return array<string, mixed>
     */
    private function scanInvalidFromQueue(string $kind, string $folder): array
    {
        if (!$this->mediaOrphanQueueService->isEnabled()) {
            return $this->blockedScanResult('未创建 media_orphan_queue 表。请先执行 migrate_media_orphan_queue.php。');
        }
        if (!$this->mediaAssetRefService->isEnabled()) {
            return $this->blockedScanResult('未启用影子引用表，队列模式无法校验引用。请先执行 migrate_media_asset_refs.php。');
        }

        self::$referencedPathCache = $this->mediaAssetRefService->referencedPathLookup();
        $invalid                   = [];
        try {
            foreach ($this->mediaOrphanQueueService->pathsForPurge($kind, $folder) as $path) {
                if (!$this->isUnusedUploadPathFast($path)) {
                    $this->mediaOrphanQueueService->remove($path);
                    continue;
                }
                $real = $this->mediaLibraryPathService->resolveFileRealPathPublic($path);
                if ($real === null) {
                    $this->mediaOrphanQueueService->remove($path);
                    continue;
                }
                $invalid[] = [
                    'path' => $path,
                    'url'  => '/' . ltrim($path, '/'),
                    'name' => basename($real),
                    'size' => (int) (@filesize($real) ?: 0),
                ];
            }
        } finally {
            self::$referencedPathCache = null;
        }

        return $this->formatScanResult($invalid, 'queue', true);
    }

    /**
     * 全盘扫描 uploads（默认，最完整但慢）
     *
     * @return array<string, mixed>
     */
    private function scanInvalidFromDisk(string $kind, string $folder): array
    {
        $refTracking = $this->mediaAssetRefService->isEnabled();
        if (!$refTracking) {
            return $this->blockedScanResult(
                '未启用影子引用表。海量文档下逐文件 LIKE 扫描会超时，请先执行 migrate_media_asset_refs.php 与 migrate_media_refs_backfill.php 回填引用后再清理。',
            );
        }

        self::$referencedPathCache = $this->mediaAssetRefService->referencedPathLookup();
        try {
            $all     = $this->mediaLibraryPathService->scanUploadFilesOnce($kind, $folder);
            $invalid = [];
            foreach ($all as $item) {
                $path = (string) ($item['path'] ?? '');
                if ($path !== '' && $this->isUnusedUploadPathFast($path)) {
                    $invalid[] = $item;
                }
            }
        } finally {
            self::$referencedPathCache = null;
        }

        return $this->formatScanResult($invalid, 'disk', true);
    }

    /**
     * 列出可分片并行扫描的子目录（相对 uploads/），如 2024/01、article
     *
     * @return list<string>
     */
    public function listUploadScanShards(string $folder = ''): array
    {
        $root = $this->mediaLibraryPathService->uploadRoot();
        if (!is_dir($root)) {
            return [];
        }

        $folder = trim(str_replace('\\', '/', $folder), '/');
        if ($folder !== '' && str_contains($folder, '..')) {
            return [];
        }

        $base = $folder === ''
            ? $root
            : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);

        $baseReal = realpath($base);
        $rootReal = realpath($root);
        if ($baseReal === false || $rootReal === false || !is_dir($baseReal) || !str_starts_with($baseReal, $rootReal)) {
            return $folder === '' ? [] : [$folder];
        }

        $shards = [];
        $items  = scandir($baseReal);
        if ($items === false) {
            return $folder === '' ? [] : [$folder];
        }

        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $baseReal . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($full)) {
                continue;
            }
            $shards[] = $folder === '' ? $name : $folder . '/' . $name;
        }

        if ($shards === [] && $folder !== '') {
            return [$folder];
        }

        sort($shards);

        return $shards;
    }

    /**
     * @param list<array<string, mixed>> $invalid
     * @return array<string, mixed>
     */
    private function formatScanResult(array $invalid, string $mode, bool $refTracking): array
    {
        $paths = array_values(array_map(static fn (array $i): string => (string) $i['path'], $invalid));
        $count = count($invalid);

        return [
            'count'             => $count,
            'total_size'        => array_sum(array_map(static fn (array $i): int => (int) ($i['size'] ?? 0), $invalid)),
            'ref_tracking'      => $refTracking,
            'scan_mode'         => $mode,
            'blocked'           => false,
            'async_recommended' => $count > self::ASYNC_RECOMMEND_THRESHOLD,
            'sample'            => array_map(
                static fn (array $i): array => [
                    'name' => (string) ($i['name'] ?? ''),
                    'path' => (string) ($i['path'] ?? ''),
                    'url'  => (string) ($i['url'] ?? ''),
                    'size' => (int) ($i['size'] ?? 0),
                ],
                array_slice($invalid, 0, 8),
            ),
            'paths' => $paths,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blockedScanResult(string $reason): array
    {
        return [
            'count'             => 0,
            'total_size'        => 0,
            'ref_tracking'      => false,
            'scan_mode'         => '',
            'blocked'           => true,
            'block_reason'      => $reason,
            'async_recommended' => false,
            'sample'            => [],
            'paths'             => [],
        ];
    }

    /**
     * 批量删除无效资源（少量同步；大量请走 MediaPurgeBatchService）
     *
     * @param array{kind?:string,folder?:string,force_async?:bool} $params
     * @return ServiceResult}
     */
    public function purgeInvalidResources(array $params): ServiceResult
    {
        if (!$this->mediaAssetRefService->isEnabled()) {
            return ServiceResult::fail('未启用影子引用表，无法安全批量清理。请先执行引用表迁移与回填脚本。');
        }

        $kind   = ($params['kind'] ?? 'image') === 'software' ? 'software' : 'image';
        $scan   = $this->scanInvalidResourcesInternal(array_merge($params, ['kind' => $kind]));
        $paths  = $scan['paths'] ?? [];
        $count  = count($paths);
        if ($count < 1) {
            return ServiceResult::ok(['deleted' => 0, 'failed' => [], 'skipped' => 0], '未发现可清理的无效资源');
        }

        $forceAsync = !empty($params['force_async']) || $count > self::SYNC_PURGE_MAX;
        if ($forceAsync) {
            $start = $this->mediaPurgeBatchService->start([
                'kind'   => $kind,
                'folder' => $params['folder'] ?? '',
                'paths'  => $paths,
            ]);
            if (!$start->isOk()) {
                return ServiceResult::fail((string) ($start->message() ?? '无法创建清理任务'));
            }

            return ServiceResult::ok([
                    'deleted' => 0,
                    'failed'  => [],
                    'skipped' => 0,
                    'async'   => true,
                    'job_id'  => (string) ($start->dataArray()['job_id'] ?? ''),
                    'total'   => (int) ($start->dataArray()['total'] ?? $count),
                ], '无效资源较多，已创建后台分批清理任务');
        }

        self::$referencedPathCache = $this->mediaAssetRefService->referencedPathLookup();
        $deleted = 0;
        $failed  = [];
        try {
            foreach ($paths as $path) {
                if (!$this->isUnusedUploadPathFast($path)) {
                    continue;
                }
                $res = $this->deleteFile($path, $kind);
                if ($res->isOk()) {
                    $deleted++;
                } else {
                    $failed[] = $path;
                }
            }
        } finally {
            self::$referencedPathCache = null;
        }

        if ($deleted === 0) {
            return ServiceResult::fail('清理失败', ApiErrorCode::VALIDATION, ['deleted' => 0, 'failed' => $failed, 'skipped' => 0]);
        }

        $msg = $failed === []
            ? '已清理 ' . $deleted . ' 个无效资源'
            : '已清理 ' . $deleted . ' 个，' . count($failed) . ' 个失败';

        return ServiceResult::ok(['deleted' => $deleted, 'failed' => $failed, 'skipped' => 0], $msg);
    }

    /**
     * @param list<string> $paths
     * @return ServiceResult}
     */
    public function batchDeleteImages(array $paths): ServiceResult
    {
        $paths = array_values(array_unique(array_filter(array_map('strval', $paths))));
        if ($paths === []) {
            return ServiceResult::fail('请选择要删除的图片');
        }

        $success = 0;
        $failed  = [];
        foreach ($paths as $path) {
            $res = $this->deleteFile($path, $this->mediaLibraryPathService->kindFromPath($path));
            if ($res->isOk()) {
                $success++;
            } else {
                $failed[] = $path;
            }
        }

        if ($success === 0) {
            return ServiceResult::fail('删除失败', ApiErrorCode::VALIDATION, ['success' => 0, 'failed' => $failed]);
        }

        $msg = $failed === []
            ? '已删除 ' . $success . ' 张图片'
            : '已删除 ' . $success . ' 张，' . count($failed) . ' 张失败';

        return ServiceResult::ok(['success' => $success, 'failed' => $failed], $msg);
    }

    /**
     * 移动图片到 uploads 下指定子目录（如 article、article/20260523）
     *
     * @param list<string> $paths
     * @return ServiceResult}
     */
    public function moveImages(array $paths, string $targetDir): ServiceResult
    {
        $paths = array_values(array_unique(array_filter(array_map('strval', $paths))));
        if ($paths === []) {
            return ServiceResult::fail('请选择要移动的图片');
        }

        $targetDir = $this->mediaLibraryPathService->normalizeTargetDir($targetDir);
        if ($targetDir === null) {
            return ServiceResult::fail('无效的目标目录');
        }

        $root     = $this->mediaLibraryPathService->uploadRoot();
        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            return ServiceResult::fail('上传目录不存在');
        }

        $destDir = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetDir);
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return ServiceResult::fail('无法创建目标目录');
        }

        $success = 0;
        $failed  = [];
        foreach ($paths as $path) {
            $real = $this->mediaLibraryPathService->resolveFileRealPath($path);
            if ($real === null) {
                $failed[] = $path;
                continue;
            }

            $filename = basename($real);
            $dest     = $destDir . DIRECTORY_SEPARATOR . $filename;
            if (is_file($dest)) {
                $dest = $destDir . DIRECTORY_SEPARATOR . pathinfo($filename, PATHINFO_FILENAME)
                    . '_' . substr(md5(uniqid('', true)), 0, 6)
                    . '.' . pathinfo($filename, PATHINFO_EXTENSION);
            }

            if (!rename($real, $dest)) {
                $failed[] = $path;
                continue;
            }
            $success++;
        }

        if ($success === 0) {
            return ServiceResult::fail('移动失败', ApiErrorCode::VALIDATION, ['success' => 0, 'failed' => $failed]);
        }

        $msg = $failed === []
            ? '已移动 ' . $success . ' 张图片'
            : '已移动 ' . $success . ' 张，' . count($failed) . ' 张失败';

        return ServiceResult::ok(['success' => $success, 'failed' => $failed], $msg);
    }

    public function isUnusedUploadPathFast(string $pathOrUrl): bool
    {
        if ($this->mediaAssetRefService->isEnabled()) {
            $path = $this->mediaAssetRefService->normalizePath($pathOrUrl);
            if ($path === '') {
                return false;
            }
            $lookup = self::$referencedPathCache ?? $this->mediaAssetRefService->referencedPathLookup();

            return !isset($lookup[$path]);
        }

        return $this->isUnusedUploadPath($pathOrUrl);
    }

    private function isUnusedUploadPath(string $pathOrUrl): bool
    {
        if ($this->mediaAssetRefService->isEnabled()) {
            return $this->mediaAssetRefService->canDeletePath($pathOrUrl)['ok'];
        }

        $row = $this->mediaAssetService->findByPath($pathOrUrl);
        if ($row !== null && (int) ($row['ref_count'] ?? 0) > 0) {
            return false;
        }

        return !$this->pathAppearsInLegacyContent($pathOrUrl);
    }

    private function pathAppearsInLegacyContent(string $pathOrUrl): bool
    {
        $norm = $this->mediaAssetRefService->normalizePath($pathOrUrl);
        if ($norm === '') {
            return true;
        }

        $needle = str_replace(['%', '_'], ['\\%', '\\_'], $norm);

        try {
            $doc = Document::whereNull('deleted_at')
                ->where(function ($query) use ($needle) {
                    $query->whereLike('litpic', '%' . $needle . '%')
                        ->whereOr('content', 'like', '%' . $needle . '%')
                        ->whereOr('content_mobile', 'like', '%' . $needle . '%');
                })
                ->limit(1)
                ->find();
            if ($doc) {
                return true;
            }
        } catch (\Throwable $e) {
            OpsLog::businessWarning('media_legacy_content_probe_failed', [
                'path' => $pathOrUrl,
                'msg'  => $e->getMessage(),
            ]);

            return true;
        }

        return false;
    }
}
