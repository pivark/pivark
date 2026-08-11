<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\support\ProjectPaths;

use app\common\service\front\FrontAuthService;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\theme\ThemeService;
use app\common\support\LocalFile;

final class TemplateBlockCacheService
{

    public function __construct(
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly ThemeService $themeService,
        private readonly FrontAuthService $frontAuthService,
    ) {
    }

    /** @var array<string, string> */
    private static array $memory = [];

    public function ttl(): int
    {
        return max(0, (int) config('pivark.block_cache_ttl', 120));
    }

    public function enabled(): bool
    {
        return $this->ttl() > 0;
    }

    public function get(string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        if (isset(self::$memory[$key])) {
            return self::$memory[$key];
        }
        if (!$this->enabled()) {
            return null;
        }
        $file = $this->filePath($key);
        if (!is_file($file)) {
            return null;
        }
        $meta = $this->readMeta($file);
        if ($meta !== null && (int) ($meta['generation'] ?? 0) !== $this->frontCacheInvalidator->generation()) {
            LocalFile::unlinkIfExists($file);
            LocalFile::unlinkIfExists($file . '.meta');

            return null;
        }
        if (time() - (int) filemtime($file) > $this->ttl()) {
            LocalFile::unlinkIfExists($file);

            return null;
        }
        $html = (string) file_get_contents($file);

        return $html !== '' ? $html : null;
    }

