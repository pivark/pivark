<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\weapp;

use app\common\service\plugin\PluginManifestService;
use app\common\support\PivarkVueRoute;

final class WeappAdminUiService
{
    public const MODE_CORE_VUE = 'core_vue';
    public const MODE_IFRAME   = 'iframe';

    public const DEFAULT_IFRAME_ENTRY = 'admin/dist/index.html';

    /** 文档发布页嵌入块默认静态页（相对 weapp/{id}/） */
    public const DEFAULT_DOCUMENT_EDITOR_IFRAME_ENTRY = 'admin/dist/document-editor.html';

    /**
     * @param array<string, mixed> $manifest
     */
    public function mode(array $manifest, string $identifier = ''): string
    {
        $ui     = $this->uiBlock($manifest);
        $forced = strtolower(trim((string) ($ui['mode'] ?? '')));
        if ($forced === self::MODE_IFRAME || $forced === self::MODE_CORE_VUE) {
            return $forced;
        }

        $kind = strtolower(trim((string) ($manifest['kind'] ?? '')));
        if ($kind === PluginManifestService::KIND_PLATFORM) {
            return self::MODE_CORE_VUE;
        }

        $identifier = $this->safeIdentifier($identifier !== ''
            ? $identifier
            : (string) ($manifest['identifier'] ?? ''));
        if ($identifier !== '' && PivarkVueRoute::hasExplicitWeappAdmin($identifier)) {
            return self::MODE_CORE_VUE;
        }

        $admin = is_array($manifest['admin'] ?? null) ? $manifest['admin'] : [];
        $adminRoute = trim((string) ($admin['home_route'] ?? $admin['route'] ?? ''));
        if ($adminRoute !== '' && PivarkVueRoute::isExplicitAdminHref($adminRoute)) {
            return self::MODE_CORE_VUE;
        }

        return self::MODE_IFRAME;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function iframeEntry(array $manifest): string
    {
        $ui    = $this->uiBlock($manifest);
        $entry = trim(str_replace('\\', '/', (string) ($ui['entry'] ?? '')));

        return $entry !== '' ? ltrim($entry, '/') : self::DEFAULT_IFRAME_ENTRY;
    }

    /**
     * 插件包内静态页 URL（根路径，供 SPA iframe 加载）
     *
     * @param array<string, mixed> $manifest
     */
    public function iframeSrc(string $identifier, array $manifest, ?string $entry = null): string
    {
        $identifier = $this->safeIdentifier($identifier);
        if ($identifier === '') {
            return '';
        }
        $entry = $entry ?? $this->iframeEntry($manifest);

        return '/weapp/' . $identifier . '/' . ltrim($entry, '/');
    }

    /**
     * 文档发布页插件块 iframe URL（长期方案：UI 在插件包内 dist，内核只嵌 URL）
     *
     * @param array<string, mixed> $manifest
     */
    public function documentEditorIframeSrc(
        string $identifier,
        array $manifest,
        int $documentId = 0,
        string $slot = '',
    ): string {
        $identifier = $this->safeIdentifier($identifier);
        if ($identifier === '') {
            return '';
        }

        $surfaces = is_array($manifest['surfaces'] ?? null) ? $manifest['surfaces'] : [];
        $editor   = is_array($surfaces['document_editor'] ?? null) ? $surfaces['document_editor'] : [];
        $entry    = trim(str_replace('\\', '/', (string) ($editor['iframe_entry'] ?? '')));
        if ($entry === '') {
            $entry = self::DEFAULT_DOCUMENT_EDITOR_IFRAME_ENTRY;
        }

        $src = $this->iframeSrc($identifier, $manifest, $entry);
        if ($src === '') {
            return '';
        }

        $query = [
            'embed'       => 'document_editor',
            'document_id' => max(0, $documentId),
        ];
        if ($slot !== '') {
            $query['slot'] = $slot;
        }
        $version = trim((string) ($manifest['version'] ?? ''));
        if ($version !== '') {
            $query['v'] = $version;
        }
        // 仅靠 plugin version 不够：重建 document-editor.html 后版本常不变，浏览器会继续用旧壳
        // （旧壳把 /admin SPA HTML 当错误文案吐红字）。用 dist 文件 mtime 作指纹。
        $abs = root_path() . 'weapp' . DIRECTORY_SEPARATOR . $identifier . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim($entry, '/'));
        if (is_file($abs)) {
            $mtime = (int) @filemtime($abs);
            if ($mtime > 0) {
                $query['h'] = base_convert((string) $mtime, 10, 36);
            }
        }

        return $src . (str_contains($src, '?') ? '&' : '?') . http_build_query($query);
    }

