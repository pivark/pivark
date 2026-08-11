<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\model\MediaAssetRef;

use app\common\model\Document;

use app\common\service\media\MediaAssetRefService;

/** 文档扩展插件附件采集（ai_document 等统一入口） */
final class DocumentAddonAttachmentRegistry
{

    public function __construct(
        private readonly DocumentAddonSearchRegistry $addonRegistry,
        private readonly MediaAssetRefService $mediaRefs,
    ) {
    }

    /**
     * @param list<array{path:string,mime:string,name:string}> $items
     * @param array<string, true> $seen
     */
    private function appendPathsFromHtml(string $html, array &$items, array &$seen): void
    {
        if ($html === '') {
            return;
        }
        if (preg_match_all('#(?:src|href)=["\']([^"\']+\.(?:pdf|docx?|xlsx?|pptx?|txt|md))["\']#iu', $html, $m)) {
            foreach ($m[1] as $path) {
                $this->pushPathString($items, $seen, (string) $path, basename((string) $path));
            }
        }
    }

    /**
     * @param list<array{path:string,mime:string,name:string}> $items
     * @param array<string, true> $seen
     */
    private function walkPayloadPaths(mixed $node, array &$items, array &$seen, int $depth): void
    {
        if ($depth > 10 || $node === null) {
            return;
        }
        if (!is_array($node)) {
            return;
        }
        foreach (['file_path', 'path', 'litpic', 'path_snapshot', 'cover_litpic'] as $key) {
            if (isset($node[$key]) && is_string($node[$key])) {
                $name = '';
                foreach (['label', 'title', 'name', 'original_name'] as $nk) {
                    if (!empty($node[$nk]) && is_string($node[$nk])) {
                        $name = (string) $node[$nk];
                        break;
                    }
                }
                $this->pushPathString($items, $seen, (string) $node[$key], $name);
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $this->walkPayloadPaths($child, $items, $seen, $depth + 1);
            }
        }
    }

    /**
     * @param list<array{path:string,mime:string,name:string}> $items
     * @param array<string, true> $seen
     */
    private function pushPathString(array &$items, array &$seen, string $pathOrUrl, string $name): void
    {
        $abs = $this->resolveAbsolutePath($pathOrUrl);
        if ($abs === '' || isset($seen[$abs])) {
            return;
        }
        $seen[$abs] = true;
        $items[] = [
            'path' => $abs,
            'mime' => (string) (@mime_content_type($abs) ?: ''),
            'name' => $name !== '' ? $name : basename($abs),
        ];
    }

    /**
     * @param list<array{path:string,mime:string,name:string}> $items
     * @param array<string, true> $seen
     * @param array{path:string,mime:string,name:string} $file
     */
    private function pushFile(array &$items, array &$seen, array $file): void
    {
        $this->pushPathString($items, $seen, $file['path'], $file['name']);
    }

    public function resolveAbsolutePath(string $pathOrUrl): string
    {
        $path = trim($pathOrUrl);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            $u = parse_url($path, PHP_URL_PATH);
            $path = is_string($u) ? $u : '';
        }
        $path = str_replace('\\', '/', $path);
        if ($path === '') {
            return '';
        }
        if (is_file($path)) {
            return realpath($path) ?: $path;
        }
        $root = rtrim(root_path(), '/\\');
        $candidates = [
            $root . '/public' . (str_starts_with($path, '/') ? $path : '/' . $path),
            $root . (str_starts_with($path, '/') ? $path : '/' . $path),
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return realpath($c) ?: $c;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentContentRow(mixed $result): ?array
    {
        return $result instanceof Document ? $result->toArray() : null;
    }
}
