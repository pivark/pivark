<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\model\SiteNav;
use app\common\service\infra\BreadcrumbService;
use app\common\service\site\SiteNavService;
use app\common\service\tag\TagService;
use app\common\support\DbRead;

/**
 * 模板筛选解析：`{pv:arclist}` 文档/品项 · `{pv:tagcloud}` 标签目录共用。
 *
 * 栏目意图（navid / nav=page…）→ `nav_id` 直读；Tag 意图（tagid / tags…）→ 聚合；两者不混读。
 */
class ArclistTagResolveService
{

    /**
     * 栏目意图：返回可挂载内容的 site_nav.id；无栏目意图则 0。
     *
     * @param array<string, string> $attrs
     * @param array<string, mixed>  $pageVars
     */
    public function resolveContentNavId(array $attrs, array $pageVars = []): int
    {
        $navMode = strtolower(trim((string) ($attrs['nav'] ?? '')));
        if (in_array($navMode, ['page', 'current', 'context'], true)) {
            $fromPage = (int) ($pageVars['nav_id'] ?? $pageVars['channel_nav_id'] ?? 0);
            if ($fromPage > 0 && app(SiteNavService::class)->isContentCategoryId($fromPage)) {
                return $fromPage;
            }
        }

        foreach ($this->tokens((string) ($attrs['navid'] ?? $attrs['nav_id'] ?? '')) as $token) {
            if (preg_match('/^\d+$/', $token)) {
                $nid = (int) $token;
                if ($nid > 0 && app(SiteNavService::class)->isContentCategoryId($nid)) {
                    return $nid;
                }
            }
        }

        foreach ($this->tokens((string) ($attrs['navtarget'] ?? $attrs['nav_target'] ?? '')) as $token) {
            $nid = $this->navIdFromNavTarget($token);
            if ($nid > 0) {
                return $nid;
            }
        }

        foreach ($this->tokens((string) ($attrs['navurl'] ?? $attrs['nav_url'] ?? $attrs['navpath'] ?? '')) as $token) {
            $nid = $this->navIdFromNavUrl($token);
            if ($nid > 0) {
                return $nid;
            }
        }

        return 0;
    }

    /**
     * @param array<string, string> $attrs
     * @param array<string, mixed>  $pageVars
     */
    public function resolveTagsParam(array $attrs, array $pageVars = []): string
    {
        if ($this->resolveContentNavId($attrs, $pageVars) > 0) {
            return '';
        }

        return implode(',', $this->resolveSlugList($attrs, $pageVars));
    }

    /** @param array<string, string> $attrs */
    public function resolveTagMatch(array $attrs): string
    {
        $raw = strtolower(trim((string) ($attrs['tag_match'] ?? $attrs['match'] ?? 'all')));

        return in_array($raw, ['any', 'or'], true) ? 'any' : 'all';
    }

    /**
     * @param array<string, string>     $attrs
     * @param array<string, mixed>|null $pageVars
     * @return list<int>
     */
    public function resolveIncludeTagIds(array $attrs, ?array $pageVars = null): array
    {
        $slugs = $this->resolveSlugList($attrs, $pageVars ?? []);
        $ids   = [];
        foreach ($slugs as $slug) {
            $this->pushTagId($ids, $this->idFromSlug($slug));
        }
        $ids = array_values(array_unique(array_filter($ids)));
        // 单 Tag（栏目导航/频道上下文）默认含下级
        if (count($slugs) === 1 && $ids !== []) {
            $ids = app(\app\common\service\tag\TagCore::class)->idsWithDescendants($ids);
        }

        return $ids;
    }

