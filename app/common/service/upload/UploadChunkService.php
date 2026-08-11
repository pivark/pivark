<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 分片上传（断点续传）
     * @param mixed $scene
     * @param mixed $fileName
     * @param mixed $fileSize
     * @param mixed $fileHash
     * @param mixed $partSize
 */
declare(strict_types=1);

namespace app\common\service\upload;

use app\common\support\ServiceResult;
use app\common\support\RuntimeJsonFile;

use app\common\service\media\MediaAssetService;
use app\common\exception\UploadException;
use app\common\support\LocalFile;
use think\facade\Config;

class UploadChunkService
{

    /** meta.json 最大体积，防止异常文件读入内存 */
    private const MAX_META_BYTES = 65536;

    public function __construct(
        private readonly MediaAssetService $mediaAssetService,
    ) {
    }

    /**
     * @return ServiceResult
     */
    public function init(string $scene, string $fileName, int $fileSize, string $fileHash, int $partSize): ServiceResult
    {
        if (!$this->isEnabled()) {
            return ServiceResult::fail('分片上传未启用');
        }

        try {
            $scene = UploadService::normalizeScene($scene);
        } catch (\InvalidArgumentException $e) {
            return ServiceResult::fail($this->clientErrorMessage($e, '无效的上传参数'));
        }

        if ($fileSize < 1) {
            return ServiceResult::fail('无效的文件大小');
        }

        $maxBytes = $this->maxFileBytes();
        if ($fileSize > $maxBytes) {
            return ServiceResult::fail('文件超过允许的最大体积');
        }

        $partSize = $this->normalizePartSize($partSize);
        $total    = (int) ceil($fileSize / $partSize);
        $maxParts = (int) Config::get('upload.chunk.max_parts', 10000);
        if ($total > $maxParts) {
            return ServiceResult::fail('分片数量过多，请增大分片大小');
        }

        $hash = $this->mediaAssetService->normalizeHash($fileHash);
        if ($hash === '') {
            return ServiceResult::fail('无效的文件哈希');
        }

        $this->gcExpired();

        $uploadId = bin2hex(random_bytes(16));
        $dir      = $this->sessionDir($uploadId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ServiceResult::fail('无法创建临时目录');
        }

        $meta = [
            'upload_id'   => $uploadId,
            'scene'       => $scene,
            'file_name'   => $this->safeBaseName($fileName),
            'file_size'   => $fileSize,
            'file_hash'   => $hash,
            'part_size'   => $partSize,
            'total_parts' => $total,
            'uploaded'    => [],
            'created_at'  => time(),
        ];
        $this->writeMeta($uploadId, $meta);

        return ServiceResult::ok([
                'upload_id'   => $uploadId,
                'part_size'   => $partSize,
                'total_parts' => $total,
                'uploaded'    => [],
            ], 'ok');
    }

    /**
     * @return ServiceResult
     * @param mixed $uploadId
     */
    public function resume(string $uploadId): ServiceResult
    {
        $meta = $this->readMeta($uploadId);
        if ($meta === null) {
            return ServiceResult::fail('上传会话不存在或已过期');
        }

        return ServiceResult::ok([
                'upload_id'   => $uploadId,
                'part_size'   => (int) ($meta['part_size'] ?? 0),
                'total_parts' => (int) ($meta['total_parts'] ?? 0),
                'uploaded'    => array_values(array_map('intval', (array) ($meta['uploaded'] ?? []))),
                'file_name'   => (string) ($meta['file_name'] ?? ''),
                'file_size'   => (int) ($meta['file_size'] ?? 0),
                'file_hash'   => (string) ($meta['file_hash'] ?? ''),
                'scene'       => (string) ($meta['scene'] ?? 'general'),
            ], 'ok');
    }

    /**
     * @return ServiceResult}
     * @param mixed $uploadId
     * @param mixed $index
     * @param mixed $tmpPath
     */
    public function savePart(string $uploadId, int $index, string $tmpPath): ServiceResult
    {
        $meta = $this->readMeta($uploadId);
        if ($meta === null) {
            return ServiceResult::fail('上传会话不存在或已过期');
        }

        $total = (int) ($meta['total_parts'] ?? 0);
        if ($index < 0 || $index >= $total) {
            return ServiceResult::fail('分片序号无效');
        }

        if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
            return ServiceResult::fail('分片数据无效');
        }

        $partPath = $this->partPath($uploadId, $index);
        if (!rename($tmpPath, $partPath)) {
            if (!copy($tmpPath, $partPath)) {
                return ServiceResult::fail('分片保存失败');
            }
            LocalFile::unlinkIfExists($tmpPath);
        }

