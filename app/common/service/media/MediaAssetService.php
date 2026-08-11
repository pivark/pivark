<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 媒体资产索引（内容哈希去重）
     * @param mixed $hash
     * @param mixed $fileSize
 */
declare(strict_types=1);

namespace app\common\service\media;

use app\common\support\AppTime;
use app\common\service\media\MediaAssetRefService;
use app\common\service\media\MediaLibraryPathService;

use app\common\support\DbTable;
use app\common\service\upload\UploadService;
use app\common\model\MediaAsset;
use think\facade\Config;

class MediaAssetService
{

    public function __construct(
        private readonly MediaLibraryPathService $mediaLibraryPathService,
    ) {
    }

    private static ?bool $tableReady = null;

    /**
     * @return mixed
     */
    public function isEnabled(): bool
    {
        return (bool) Config::get('upload.dedup.enabled', true) && $this->tableExists();
    }

    /**
     * @return mixed
     */
    public function tableExists(): bool
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }
        self::$tableReady = DbTable::modelExists(MediaAsset::class);

        return self::$tableReady;
    }

    /**
     * @return array<string, mixed>|null
     * @param mixed $hash
     * @param mixed $fileSize
     */
    public function findByHash(string $hash, ?int $fileSize = null): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $hash = $this->normalizeHash($hash);
        if ($hash === '') {
            return null;
        }

        $query = MediaAsset::where('content_hash', $hash);
        if ($fileSize !== null && $fileSize > 0) {
            $query->where('file_size', $fileSize);
        }
        $model = $query->find();
        if (!$model) {
            return null;
        }

        $row = $model->toArray();
        if (!$this->fileExistsOnDisk((string) ($row['path'] ?? ''))) {
            $this->deleteById((int) ($row['id'] ?? 0));
            return null;
        }

        return $row;
    }

    /**
     * @param array{content_hash:string,file_size:int,mime?:string,ext?:string,scene:string,path:string,url:string,original_name?:string} $data
     * @return mixed
     */
    public function register(array $data): int
    {
        if (!$this->tableExists()) {
            return 0;
        }
        $hash = $this->normalizeHash((string) ($data['content_hash'] ?? ''));
        if ($hash === '') {
            return 0;
        }

        $existing = MediaAsset::where('content_hash', $hash)->find()?->toArray();
        $now      = AppTime::now();
        $payload  = [
            'file_size'     => max(0, (int) ($data['file_size'] ?? 0)),
            'mime'          => (string) ($data['mime'] ?? ''),
            'ext'           => strtolower((string) ($data['ext'] ?? '')),
            'scene'         => UploadService::normalizeScene((string) ($data['scene'] ?? 'general')),
            'path'          => $this->normalizeStoredPath((string) ($data['path'] ?? '')),
            'url'           => (string) ($data['url'] ?? ''),
            'original_name' => mb_substr((string) ($data['original_name'] ?? ''), 0, 255),
            'updated_at'    => $now,
        ];

        if ($existing) {
            MediaAsset::where('id', (int) $existing['id'])->update($payload);
            if (app(MediaAssetRefService::class)->tableExists()) {
                app(MediaAssetRefService::class)->recalcRefCount((int) $existing['id']);
            } else {
                MediaAsset::where('id', (int) $existing['id'])->inc('ref_count')->update([]);
            }

            return (int) $existing['id'];
        }

        $payload['content_hash'] = $hash;
        $payload['ref_count']    = app(MediaAssetRefService::class)->tableExists() ? 0 : 1;
        $payload['created_at']   = $now;

        return (int) MediaAsset::insertGetId($payload);
    }

    /**
     * @return mixed
     * @param mixed $id
     */
    public function touchReuse(int $id): void
    {
        if (!$this->tableExists() || $id < 1) {
            return;
        }
        if (app(MediaAssetRefService::class)->tableExists()) {
            app(MediaAssetRefService::class)->recalcRefCount($id);
        } else {
            MediaAsset::where('id', $id)->inc('ref_count')->update(['updated_at' => AppTime::now()]);
        }
    }

    /**
     * @param array{content_hash:string,file_size:int,mime?:string,ext?:string,scene:string,path:string,url:string,original_name?:string,admin_id?:int} $data
     */
    public function registerWithAlias(array $data): int
    {
        $id = $this->register($data);
        $name = trim((string) ($data['original_name'] ?? ''));
        if ($id > 0 && $name !== '' && app(MediaAssetRefService::class)->isEnabled()) {
            app(MediaAssetRefService::class)->addAlias($id, $name, (int) ($data['admin_id'] ?? 0));
        }

        return $id;
    }

    /**
     * @return mixed
     * @param mixed $pathOrUrl
     */
    public function deleteByPath(string $pathOrUrl): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $path = $this->normalizeStoredPath($pathOrUrl);
        if ($path === '') {
            return;
        }
        MediaAsset::where('path', $path)->delete();
    }

    public function findByPath(string $pathOrUrl): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        $path = $this->normalizeStoredPath($pathOrUrl);
        if ($path === '') {
            return null;
        }

        return MediaAsset::where('path', $path)->find()?->toArray();
    }

    /** 旧文件未进索引时，按磁盘补登记并返回 media_assets.id */
    public function ensureRegisteredForPath(string $pathOrUrl): int
    {
        if (!$this->tableExists()) {
            return 0;
        }
        $path = $this->normalizeStoredPath($pathOrUrl);
        if ($path === '') {
            return 0;
        }
        $existing = $this->findByPath($path);
        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $real = $this->mediaLibraryPathService->resolveFileRealPathPublic($path);
        if ($real === null) {
            return 0;
        }
        $hash = $this->computeSha256($real);
        if ($hash === '') {
            return 0;
        }
        $dup = $this->findByHash($hash);
        if ($dup !== null) {
            return (int) ($dup['id'] ?? 0);
        }

        $size = (int) (@filesize($real) ?: 0);
        $ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));

        return $this->register([
            'content_hash'  => $hash,
            'file_size'     => $size,
            'mime'          => '',
            'ext'           => $ext,
            'scene'         => 'general',
            'path'          => $path,
            'url'           => '/' . $path,
            'original_name' => basename($real),
        ]);
    }

    /**
     * @return array<string, mixed>
     * @param mixed $row
     */
    public function formatForClient(array $row): array
    {
        $url = (string) ($row['url'] ?? '');
        if ($url !== '') {
            $url = app(MediaUrlService::class)->formatForStorage($url);
        }
        if ($url !== '' && $url[0] !== '/' && !preg_match('#^https?://#i', $url)) {
            $url = '/' . ltrim($url, '/');
        }

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'url'           => $url,
            'path'          => (string) ($row['path'] ?? ''),
            'original_name' => (string) ($row['original_name'] ?? ''),
            'file_size'     => (int) ($row['file_size'] ?? 0),
            'scene'         => (string) ($row['scene'] ?? ''),
            'content_hash'  => (string) ($row['content_hash'] ?? ''),
            'created_at'    => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @return mixed
     * @param mixed $filePath
     */
    public function computeSha256(string $filePath): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return '';
        }
        $hash = hash_file('sha256', $filePath);

        return is_string($hash) ? $hash : '';
    }

    /**
     * @return mixed
     * @param mixed $hash
     */
    public function normalizeHash(string $hash): string
    {
        $hash = strtolower(trim($hash));
        if ($hash === '' || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return '';
        }

        return $hash;
    }

    private function deleteById(int $id): void
    {
        if ($id < 1) {
            return;
        }
        MediaAsset::where('id', $id)->delete();
    }

    private function fileExistsOnDisk(string $path): bool
    {
        $path = $this->normalizeStoredPath($path);
        if ($path === '') {
            return false;
        }
        $publicDir = trim((string) Config::get('upload.public_dir', 'uploads'), '/');
        if (str_starts_with($path, $publicDir . '/')) {
            $relative = substr($path, strlen($publicDir) + 1);
        } elseif (str_starts_with($path, 'uploads/')) {
            $relative = substr($path, strlen('uploads/'));
        } else {
            $relative = $path;
        }

        $full = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . $publicDir
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        return is_file($full);
    }

    private function normalizeStoredPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        if (str_starts_with($path, '/')) {
            $path = ltrim($path, '/');
        }
        $publicDir = trim((string) Config::get('upload.public_dir', 'uploads'), '/');
        if (!str_starts_with($path, $publicDir . '/') && !str_starts_with($path, 'uploads/')) {
            if ($publicDir === 'uploads') {
                $path = 'uploads/' . ltrim($path, '/');
            } else {
                $path = $publicDir . '/' . ltrim($path, '/');
            }
        }

        return $path;
    }
}