    /**
     * @param array<string, string> $attrs
     * @return list<int>
     */
    public function resolveExcludeTagIds(array $attrs): array
    {
        $ids = [];
        foreach ($this->resolveExcludeSlugList($attrs) as $slug) {
            $this->pushTagId($ids, $this->idFromSlug($slug));
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param array<string, string> $attrs
     * @return list<int>
     */
    public function resolveGroupIds(array $attrs): array
    {
        return $this->parseIdList(
            $attrs['tag_group_ids'] ?? $attrs['tag_group_id'] ?? $attrs['group_ids'] ?? $attrs['group_id'] ?? ''
        );
    }

    /**
     * @param array<string, string> $attrs
     * @return list<int>
     */
    public function resolveExcludeGroupIds(array $attrs): array
    {
        return $this->parseIdList(
            $attrs['exclude_tag_group_ids'] ?? $attrs['exclude_tag_group_id']
                ?? $attrs['exclude_group_ids'] ?? $attrs['exclude_group_id'] ?? ''
        );
    }

    /**
     * @param array<string, string> $attrs
     * @return int 0=未指定
     */
    public function resolveParentId(array $attrs): int
    {
        $raw = trim((string) ($attrs['parent_id'] ?? $attrs['parent_tag_id'] ?? $attrs['tag_parent_id'] ?? ''));
        if (preg_match('/^\d+$/', $raw)) {
            return (int) $raw;
        }

        $slug = trim((string) ($attrs['parent_slug'] ?? ''));
        if ($slug === '' && $raw !== '') {
            // parent_id="product-plugin" 也认 slug
            $slug = $raw;
        }
        if ($slug === '') {
            return 0;
        }

        $row = app(TagService::class)->findRowBySlug($slug);
        if (!is_array($row)) {
            $row = app(TagService::class)->findRowByUrlPath($slug);
        }

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /**
     * @param array<string, string> $attrs
     * @return array{
     *   nav_id?:int,
     *   tag_match:string,
     *   include_tag_ids:list<int>,
     *   exclude_tag_ids:list<int>,
     *   tag_group_ids:list<int>,
     *   exclude_tag_group_ids:list<int>,
     *   parent_id:int,
     *   tags:string
     * }
     */
    public function resolveDocumentFilterParams(array $attrs, array $pageVars = []): array
    {
        $navId = $this->resolveContentNavId($attrs, $pageVars);
        if ($navId > 0) {
            return [
                'nav_id'                 => $navId,
                'tag_match'              => 'all',
                'include_tag_ids'        => [],
                'exclude_tag_ids'        => $this->resolveExcludeTagIds($attrs),
                'tag_group_ids'          => [],
                'exclude_tag_group_ids'  => $this->resolveExcludeGroupIds($attrs),
                'parent_id'              => 0,
                'tags'                   => '',
            ];
        }

        $slugs      = $this->resolveSlugList($attrs, $pageVars);
        $includeIds = $this->resolveIncludeTagIds($attrs, $pageVars);
        $match      = $this->resolveTagMatch($attrs);
        if (count($slugs) === 1 && count($includeIds) > 1) {
            $match = 'any';
        }

        return [
            'tag_match'              => $match,
            'include_tag_ids'        => $includeIds,
            'exclude_tag_ids'        => $this->resolveExcludeTagIds($attrs),
            'tag_group_ids'          => $this->resolveGroupIds($attrs),
            'exclude_tag_group_ids'  => $this->resolveExcludeGroupIds($attrs),
            'parent_id'              => 0,
            'tags'                   => implode(',', $slugs),
        ];
    }

    /**
     * @param array<string, string> $attrs
     * @return array{
     *   include_tag_ids:list<int>,
     *   exclude_tag_ids:list<int>,
     *   tag_group_ids:list<int>,
     *   exclude_tag_group_ids:list<int>,
     *   parent_id:int,
     *   min_document_count:int
     * }
     */
    public function resolveCatalogFilterParams(array $attrs): array
    {
        $minDocs = max(0, (int) ($attrs['min_document_count'] ?? $attrs['min_docs'] ?? 0));
        if ($minDocs < 1 && in_array(strtolower(trim((string) ($attrs['has_documents'] ?? ''))), ['1', 'true', 'yes'], true)) {
            $minDocs = 1;
        }

        $includeIds = $this->resolveIncludeTagIds($attrs, null);
        if ($includeIds === []) {
            $includeIds = $this->parseIdList(
                $attrs['tagids'] ?? $attrs['tag_ids'] ?? $attrs['tagid'] ?? $attrs['tag_id'] ?? ''
            );
        }

        return [
            'include_tag_ids'       => $includeIds,
            'exclude_tag_ids'       => $this->resolveExcludeTagIds($attrs),
            'tag_group_ids'         => $this->resolveGroupIds($attrs),
            'exclude_tag_group_ids' => $this->resolveExcludeGroupIds($attrs),
            'parent_id'             => $this->resolveParentId($attrs),
            'min_document_count'    => $minDocs,
        ];
    }

    /**
     * @param array<string, string> $attrs
     * @param array<string, mixed>  $pageVars
     * @return list<string>
     */
    public function resolveSlugList(array $attrs, array $pageVars = []): array
    {
        // 栏目意图已由 resolveContentNavId 承接；此处不再把 navid/navtarget 弯成 Tag slug
        if ($this->resolveContentNavId($attrs, $pageVars) > 0) {
            return [];
        }

        $slugs   = [];
        $navMode = strtolower(trim((string) ($attrs['nav'] ?? '')));

        // nav=page 且无 pageVars.nav_id 时：兼容纯 Tag 列表页（读 tag_slug）
        if (in_array($navMode, ['page', 'current', 'context'], true)) {
            $slug = $this->slugFromPageContext($pageVars);
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        foreach ($this->tokens($attrs['tagid'] ?? $attrs['tag_id'] ?? '') as $token) {
            if (preg_match('/^\d+$/', $token)) {
                $this->pushSlug($slugs, $this->slugFromId((int) $token));
            }
        }

        foreach ($this->tokens($attrs['tagids'] ?? $attrs['tag_ids'] ?? '') as $token) {
            if (preg_match('/^\d+$/', $token)) {
                $this->pushSlug($slugs, $this->slugFromId((int) $token));
            }
        }

        foreach ($this->tokens($attrs['tagname'] ?? $attrs['tag_name'] ?? '') as $token) {
            $this->pushSlug($slugs, $this->slugFromName($token));
        }

        foreach ($this->tokens($attrs['tagnames'] ?? $attrs['tag_names'] ?? '') as $token) {
            $this->pushSlug($slugs, $this->slugFromName($token));
        }

        foreach ($this->tokens($attrs['tagurl'] ?? $attrs['tag_url'] ?? $attrs['tagpath'] ?? $attrs['tag_path'] ?? '') as $token) {
            $this->pushSlug($slugs, $this->slugFromUrlPath($token));
        }

        // 不用 typeid（易与栏目 ID 歧义）；Tag 只用 tags/tag/tagid/tagname/tagurl
        $tagsRaw = trim((string) ($attrs['tags'] ?? $attrs['tag'] ?? ''));
        if ($tagsRaw !== '') {
            foreach ($this->tokens($tagsRaw) as $token) {
                $this->pushSlug($slugs, $this->resolveLegacyToken($token));
            }
        }

        return array_values(array_unique(array_filter($slugs)));
    }

    /**
     * @param array<string, string> $attrs
     * @return list<string>
     */
    public function resolveExcludeSlugList(array $attrs): array
    {
        $slugs = [];

        foreach ($this->tokens($attrs['exclude_tagid'] ?? $attrs['exclude_tag_id'] ?? '') as $token) {
            if (preg_match('/^\d+$/', $token)) {
                $this->pushSlug($slugs, $this->slugFromId((int) $token));
            }
        }

        foreach ($this->tokens($attrs['exclude_tagids'] ?? $attrs['exclude_tag_ids'] ?? '') as $token) {
            if (preg_match('/^\d+$/', $token)) {
                $this->pushSlug($slugs, $this->slugFromId((int) $token));
            }
        }

        foreach ($this->tokens($attrs['exclude_tagname'] ?? $attrs['exclude_tag_name'] ?? '') as $token) {
            $this->pushSlug($slugs, $this->slugFromName($token));
        }

        foreach ($this->tokens($attrs['exclude_tagnames'] ?? $attrs['exclude_tag_names'] ?? '') as $token) {
            $this->pushSlug($slugs, $this->slugFromName($token));
        }

        foreach ($this->tokens($attrs['exclude_tagurl'] ?? $attrs['exclude_tag_url'] ?? $attrs['exclude_tagpath'] ?? $attrs['exclude_tag_path'] ?? '') as $token) {
            $this->pushSlug($slugs, $this->slugFromUrlPath($token));
        }

        $tagsRaw = trim((string) ($attrs['exclude_tags'] ?? $attrs['exclude_tag'] ?? $attrs['notags'] ?? $attrs['no_tags'] ?? ''));
        if ($tagsRaw !== '') {
            foreach ($this->tokens($tagsRaw) as $token) {
                $this->pushSlug($slugs, $this->resolveLegacyToken($token));
            }
        }

        return array_values(array_unique(array_filter($slugs)));
    }

    /**
     * @param array<string, mixed> $pageVars
     */
    private function slugFromPageContext(array $pageVars): string
    {
        $slug = trim((string) ($pageVars['tag_slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        $tagId = (int) ($pageVars['tag_id'] ?? 0);

        return $tagId > 0 ? $this->slugFromId($tagId) : '';
    }

    private function slugFromId(int $id): string
    {
        if ($id < 1) {
            return '';
        }
        $row = app(TagService::class)->findRowById($id);

        return $row !== null ? trim((string) ($row['slug'] ?? '')) : '';
    }

    private function slugFromName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $row = app(TagService::class)->findRowByName($name);

        return $row !== null ? trim((string) ($row['slug'] ?? '')) : '';
    }

    private function slugFromUrlPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $row = app(TagService::class)->findRowByUrlPath($path) ?? app(TagService::class)->findRowBySlug(ltrim($path, '/'));

        return $row !== null ? trim((string) ($row['slug'] ?? '')) : '';
    }

    private function navIdFromNavTarget(string $target): int
    {
        $target = trim($target);
        if ($target === '') {
            return 0;
        }
        $rows = DbRead::model(SiteNav::class)->where('target', $target)->where('status', 1)
            ->order('sort', 'asc')->order('id', 'asc')->limit(8)->select()->toArray();
        foreach ($rows as $row) {
            $nid = (int) ($row['id'] ?? 0);
            if ($nid > 0 && app(SiteNavService::class)->isContentCategoryId($nid)) {
                return $nid;
            }
        }

        return 0;
    }

    private function navIdFromNavUrl(string $url): int
    {
        $url = trim($url);
        if ($url === '') {
            return 0;
        }
        $needle = $this->normalizeNavUrl($url);
        foreach (app(SiteNavService::class)->listPublicTree() as $item) {
            $nid = $this->matchNavTreeUrlToId($item, $needle);
            if ($nid > 0) {
                return $nid;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function matchNavTreeUrlToId(array $item, string $needle): int
    {
        $url = $this->normalizeNavUrl((string) ($item['url'] ?? ''));
        if ($url !== '' && ($url === $needle || str_starts_with($needle, rtrim($url, '/') . '/'))) {
            $nid = (int) ($item['id'] ?? 0);
            if ($nid > 0 && app(SiteNavService::class)->isContentCategoryId($nid)) {
                return $nid;
            }
        }

        foreach ((array) ($item['children'] ?? []) as $child) {
            if (!is_array($child)) {
                continue;
            }
            $nid = $this->matchNavTreeUrlToId($child, $needle);
            if ($nid > 0) {
                return $nid;
            }
        }

        return 0;
    }

    private function normalizeNavUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return '';
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $url = $path;
        }

        return app(BreadcrumbService::class)->normalizeUrl('/' . ltrim($url, '/'));
    }

    private function resolveLegacyToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }
        if (preg_match('/^\d+$/', $token)) {
            return $this->slugFromId((int) $token);
        }
        if (str_starts_with($token, '/') || str_contains($token, '/')) {
            $byUrl = $this->slugFromUrlPath($token);
            if ($byUrl !== '') {
                return $byUrl;
            }
        }
        $row = app(TagService::class)->findRowBySlug($token);
        if ($row !== null) {
            return trim((string) ($row['slug'] ?? ''));
        }

        return $this->slugFromName($token);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $raw) ?: [])));
    }

    /**
     * @param list<string> $slugs
     */
    private function pushSlug(array &$slugs, string $slug): void
    {
        if ($slug !== '') {
            $slugs[] = $slug;
        }
    }

    private function idFromSlug(string $slug): int
    {
        $slug = trim($slug);
        if ($slug === '') {
            return 0;
        }
        $row = app(TagService::class)->findRowBySlug($slug);

        return $row !== null ? (int) ($row['id'] ?? 0) : 0;
    }

    /**
     * @param list<int> $ids
     */
    private function pushTagId(array &$ids, int $id): void
    {
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    /**
     * @return list<int>
     */
    private function parseIdList(mixed $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach ($this->tokens($raw) as $token) {
            if (preg_match('/^\d+$/', $token)) {
                $out[] = (int) $token;
            }
        }

        return array_values(array_unique(array_filter($out)));
    }
}
