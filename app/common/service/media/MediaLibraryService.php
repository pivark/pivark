<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * 素材库 Facade — 委托 MediaLibraryOpsService / MediaLibraryPathService
 */
declare(strict_types=1);

namespace app\common\service\media;

use app\common\support\ServiceResult;

class MediaLibraryService
{

    public function __construct(
        private readonly MediaLibraryOpsService $ops,
        private readonly MediaLibraryPathService $paths,
    ) {
    }

    public function listImages(array $params): array {
        return $this->ops->listImages($params);
    }

    public function listImagesForMember(int $memberId, array $params): array {
        return $this->ops->listImagesForMember($memberId, $params);
    }

    public function memberOwnsUploadPath(int $memberId, string $pathOrUrl): bool {
        return $this->ops->memberOwnsUploadPath($memberId, $pathOrUrl);
    }

    public function listSoftware(array $params): array {
        return $this->ops->listSoftware($params);
    }

    public function deleteImage(string $pathOrUrl, bool $force = false): ServiceResult {
        return $this->ops->deleteImage($pathOrUrl, $force);
    }

    public function deleteFile(string $pathOrUrl, string $kind = 'image', bool $force = false): ServiceResult {
        return $this->ops->deleteFile($pathOrUrl, $kind, $force);
    }

    public function previewDelete(string $pathOrUrl, string $kind = 'image'): array {
        return $this->ops->previewDelete($pathOrUrl, $kind);
    }

    public function previewDeletes(array $paths): array {
        return $this->ops->previewDeletes($paths);
    }

    public function defaultPurgeScanMode(): string {
        return $this->ops->defaultPurgeScanMode();
    }

    public function resolvePurgeScanMode(?string $mode): string {
        return $this->ops->resolvePurgeScanMode($mode);
    }

    public function scanInvalidResources(array $params): array {
        return $this->ops->scanInvalidResources($params);
    }

    public function scanInvalidResourcesInternal(array $params): array {
        return $this->ops->scanInvalidResourcesInternal($params);
    }

    public function listUploadScanShards(string $folder = ''): array {
        return $this->ops->listUploadScanShards($folder);
    }

    public function purgeInvalidResources(array $params): ServiceResult {
        return $this->ops->purgeInvalidResources($params);
    }

    public function batchDeleteImages(array $paths): ServiceResult {
        return $this->ops->batchDeleteImages($paths);
    }

    public function moveImages(array $paths, string $targetDir): ServiceResult {
        return $this->ops->moveImages($paths, $targetDir);
    }

    public function uploadRoot(): string {
        return $this->paths->uploadRoot();
    }

    public function resolveFileRealPathPublic(string $pathOrUrl): ?string {
        return $this->paths->resolveFileRealPathPublic($pathOrUrl);
    }

    public function isUnusedUploadPathFast(string $pathOrUrl): bool {
        return $this->ops->isUnusedUploadPathFast($pathOrUrl);
    }

    public function kindFromPathPublic(string $pathOrUrl): string {
        return $this->paths->kindFromPathPublic($pathOrUrl);
    }
}
