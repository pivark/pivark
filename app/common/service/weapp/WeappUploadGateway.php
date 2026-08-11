<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappUploadGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\upload\UploadService;
use app\common\support\ServiceResult;

final class WeappUploadGateway
{

    public function uploadAttachment(\think\file\UploadedFile $file): ServiceResult
    {
        return UploadService::scene('attachment')->handle($file);
    }

    /**
     * 插件生成物（如图集 ZIP）落 public/uploads 下相对路径。
     * 单写口：禁 weapp 自行 mkdir/file_put 平行管道。
     *
     * @return string 相对 public 的路径，如 uploads/doc_gallery_pack/1/default.zip；失败返回 ''
     */
    public function putGeneratedUnderUploads(string $relativeUnderUploads, string $absoluteSource): string
    {
        $rel = $this->normalizeUploadsRelative($relativeUnderUploads);
        if ($rel === '') {
            return '';
        }
        if (!is_file($absoluteSource) || !is_readable($absoluteSource)) {
            return '';
        }

        $abs = $this->absolutePublicPath($rel);
        if ($abs === '' || !$this->ensureParentDir($abs)) {
            return '';
        }
        if (!copy($absoluteSource, $abs)) {
            return '';
        }

        return $rel;
    }

    /**
     * 插件解压/生成的字节流落 public/uploads（如图集 ZIP 导入）。
     * 与 putGeneratedUnderUploads 同单写口，禁 weapp 自 mkdir/file_put。
     *
     * @return string 相对 public 的路径；失败返回 ''
     */
    public function putContentsUnderUploads(string $relativeUnderUploads, string $binaryContents): string
    {
        $rel = $this->normalizeUploadsRelative($relativeUnderUploads);
        if ($rel === '') {
            return '';
        }
        $abs = $this->absolutePublicPath($rel);
        if ($abs === '' || !$this->ensureParentDir($abs)) {
            return '';
        }
        if (@file_put_contents($abs, $binaryContents) === false) {
            return '';
        }

        return $rel;
    }

    private function normalizeUploadsRelative(string $relativeUnderUploads): string
    {
        $rel = ltrim(str_replace('\\', '/', $relativeUnderUploads), '/');
        if ($rel === '' || str_contains($rel, '..') || !str_starts_with($rel, 'uploads/')) {
            return '';
        }

        return $rel;
    }

    private function absolutePublicPath(string $rel): string
    {
        $root = rtrim((string) (defined('ROOT_PATH') ? ROOT_PATH : ''), '/\\');
        if ($root === '') {
            return '';
        }

        return $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    private function ensureParentDir(string $absFile): bool
    {
        $dir = dirname($absFile);

        return is_dir($dir) || (mkdir($dir, 0755, true) || is_dir($dir));
    }

    /**
     * @return array{url:string,path:string,filename:string,content_hash?:string}
     */
    public function storeFromLocalFile(string $scene, string $sourcePath, string $originalName): array
    {
        return UploadService::scene($scene)->storeFromLocalFile($sourcePath, $originalName);
    }
}