    /**
     * 插件列表/API：后台入口 href（manifest spa_path 优先于 home_route）
     *
     * @param array<string, mixed> $manifest
     */
    public function adminRouteFromManifest(array $manifest, string $identifier = ''): string
    {
        $admin = is_array($manifest['admin'] ?? null) ? $manifest['admin'] : [];
        $ui    = is_array($admin['ui'] ?? null) ? $admin['ui'] : [];
        $spa   = trim((string) ($ui['spa_path'] ?? ''));
        if ($spa !== '' && str_starts_with($spa, '/')) {
            return $spa;
        }

        $home = trim((string) ($admin['home_route'] ?? $admin['route'] ?? ''));
        if ($home !== '') {
            return $home;
        }

        $identifier = $this->safeIdentifier($identifier !== ''
            ? $identifier
            : (string) ($manifest['identifier'] ?? ''));

        return $identifier !== ''
            ? app(\app\common\service\weapp\WeappContext::class)->adminHomeRoute($identifier)
            : '';
    }

    /**
     * Vue SPA 路由 path（插件中心「管理」跳转）
     *
     * @param array<string, mixed> $manifest
     */
    public function spaPath(string $identifier, array $manifest): string
    {
        $identifier = $this->safeIdentifier($identifier);
        if ($identifier === '') {
            return '';
        }

        $uiMode = $this->mode($manifest, $identifier);
        if ($uiMode === self::MODE_IFRAME) {
            return '/weapp/' . $identifier . '/index';
        }

        $ui  = $this->uiBlock($manifest);
        $spa = trim((string) ($ui['spa_path'] ?? ''));
        if ($spa !== '' && str_starts_with($spa, '/')) {
            return $spa;
        }

        $admin = is_array($manifest['admin'] ?? null) ? $manifest['admin'] : [];
        $adminRoute = trim((string) ($admin['home_route'] ?? $admin['route'] ?? ''));
        if ($adminRoute !== '') {
            $resolved = PivarkVueRoute::resolve($adminRoute, '');
            $path = (string) ($resolved['path'] ?? '');
            if ($path !== '') {
                return $path;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array{name:string,path:string,title:string,meta:array<string,mixed>}>
     */
    public function spaRouteDefs(string $identifier, array $manifest): array
    {
        $identifier = $this->safeIdentifier($identifier);
        if ($identifier === '' || $this->mode($manifest, $identifier) !== self::MODE_IFRAME) {
            return [];
        }

        $title = trim((string) (($manifest['admin']['title'] ?? '') ?: ($manifest['name'] ?? $identifier)));
        $studly = $this->studly($identifier);
        $defs   = [[
            'name'  => 'WeappIframe' . $studly,
            'path'  => $this->spaPath($identifier, $manifest),
            'title' => $title !== '' ? $title : $identifier,
            'meta'  => [
                'iframeSrc'    => $this->iframeSrc($identifier, $manifest),
                'pivarkWeapp'  => $identifier,
                'hideInMenu'   => true,
            ],
        ]];

        foreach ([
            ['guide', '功能介绍', '/weapp/guide/index'],
            ['usage', '前台调用说明', '/weapp/usage/index'],
            ['changelog', '升级日志', '/weapp/changelog/index'],
        ] as [$seg, $segTitle, $component]) {
            $path = '/weapp/' . $identifier . '/' . $seg;
            if (PivarkVueRoute::hasExplicitPath($path)) {
                continue;
            }
            $defs[] = [
                'name'  => 'Weapp' . $studly . ucfirst($seg),
                'path'  => $path,
                'title' => $segTitle,
                'meta'  => [
                    'pivarkWeapp' => $identifier,
                    'title'       => $segTitle,
                    'hideInMenu'  => true,
                ],
                'component' => $component,
            ];
        }

        return $defs;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function uiBlock(array $manifest): array
    {
        $admin = is_array($manifest['admin'] ?? null) ? $manifest['admin'] : [];

        return is_array($admin['ui'] ?? null) ? $admin['ui'] : [];
    }

    public function safeIdentifier(string $identifier): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
    }

    private function studly(string $identifier): string
    {
        $parts = preg_split('/[_-]+/', $identifier) ?: [$identifier];
        $out   = '';
        foreach ($parts as $part) {
            $out .= ucfirst(strtolower($part));
        }

        return $out !== '' ? $out : 'Plugin';
    }
}
