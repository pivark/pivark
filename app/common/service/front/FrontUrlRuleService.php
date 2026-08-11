<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\front;

use app\common\service\infra\UrlPathService;
use app\common\service\seo\SeoStaticConfigService;
use app\common\service\tag\TagService;
use app\common\service\site\SiteUrlModeService;

/** 前台 URL 规则引擎：出站拼链 + 入站解析（对称） */
class FrontUrlRuleService
{
    /** 动态模式入口前缀（TP PATHINFO；无 Web 重写也可开） */
    private const DYNAMIC_ENTRY = '/index.php';

    public function __construct(
        private readonly UrlPathService $urlPathService,
        private readonly SiteUrlModeService $siteUrlModeService,
        private readonly TagService $tagService,
        private readonly SeoStaticConfigService $seoStaticConfigService,
    ) {
    }

    /** 单页 / 标签频道首页（第 1 页） */
    public function buildChannelHome(string $path, int $page = 1): string
    {
        $path = $this->urlPathService->normalize($path);
        if ($path === '') {
            return $this->finalizeOutlink('/');
        }
        if ($page > 1) {
            return $this->buildTagListPage($path, $page);
        }

        if (!$this->siteUrlModeService->usesPrettyUrl()) {
            return $this->finalizeOutlink($this->withDynamicEntry('/' . $path));
        }

        if ($this->siteUrlModeService->channelRule() === SiteUrlModeService::CHANNEL_DIR_INDEX) {
            return $this->finalizeOutlink($this->withSuffix('/' . $path . '/index'));
        }

        return $this->finalizeOutlink($this->withSuffix('/' . $path));
    }

    /** 标签 / 单页频道列表分页 */
    public function buildTagListPage(string $path, int $page): string
    {
        $path = $this->urlPathService->normalize($path);
        $page = max(1, $page);
        if ($path === '') {
            return $this->finalizeOutlink('/');
        }
        if ($page <= 1) {
            return $this->buildChannelHome($path, 1);
        }

        $home = $this->buildChannelHome($path, 1);
        $rule = $this->siteUrlModeService->tagPageRule();

        if ($rule === SiteUrlModeService::TAG_PAGE_PATH) {
            if ($this->siteUrlModeService->usesPrettyUrl()) {
                $suffix = $this->siteUrlModeService->suffix();
                // 用未加存储前缀的栏目 path 拼分页，避免 /html 前缀干扰 strip
                $channelHome = $this->siteUrlModeService->channelRule() === SiteUrlModeService::CHANNEL_DIR_INDEX
                    ? $this->withSuffix('/' . $path . '/index')
                    : $this->withSuffix('/' . $path);
                $bare = $this->siteUrlModeService->stripSuffix(trim($channelHome, '/'));
                if ($this->siteUrlModeService->channelRule() === SiteUrlModeService::CHANNEL_DIR_INDEX) {
                    $bare = preg_replace('#/index$#', '', $bare) ?? $bare;
                }

                $paged = $suffix !== ''
                    ? '/' . $bare . '/' . $page . $suffix
                    : '/' . $bare . '/' . $page;

                return $this->finalizeOutlink($paged);
            }

            return $this->finalizeOutlink($this->withDynamicEntry('/' . $path . '/' . $page));
        }

        if ($rule === SiteUrlModeService::TAG_PAGE_LIST) {
            $base = '/' . $path;
            if ($this->siteUrlModeService->usesPrettyUrl()) {
                return $this->finalizeOutlink($this->withSuffix($base . '/list_' . $page));
            }

            return $this->finalizeOutlink($this->withDynamicEntry($base . '/list_' . $page));
        }

        return $home . '?page=' . $page;
    }