    public function set(string $key, string $html): void
    {
        if ($key === '' || $html === '') {
            return;
        }
        self::$memory[$key] = $html;
        if (!$this->enabled()) {
            return;
        }
        $file = $this->filePath($key);
        $dir  = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        file_put_contents($file, $html, LOCK_EX);
        file_put_contents(
            $file . '.meta',
            json_encode(['generation' => $this->frontCacheInvalidator->generation()], JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /**
     * @param array<string, mixed> $attrs  tagdocuments 属性
     */
    public function keyForTagdocuments(array $attrs, string $innerTpl): string
    {
        $theme = $this->themeService->getCurrentTheme();
        $norm  = [
            'tags'      => (string) ($attrs['tags'] ?? $attrs['tag'] ?? ''),
            'tagid'     => (string) ($attrs['tagid'] ?? $attrs['tag_id'] ?? ''),
            'tagids'    => (string) ($attrs['tagids'] ?? $attrs['tag_ids'] ?? ''),
            'tagname'   => (string) ($attrs['tagname'] ?? $attrs['tag_name'] ?? ''),
            'tagurl'    => (string) ($attrs['tagurl'] ?? $attrs['tag_url'] ?? $attrs['tagpath'] ?? ''),
            'nav'       => (string) ($attrs['nav'] ?? ''),
            'navid'     => (string) ($attrs['navid'] ?? $attrs['nav_id'] ?? ''),
            'navtarget' => (string) ($attrs['navtarget'] ?? $attrs['nav_target'] ?? ''),
            'navurl'    => (string) ($attrs['navurl'] ?? $attrs['nav_url'] ?? ''),
            'tag_match' => (string) ($attrs['tag_match'] ?? $attrs['match'] ?? ''),
            'exclude_tags' => (string) ($attrs['exclude_tags'] ?? $attrs['exclude_tag'] ?? $attrs['notags'] ?? ''),
            'exclude_tagids' => (string) ($attrs['exclude_tagids'] ?? $attrs['exclude_tag_ids'] ?? ''),
            'tag_group_id' => (string) ($attrs['tag_group_id'] ?? $attrs['group_id'] ?? ''),
            'exclude_tag_group_id' => (string) ($attrs['exclude_tag_group_id'] ?? $attrs['exclude_group_id'] ?? ''),
            'period'    => (string) ($attrs['period'] ?? ''),
            'since'     => (string) ($attrs['since'] ?? $attrs['published_since'] ?? ''),
            'until'     => (string) ($attrs['until'] ?? $attrs['published_until'] ?? ''),
            'row'     => (int) ($attrs['row'] ?? $attrs['loop'] ?? 10),
            'offset'  => (int) ($attrs['offset'] ?? 0),
            'limit'   => (string) ($attrs['limit'] ?? ''),
            'page'    => (int) ($attrs['page'] ?? 1),
            'orderby' => (string) ($attrs['orderby'] ?? ''),
            'sort'    => (string) ($attrs['sort'] ?? ''),
            'keyword' => (string) ($attrs['keyword'] ?? ''),
            'attr'    => (string) ($attrs['attr'] ?? $attrs['flag'] ?? ''),
            'noattr'  => (string) ($attrs['noattr'] ?? $attrs['noflag'] ?? ''),
            'entity'  => (string) ($attrs['entity'] ?? ''),
            'has'     => (string) ($attrs['has'] ?? ''),
            'nohas'   => (string) ($attrs['nohas'] ?? ''),
            'ids'     => (string) ($attrs['ids'] ?? $attrs['idlist'] ?? ''),
            'id'      => (int) ($attrs['id'] ?? $attrs['aid'] ?? 0),
            'tpl'     => md5($innerTpl),
            'audience'=> $this->audienceKey(),
        ];

        return 'tagdocuments_' . $theme . '_' . hash('sha256', json_encode($norm, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $attrs tagcloud 属性
     */
    public function keyForTagcloud(array $attrs, string $innerTpl): string
    {
        $theme = $this->themeService->getCurrentTheme();
        $norm  = [
            'row'               => (int) ($attrs['row'] ?? $attrs['loop'] ?? 20),
            'page'              => (int) ($attrs['page'] ?? 1),
            'sort'              => (string) ($attrs['sort'] ?? $attrs['orderby'] ?? $attrs['mode'] ?? ''),
            'kind'              => (string) ($attrs['kind'] ?? ''),
            'group_id'          => (string) ($attrs['group_id'] ?? $attrs['tag_group_id'] ?? ''),
            'tag_group_ids'     => (string) ($attrs['tag_group_ids'] ?? $attrs['group_ids'] ?? ''),
            'tagids'            => (string) ($attrs['tagids'] ?? $attrs['tag_ids'] ?? $attrs['tags'] ?? $attrs['tagid'] ?? ''),
            'exclude_tagids'    => (string) ($attrs['exclude_tagids'] ?? $attrs['exclude_tags'] ?? $attrs['notags'] ?? ''),
            'exclude_group_ids' => (string) ($attrs['exclude_tag_group_id'] ?? $attrs['exclude_group_ids'] ?? ''),
            'parent_id'         => (string) ($attrs['parent_id'] ?? $attrs['parent_tag_id'] ?? ''),
            'min_docs'          => (string) ($attrs['min_document_count'] ?? $attrs['min_docs'] ?? ''),
            'has_documents'     => (string) ($attrs['has_documents'] ?? ''),
            'keyword'           => (string) ($attrs['keyword'] ?? ''),
            'period'            => (string) ($attrs['period'] ?? ''),
            'since'             => (string) ($attrs['since'] ?? ''),
            'tpl'               => md5($innerTpl),
            'audience'          => $this->audienceKey(),
        ];

        return 'tagcloud_' . $theme . '_' . hash('sha256', json_encode($norm, JSON_UNESCAPED_UNICODE));
    }

    public function clearAll(): void
    {
        self::$memory = [];
        $dir = $this->cacheRoot();
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                LocalFile::rmdirIfExists($file->getPathname());
            } else {
                LocalFile::unlinkIfExists($file->getPathname());
            }
        }
    }

    private function audienceKey(): string
    {
        if ($this->frontAuthService->isLoggedIn()) {
            $member = $this->frontAuthService->current();
            $level  = is_array($member) ? (int) ($member['member_level_id'] ?? 0) : 0;

            return 'm' . $level;
        }

        return 'guest';
    }

    private function cacheRoot(): string
    {
        return ProjectPaths::runtimeDir() . 'block_cache';
    }

    private function filePath(string $key): string
    {
        $hash = hash('sha256', $this->frontCacheInvalidator->generation() . '|' . $key);

        return $this->cacheRoot() . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . $hash . '.html';
    }

    /**
     * @return array{generation?:int}|null
     */
    private function readMeta(string $htmlFile): ?array
    {
        $metaFile = $htmlFile . '.meta';
        if (!is_file($metaFile)) {
            return null;
        }
        $raw = (string) file_get_contents($metaFile);
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
