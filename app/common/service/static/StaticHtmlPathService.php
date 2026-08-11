<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 静态 HTML 路径映射（URL → public/ 下相对路径）
     * @return mixed
     * @param mixed $url
 */
declare(strict_types=1);

namespace app\common\service\static;

use app\common\service\seo\SeoStaticConfigService;
use app\common\support\ProjectPaths;

class StaticHtmlPathService
{

    public function __construct(
        private readonly SeoStaticConfigService $seoStatic,
    ) {
    }

    /**
     * 出站 URL 转为 public/ 下相对路径；无法映射（如纯 ?page= 分页）返回 null
     */
    public function relativePathFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || $url === '/') {
            $sub = $this->seoStatic->subdir();
            return $sub !== '' ? $sub . '/index.html' : 'index.html';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $path  = trim((string) ($parts['path'] ?? ''), '/');
        $query = (string) ($parts['query'] ?? '');

        if ($path === '') {
            $sub = $this->seoStatic->subdir();

            return $sub !== '' ? $sub . '/index.html' : 'index.html';
        }

        if ($query !== '') {
            parse_str($query, $params);
            $page = max(1, (int) ($params['page'] ?? 1));
            if ($page > 1) {
                return null;
            }
        }

        $sub = $this->seoStatic->subdir();
        if ($sub !== '') {
            if ($path === $sub) {
                return $sub . '/index.html';
            }
            if (!str_starts_with($path, $sub . '/')) {
                $path = $sub . '/' . $path;
            }
        }

        if (!str_contains($path, '.')) {
            return rtrim($path, '/') . '/index.html';
        }

        return $path;
    }

    /**
     * @return mixed
     */
    public function rootDir(): string
    {
        return ProjectPaths::publicDir();
    }

    /**
     * @return mixed
     * @param mixed $relativePath
     */
    public function absolutePath(string $relativePath): string
    {
        $relativePath = str_replace(['\\', "\0"], '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        return $this->rootDir() . '/' . $relativePath;
    }
}