    /** 系统列表 /articles、/tags、/documents */
    public function buildSystemList(string $base, int $page): string
    {
        $base = '/' . trim($base, '/');
        $page = max(1, $page);

        if (!$this->siteUrlModeService->usesPrettyUrl()) {
            $url = $this->finalizeOutlink($this->withDynamicEntry($base));

            return $page <= 1 ? $url : $url . '?page=' . $page;
        }

        if ($page <= 1) {
            return $this->finalizeOutlink($this->withSuffix($base));
        }

        $rule = $this->siteUrlModeService->tagPageRule();
        if ($rule === SiteUrlModeService::TAG_PAGE_PATH) {
            return $this->finalizeOutlink($this->withSuffix($base . '/' . $page));
        }
        if ($rule === SiteUrlModeService::TAG_PAGE_LIST) {
            return $this->finalizeOutlink($this->withSuffix($base . '/list_' . $page));
        }

        // 仅伪静态允许 ?page=（静态已在 tagPageRule 强制 path）
        return $this->finalizeOutlink($this->withSuffix($base)) . '?page=' . $page;
    }

    /** 文档是否有 SEO 友好路径（url_path 或合法 html_name），与 buildDocument 对称 */
    public function documentHasFriendlyPublicKey(array $row): bool
    {
        $urlPath = trim((string) ($row['url_path'] ?? ''));
        if ($urlPath !== '') {
            return $this->urlPathService->normalize($urlPath) !== '';
        }
        $htmlName = trim((string) ($row['html_name'] ?? ''));

        return $htmlName !== '' && preg_match('/^[a-zA-Z0-9_\-]+$/', $htmlName) === 1;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $tagRow  tag_dir 规则用
     */
    public function buildDocument(array $row, ?array $tagRow = null): string
    {
        $urlPath = trim((string) ($row['url_path'] ?? ''));
        if ($urlPath !== '') {
            return $this->buildChannelHome($urlPath, 1);
        }

        $id       = (int) ($row['id'] ?? 0);
        $htmlName = trim((string) ($row['html_name'] ?? ''));
        $key      = ($htmlName !== '' && preg_match('/^[a-zA-Z0-9_\-]+$/', $htmlName) === 1)
            ? rawurlencode($htmlName)
            : (string) max(1, $id);

        $rule = $this->siteUrlModeService->articleRule();
        if (!$this->siteUrlModeService->usesPrettyUrl()) {
            $rule = SiteUrlModeService::ARTICLE_UNDER;
        }

        if ($rule === SiteUrlModeService::ARTICLE_ROOT) {
            return $this->buildChannelHome($key, 1);
        }

        // 有主频道 url_path 时统一 /{channel}/{key}（如 /downloads/pv-demo-download-06）
        // tag_dir 与 under_documents 一致：列表勿再吐 /documents/ 兼容链
        if (
            $tagRow !== null
            && (
                $rule === SiteUrlModeService::ARTICLE_TAG_DIR
                || $rule === SiteUrlModeService::ARTICLE_UNDER
            )
        ) {
            $tagPath = $this->tagService->publicPath($tagRow);
            if ($tagPath !== '') {
                $nested = '/' . $tagPath . '/' . $key;

                return $this->siteUrlModeService->usesPrettyUrl()
                    ? $this->finalizeOutlink($this->withSuffix($nested))
                    : $this->finalizeOutlink($this->withDynamicEntry($nested));
            }
        }

        $under = '/documents/' . $key;

        return $this->siteUrlModeService->usesPrettyUrl()
            ? $this->finalizeOutlink($this->withSuffix($under))
            : $this->finalizeOutlink($this->withDynamicEntry($under));
    }

    /**
     * 解析入站路径（单段或多段）
     *
     * @return array{path:string,page:int,sub_key:string,kind:string}
     */
    public function parseInbound(string $rawPath, int $queryPage = 1): array
    {
        $raw = trim($rawPath, '/');
        if ($raw === '') {
            return ['path' => '', 'page' => max(1, $queryPage), 'sub_key' => '', 'kind' => ''];
        }

        $segments = array_values(array_filter(explode('/', $raw), static fn (string $s): bool => $s !== ''));
        foreach ($segments as &$seg) {
            $seg = $this->siteUrlModeService->stripSuffix($seg);
        }
        unset($seg);

        if ($segments === []) {
            return ['path' => '', 'page' => max(1, $queryPage), 'sub_key' => '', 'kind' => ''];
        }

        if (count($segments) === 1) {
            return [
                'path'    => $this->urlPathService->normalize($segments[0]),
                'page'    => max(1, $queryPage),
                'sub_key' => '',
                'kind'    => 'single',
            ];
        }

        $last = (string) $segments[count($segments) - 1];
        $parentSegs = array_slice($segments, 0, -1);
        $parentPath = $this->urlPathService->normalize(implode('/', $parentSegs));

        if ($last === 'index') {
            return [
                'path'    => $parentPath,
                'page'    => max(1, $queryPage),
                'sub_key' => '',
                'kind'    => 'channel_home',
            ];
        }

        $listPage = $this->parseListPageToken($last);
        if ($listPage > 0) {
            return ['path' => $parentPath, 'page' => $listPage, 'sub_key' => '', 'kind' => 'tag_list'];
        }

        // 纯数字末段：分页规则 path 时由 resolvePaged 处理；此处作 nested（文档 id / 子栏）
        // sub_key 勿走 normalize（保留文档 html_name 大小写）
        return [
            'path'    => $parentPath,
            'page'    => max(1, $queryPage),
            'sub_key' => $last,
            'kind'    => 'nested',
        ];
    }

    public function parsePageSegment(string $pageParam): int
    {
        $token = $this->siteUrlModeService->stripSuffix(trim($pageParam));
        if ($token === 'index') {
            return 1;
        }
        $listPage = $this->parseListPageToken($token);
        if ($listPage > 0) {
            return $listPage;
        }
        if ($token !== '' && ctype_digit($token)) {
            return max(1, (int) $token);
        }

        return 0;
    }

    public function withSuffix(string $url): string
    {
        if (!$this->siteUrlModeService->usesPrettyUrl()) {
            return $url;
        }
        $suffix = $this->siteUrlModeService->suffix();
        if ($suffix === '') {
            return $url;
        }

        $parts = explode('/', trim($url, '/'));
        if ($parts === []) {
            return '/';
        }

        $last = (string) $parts[count($parts) - 1];
        if (!str_ends_with($last, $suffix)) {
            $parts[count($parts) - 1] = $last . $suffix;
        }

        return '/' . implode('/', $parts);
    }

    /** 动态出站：/path → /index.php/path（首页 / 不变；保留 ?#） */
    public function applyDynamicEntry(string $path): string
    {
        if ($this->siteUrlModeService->usesPrettyUrl()) {
            // 伪静态/静态：member/search 等非静态落盘路径保持原样（不加 seo_static_subdir）
            return $path === '' ? '/' : $path;
        }

        return $this->withDynamicEntry($path);
    }

    /** 出站收口：静态模式加 seo_static_subdir 前缀（与落盘路径所见即所得） */
    private function finalizeOutlink(string $url): string
    {
        return $this->seoStaticConfigService->applyStaticStoragePrefix($url);
    }

    /** 动态出站内部：不判断模式（调用方已判定） */
    private function withDynamicEntry(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $hash = '';
        $query = '';
        if (str_contains($path, '#')) {
            [$path, $frag] = explode('#', $path, 2);
            $hash = '#' . $frag;
        }
        if (str_contains($path, '?')) {
            [$path, $q] = explode('?', $path, 2);
            $query = '?' . $q;
        }

        $path = '/' . ltrim($path, '/');
        if (str_starts_with($path, self::DYNAMIC_ENTRY . '/') || $path === self::DYNAMIC_ENTRY) {
            return $path . $query . $hash;
        }

        return self::DYNAMIC_ENTRY . $path . $query . $hash;
    }

    private function parseListPageToken(string $token): int
    {
        if (preg_match('/^list_(\d+)$/i', $token, $m) === 1) {
            return max(1, (int) $m[1]);
        }

        return 0;
    }
}
