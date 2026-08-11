<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * 全端共用上传 Facade — 委托 UploadSceneService / UploadFileService
 */
declare(strict_types=1);

namespace app\common\service\upload;

use app\common\support\ServiceResult;
use think\file\UploadedFile;

class UploadService
{

    private readonly UploadFileService $file;

    public function __construct(
        private readonly UploadSceneService $scenes,
        string $type = 'image',
        string $scene = 'general',
    ) {
        $this->file = new UploadFileService($type, $scene);
    }

    /**
     * @return self
     */
    public static function scene(string $scene): self
    {
        $scene = UploadSceneService::normalizeScene($scene);
        $cfg   = UploadSceneService::sceneConfig($scene);
        if ($cfg === []) {
            throw new \InvalidArgumentException("未登记的上传场景: {$scene}");
        }

        return new self(app(UploadSceneService::class), (string) ($cfg['type'] ?? 'image'), $scene);
    }

    /**
     * @param array<string, mixed> $meta 需含 type
     */
    public function registerScene(string $scene, array $meta): void
    {
        $this->scenes->registerScene($scene, $meta);
    }

    /**
     * @return array<string, mixed>
     */
    public function sceneMeta(string $scene): array
    {
        return $this->scenes->sceneMeta($scene);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allScenes(): array
    {
        return $this->scenes->allScenes();
    }

    public function getScene(): string
    {
        return $this->file->getScene();
    }

    public function uploadRouteForScene(string $scene): string
    {
        return $this->scenes->uploadRouteForScene($scene);
    }

    /**
     * @return self
     */
    public static function type(string $type): self
    {
        return new self(app(UploadSceneService::class), $type, 'general');
    }

    public function handle(?UploadedFile $file, array $meta = []): ServiceResult
    {
        return $this->file->handle($file, $meta);
    }

    public function checkDuplicate(string $hash, int $fileSize = 0): ServiceResult
    {
        return $this->file->checkDuplicate($hash, $fileSize);
    }

    /**
     * @return array{url:string,path:string,filename:string,content_hash?:string}
     */
    public function store(
        UploadedFile $file,
        string $contentHash = '',
        bool $forceSecurity = false,
        string $adminConfirmPassword = '',
    ): array {
        return $this->file->store($file, $contentHash, $forceSecurity, $adminConfirmPassword);
    }

    /**
     * @return array{url:string,path:string,filename:string,content_hash?:string}
     */
    public function storeFromLocalFile(
        string $sourcePath,
        string $originalName,
        string $contentHash = '',
        bool $forceSecurity = false,
        string $adminConfirmPassword = '',
    ): array {
        return $this->file->storeFromLocalFile(
            $sourcePath,
            $originalName,
            $contentHash,
            $forceSecurity,
            $adminConfirmPassword,
        );
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        return $this->file->allowedExtensions();
    }

    /**
     * 直传与本地上传共用扩展名白名单。
     *
     * @throws \app\common\exception\UploadException
     */
    public function assertFilenameExtension(string $filename): void
    {
        $this->file->assertFilenameExtension($filename);
    }

    public function svgUploadAllowed(): bool
    {
        return $this->file->svgUploadAllowed();
    }

    public function maxSizeMb(): float
    {
        return $this->file->maxSizeMb();
    }

    /**
     * @return array{wap_adapt:bool,add_title:bool,add_alt:bool,alt_replace:bool}
     */
    public function editorContentOptions(): array
    {
        return $this->file->editorContentOptions();
    }

    /** @return array<string, mixed> */
    public function clientUploadConfig(): array
    {
        return $this->file->clientUploadConfig();
    }

    public static function normalizeScene(string $scene): string
    {
        return UploadSceneService::normalizeScene($scene);
    }
}
