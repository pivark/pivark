<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split from MediaLibraryService — 路径解析与目录扫描
 */
declare(strict_types=1);

namespace app\common\service\media;

use app\common\service\config\ConfigService;
use app\common\support\ProjectPaths;

class MediaLibraryPathService
{

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'ico', 'webp', 'svg'];

    /**
     * @return string uploads 目录绝对路径
     */
    public function uploadRoot(): string
    {
        return rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        $formats = (string) $this->config->get('upload_image_format', 'jpg|gif|png|bmp|jpeg|ico');
        $list    = [];
        foreach (explode('|', $formats) as $ext) {
            $ext = strtolower(trim($ext));
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            if ($ext !== '' && in_array($ext, self::IMAGE_EXT, true) && !in_array($ext, $list, true)) {
                $list[] = $ext;
            }
        }
        return $list !== [] ? $list : self::IMAGE_EXT;
    }

    /** @return list<string> */
    public function allowedSoftwareExtensions(): array
    {
        $formats = (string) $this->config->get('upload_software_format', 'zip|rar|7z|pdf|doc|docx|xls|xlsx|ppt|pptx|txt');
        $list    = [];
        foreach (explode('|', $formats) as $ext) {
            $ext = strtolower(trim($ext));
            if ($ext !== '' && !in_array($ext, $list, true)) {
                $list[] = $ext;
            }
        }
        return $list !== [] ? $list : ['zip', 'rar', '7z', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
    }

    /** @return list<string> */
    public function allowedAttachmentPickerExtensions(): array
    {
        $merged = array_merge($this->allowedSoftwareExtensions(), $this->allowedExtensions());
        $list   = [];
        foreach ($merged as $ext) {
            $ext = strtolower(trim($ext));
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            if ($ext !== '' && !in_array($ext, $list, true)) {
                $list[] = $ext;
            }
        }
        return $list;
    }

    /**
     * @param list<array> $bucket
     */
    public function scanDir(string $root, string $dir, array $allowed, array &$bucket): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        $rootReal = realpath($root);
        if ($rootReal === false) {
            return;
        }

        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            $real = realpath($path);
            if ($real === false || !str_starts_with($real, $rootReal)) {
                continue;
            }
            if (is_dir($real)) {
                $this->scanDir($root, $real, $allowed, $bucket);
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            if (!in_array($ext, $allowed, true)) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($real, strlen($rootReal))), '/');
            $parts    = explode('/', $relative);
            $top      = count($parts) > 1 ? (string) $parts[0] : '';

            $bucket[] = [
                'url'         => app(MediaUrlService::class)->formatForStorage('/uploads/' . $relative),
                'path'        => 'uploads/' . $relative,
                'name'        => $name,
                'folder_top'  => $top,
                'folder_path' => dirname($relative) === '.' ? '' : dirname($relative),
                'size'        => (int) filesize($real),
                'mtime'       => (int) filemtime($real),
            ];
        }
    }

    /** 供 MediaAssetRefService 等删除实体前解析路径 */
    public function resolveFileRealPathPublic(string $pathOrUrl): ?string
    {
        return $this->resolveFileRealPath(
            $pathOrUrl,
            array_values(array_unique(array_merge($this->allowedExtensions(), $this->allowedSoftwareExtensions()))),
        );
    }

    /**
     * @param list<string>|null $allowedExtensions
     */
    public function resolveFileRealPath(string $pathOrUrl, ?array $allowedExtensions = null): ?string
    {
        $relative = $this->normalizeRelativePath($pathOrUrl);
        if ($relative === null) {
            return null;
        }

        $root     = $this->uploadRoot();
        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            return null;
        }

        $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($full);
        if ($real === false || !is_file($real)) {
            return null;
        }

        $prefix = $rootReal . DIRECTORY_SEPARATOR;
        if (!str_starts_with($real, $prefix)) {
            return null;
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        $allowed = $allowedExtensions ?? $this->allowedExtensions();
        if (!in_array($ext, $allowed, true)) {
            return null;
        }

        return $real;
    }

    /**
     * 单次遍历 uploads（避免 collectAllFiles 重复全盘 scanDir）
     *
     * @return list<array<string, mixed>>
     */
    public function scanUploadFilesOnce(string $kind, string $folder = ''): array
    {
        $root = $this->uploadRoot();
        if (!is_dir($root)) {
            return [];
        }

        $allowed = $kind === 'software'
            ? $this->allowedAttachmentPickerExtensions()
            : $this->allowedExtensions();

        $scanRoot = $root;
        if ($folder !== '' && !str_contains($folder, '..')) {
            $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
            $real      = realpath($candidate);
            $rootReal  = realpath($root);
            if ($real !== false && $rootReal !== false && str_starts_with($real, $rootReal) && is_dir($real)) {
                $scanRoot = $real;
            } else {
                return [];
            }
        }

        $all = [];
        $this->scanDir($root, $scanRoot, $allowed, $all);

        return $all;
    }
public function kindFromPathPublic(string $pathOrUrl): string
    {
        return $this->kindFromPath($pathOrUrl);
    }
public function kindFromPath(string $pathOrUrl): string
    {
        $name = basename(str_replace('\\', '/', $pathOrUrl));
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        return in_array($ext, $this->allowedExtensions(), true) ? 'image' : 'software';
    }
public function normalizeTargetDir(string $dir): ?string
    {
        $dir = trim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || str_contains($dir, '..')) {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\/-]*$/', $dir)) {
            return null;
        }
        return $dir;
    }

    /**
     * @param list<array{id:string,name:string,count:int}> $folders
     * @return list<array{id:string,name:string}>
     */
    public function moveTargets(array $folders): array
    {
        $targets = [];
        foreach ($folders as $f) {
            $id = (string) ($f['id'] ?? '');
            if ($id === '' || preg_match('/^\d{6,8}$/', $id) === 1) {
                continue;
            }
            $targets[] = ['id' => $id, 'name' => (string) ($f['name'] ?? $id)];
        }
        $builtin = [
            ['id' => 'document', 'name' => '文章'],
            ['id' => 'editor', 'name' => '编辑器'],
            ['id' => 'user', 'name' => '用户'],
            ['id' => 'dealer', 'name' => '经销商'],
        ];
        $ids = array_column($targets, 'id');
        foreach ($builtin as $b) {
            if (!in_array($b['id'], $ids, true)) {
                $targets[] = $b;
            }
        }
        return $targets;
    }

    /** 将 path/url 规范为 uploads 下的相对路径（不含 uploads/ 前缀） */
    public function normalizeRelativePath(string $input): ?string
    {
        $input = trim(str_replace('\\', '/', $input));
        if ($input === '' || str_contains($input, '..')) {
            return null;
        }

        $uploads = app(MediaUrlService::class)->extractUploadsPublicPath($input);
        if ($uploads !== null) {
            $input = substr($uploads, strlen('/uploads/'));
        } elseif (str_starts_with($input, '/uploads/')) {
            $input = substr($input, strlen('/uploads/'));
        } elseif (str_starts_with($input, 'uploads/')) {
            $input = substr($input, strlen('uploads/'));
        }

        $input = ltrim($input, '/');
        if ($input === '' || str_contains($input, '..')) {
            return null;
        }

        return $input;
    }
public function folderLabel(string $id): string
    {
        if (preg_match('/^\d{6,8}$/', $id) === 1) {
            return $id;
        }

        return match ($id) {
            'document' => '文章',
            'editor'  => '编辑器',
            'files'   => '附件',
            'media'   => '多媒体',
            'user'    => '用户',
            'dealer'  => '经销商',
            default   => $id,
        };
    }

}