        $failMsg  = null;
        $uploaded = [];
        RuntimeJsonFile::update($this->metaPath($uploadId), static function (array $data) use ($index, &$failMsg, &$uploaded): array {
            if ($data === [] || !isset($data['total_parts'])) {
                $failMsg = '上传会话不存在或已过期';

                return $data;
            }
            $list = array_map('intval', (array) ($data['uploaded'] ?? []));
            $list[] = $index;
            $list = array_values(array_unique($list));
            sort($list);
            $data['uploaded'] = $list;
            $uploaded         = $list;

            return $data;
        });
        if ($failMsg !== null) {
            return ServiceResult::fail($failMsg);
        }

        return ServiceResult::ok(['index' => $index, 'uploaded' => $uploaded], 'ok');
    }

    /**
     * @return ServiceResult}
     * @param mixed $uploadId
     * @param mixed $forceUpload
     */
    public function complete(string $uploadId, bool $forceUpload = false): ServiceResult
    {
        $meta = $this->readMeta($uploadId);
        if ($meta === null) {
            return ServiceResult::fail('上传会话不存在或已过期');
        }

        $total    = (int) ($meta['total_parts'] ?? 0);
        $uploaded = array_map('intval', (array) ($meta['uploaded'] ?? []));
        if (count($uploaded) !== $total) {
            return ServiceResult::fail('分片未传齐，请继续上传');
        }
        for ($i = 0; $i < $total; $i++) {
            if (!in_array($i, $uploaded, true) || !is_file($this->partPath($uploadId, $i))) {
                return ServiceResult::fail('缺少分片 ' . $i);
            }
        }

        $merged = $this->mergeParts($uploadId, $meta);
        if ($merged === null) {
            return ServiceResult::fail('合并分片失败');
        }

        $expectedSize = (int) ($meta['file_size'] ?? 0);
        $actualSize   = (int) filesize($merged);
        if ($actualSize !== $expectedSize) {
            LocalFile::unlinkIfExists($merged);
            $this->removeSession($uploadId);
            return ServiceResult::fail('合并后文件大小不一致');
        }

        $hash = (string) ($meta['file_hash'] ?? '');
        if ($hash !== '' && $this->mediaAssetService->normalizeHash($hash) !== '') {
            $computed = $this->mediaAssetService->computeSha256($merged);
            if ($computed !== '' && !hash_equals($hash, $computed)) {
                LocalFile::unlinkIfExists($merged);
                $this->removeSession($uploadId);
                return ServiceResult::fail('文件校验失败，请重新上传');
            }
        }

        if (!$forceUpload && $this->mediaAssetService->isEnabled()) {
            $dup = $this->mediaAssetService->findByHash($hash, $expectedSize);
            if ($dup !== null) {
                LocalFile::unlinkIfExists($merged);
                $this->removeSession($uploadId);
                $this->mediaAssetService->touchReuse((int) $dup['id']);

                return ServiceResult::duplicate(array_merge(
                        $this->mediaAssetService->formatForClient($dup),
                        ['duplicate' => true, 'reused' => true]
                    ), '检测到相同文件已存在');
            }
        }

        try {
            $scene    = (string) ($meta['scene'] ?? 'general');
            $fileName = (string) ($meta['file_name'] ?? 'file.bin');
            $stored   = UploadService::scene($scene)->storeFromLocalFile(
                $merged,
                $fileName,
                $hash
            );
        } catch (UploadException $e) {
            LocalFile::unlinkIfExists($merged);
            $this->removeSession($uploadId);
            return $e->toJson();
        } catch (\Throwable $e) {
            LocalFile::unlinkIfExists($merged);
            $this->removeSession($uploadId);
            return ServiceResult::fail($this->clientErrorMessage($e, '上传失败，请稍后重试或联系管理员'));
        }

        LocalFile::unlinkIfExists($merged);
        $this->removeSession($uploadId);

        return ServiceResult::ok($stored, '上传成功');
    }

    /**
     * @return mixed
     */
    public function isEnabled(): bool
    {
        return (bool) Config::get('upload.chunk.enabled', true);
    }

    /**
     * @return mixed
     */
    public function thresholdBytes(): int
    {
        $mb = (float) Config::get('upload.chunk.threshold_mb', 10);

        return max(1, (int) ($mb * 1024 * 1024));
    }

    /**
     * @return mixed
     */
    public function defaultPartSizeBytes(): int
    {
        $mb = (float) Config::get('upload.chunk.part_size_mb', 2);

        return $this->normalizePartSize((int) ($mb * 1024 * 1024));
    }

    private function normalizePartSize(int $partSize): int
    {
        $min = 256 * 1024;
        $max = 10 * 1024 * 1024;
        if ($partSize < $min) {
            return $min;
        }
        if ($partSize > $max) {
            return $max;
        }

        return $partSize;
    }

    private function maxFileBytes(): int
    {
        $gb = (float) Config::get('upload.chunk.max_file_gb', 2);

        return (int) max(1, $gb * 1024 * 1024 * 1024);
    }

    private function baseDir(): string
    {
        return ROOT_PATH . 'data/runtime' . DIRECTORY_SEPARATOR . 'upload_chunks';
    }

    private function sessionDir(string $uploadId): string
    {
        return $this->baseDir() . DIRECTORY_SEPARATOR . preg_replace('/[^a-f0-9]/', '', $uploadId);
    }

    private function metaPath(string $uploadId): string
    {
        return $this->sessionDir($uploadId) . DIRECTORY_SEPARATOR . 'meta.json';
    }

    private function partPath(string $uploadId, int $index): string
    {
        return $this->sessionDir($uploadId) . DIRECTORY_SEPARATOR . $index . '.part';
    }

    /** @return array<string, mixed>|null */
    private function readMetaJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_META_BYTES) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }
        $meta = json_decode($json, true);

        return is_array($meta) ? $meta : null;
    }

    /** @return array<string, mixed>|null */
    private function readMeta(string $uploadId): ?array
    {
        $uploadId = preg_replace('/[^a-f0-9]/', '', $uploadId) ?? '';
        if ($uploadId === '') {
            return null;
        }
        $path = $this->metaPath($uploadId);
        $meta = $this->readMetaJson($path);
        if ($meta === null) {
            return null;
        }
        $ttl = (int) Config::get('upload.chunk.ttl_seconds', 86400);
        $created = (int) ($meta['created_at'] ?? 0);
        if ($created > 0 && time() - $created > $ttl) {
            $this->removeSession($uploadId);
            return null;
        }

        return $meta;
    }

    /** @param array<string, mixed> $meta */
    private function writeMeta(string $uploadId, array $meta): void
    {
        file_put_contents(
            $this->metaPath($uploadId),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /** @param array<string, mixed> $meta */
    private function mergeParts(string $uploadId, array $meta): ?string
    {
        $total  = (int) ($meta['total_parts'] ?? 0);
        $merged = $this->sessionDir($uploadId) . DIRECTORY_SEPARATOR . 'merged.bin';
        $out    = fopen($merged, 'wb');
        if ($out === false) {
            return null;
        }
        for ($i = 0; $i < $total; $i++) {
            $part = $this->partPath($uploadId, $i);
            $in   = fopen($part, 'rb');
            if ($in === false) {
                fclose($out);
                LocalFile::unlinkIfExists($merged);
                return null;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        return is_file($merged) ? $merged : null;
    }

    private function removeSession(string $uploadId): void
    {
        $dir = $this->sessionDir($uploadId);
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                LocalFile::unlinkIfExists($file);
            }
        }
        LocalFile::rmdirIfExists($dir);
    }

    /**
     * @return mixed
     */
    public function gcExpired(): void
    {
        $base = $this->baseDir();
        if (!is_dir($base)) {
            return;
        }
        $ttl = (int) Config::get('upload.chunk.ttl_seconds', 86400);
        foreach (glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $metaFile = $dir . DIRECTORY_SEPARATOR . 'meta.json';
            if (!is_file($metaFile)) {
                $this->removeDir($dir);
                continue;
            }
            $meta = $this->readMetaJson($metaFile);
            if ($meta === null) {
                $this->removeDir($dir);
                continue;
            }
            $created = (int) ($meta['created_at'] ?? 0);
            if ($created > 0 && time() - $created > $ttl) {
                $this->removeDir($dir);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                LocalFile::unlinkIfExists($file);
            }
        }
        LocalFile::rmdirIfExists($dir);
    }

    private function safeBaseName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w.\-\x{4e00}-\x{9fff}]/u', '_', $name) ?? 'file.bin';
        if ($name === '' || $name === '.' || $name === '..') {
            return 'file.bin';
        }

        return mb_substr($name, 0, 200);
    }

    private function clientErrorMessage(\Throwable $e, string $fallback): string
    {
        if ((bool) env('APP_DEBUG', false)) {
            return ($fallback !== '' ? rtrim($fallback, '。') . '：' : '') . $e->getMessage();
        }

        return $fallback;
    }
}
