<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\tag;

use app\common\support\AppTime;

use app\common\model\TagGroup as TagGroupModel;
use think\facade\Db;
use app\common\model\Document;
use app\common\model\Tag;
use app\common\model\DocumentTag;
use app\common\support\DbTable;
use app\common\support\SiteDomainContext;
use app\common\support\SiteUrl;
use app\common\support\SlugHelper;
use think\db\Query;

/** 标签（TAG）实现 */

/** 标签核心：类型、Slug、CSV、格式化、域分组 */
class TagCore
{

    private const SLUG_MD5_LENGTH = 10;

    public const KIND_TOPIC = 'topic';
    public const KIND_LABEL = 'label';

    /** 栏目树最大层级（含顶级） */
    public const MAX_PARENT_DEPTH = 12;

    /**
     * @return array<string, string>
     */

public function kindLabels(): array
    {
        return [
            self::KIND_TOPIC => '主题标签',
            self::KIND_LABEL => '标注标签',
        ];
    }

/**
     * @return mixed
     * @param mixed $raw
     */
    public function normalizeKind(mixed $raw): string
    {
        $kind = strtolower(trim((string) $raw));

        return $kind === self::KIND_TOPIC ? self::KIND_TOPIC : self::KIND_LABEL;
    }

/**
     * @return list<string> 当前主题下可选标签列表页模板（列表 / 频道，不含内容页）
     */
    public function listTagTemplates(): array
    {
        return app(\app\common\service\theme\ThemeTemplateCatalogService::class)->listFiles(\app\common\service\theme\ThemeTemplateCatalogService::SCOPE_TAG);
    }

/**
     * @return mixed
     * @param mixed $tplFile
     */
    public function publicTemplateBasename(string $tplFile): string
    {
        $tplFile = basename(str_replace(['\\', "\0"], '', trim($tplFile)));
        if ($tplFile === '') {
            return \app\common\service\theme\ThemeTemplateCatalogService::TPL_LIST_DOCUMENT;
        }
        $bare = app(\app\common\service\theme\ThemeTemplateCatalogService::class)->toCanonicalBasename($tplFile);
        if ($bare !== '' && preg_match('/^list_(document|channel)/', $bare)) {
            return $bare;
        }
        if ($bare === 'list_document_tag') {
            return $bare;
        }

        return \app\common\service\theme\ThemeTemplateCatalogService::TPL_LIST_DOCUMENT;
    }

/**
     * @param string $name      标签名
     * @param int    $excludeId 排除 ID（唯一检查）
     * @return string URL slug
     */
    public function makeSlug(string $name, int $excludeId = 0): string
    {
        $base = SlugHelper::asciiFromText($name, 'tag', self::SLUG_MD5_LENGTH);

        return SlugHelper::ensureUnique(
            $base,
            fn (string $slug): bool => $this->slugExists($slug, $excludeId),
            100,
        );
    }

/**
     * @param string $slug      Slug
     * @param int    $excludeId 排除 ID
     * @return bool
     */
    public function slugExists(string $slug, int $excludeId = 0): bool
    {
        $q = Tag::where('slug', $slug);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }
        return $q->count() > 0;
    }

public function applySiteDomainGroupScope(Query $query, string $alias = ''): void
    {
        $groupId = SiteDomainContext::tagGroupId();
        if ($groupId < 1) {
            return;
        }
        $col = $alias !== '' ? $alias . '.group_id' : 'group_id';
        $query->where($col, $groupId);
    }

public function formatForApi(array $row): array
    {
        return [
            'id'        => (int) $row['id'],
            'name'      => $row['name'],
            'slug'      => $row['slug'] ?? '',
            'kind'      => $this->normalizeKind($row['kind'] ?? self::KIND_LABEL),
            'group_id'  => (int) ($row['group_id'] ?? 0),
            'use_count' => (int) ($row['use_count'] ?? 0),
            'url'       => SiteUrl::tagFromRow($row),
            'url_path'  => app(\app\common\service\tag\TagPublicService::class)->publicPath($row),
        ];
    }

public function upsertByName(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $tag = Tag::where('name', $name)->find()?->toArray();
        if ($tag) {
            Tag::where('id', $tag['id'])->inc('use_count')->update();
            return (int) $tag['id'];
        }
        $now = AppTime::now();
        $slug = $this->makeSlug($name);

        return (int) Tag::insertGetId([
            'name'       => $name,
            'slug'       => $slug,
            'url_path'   => $slug,
            'status'     => 1,
            'use_count'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

public function normalizeTagsCsv(string $tagsStr): string
    {
        if ($tagsStr === '') {
            return '';
        }
        $tagsStr = str_replace('，', ',', $tagsStr);
        $tagsStr = preg_replace('/\s*,\s*/', ',', $tagsStr) ?? $tagsStr;
        $tagsStr = preg_replace('/,+/', ',', $tagsStr) ?? $tagsStr;

        return trim($tagsStr, " \t\n\r\0\x0B,");
    }

public function parseTagNames(string $tagsStr): array
    {
        $tagsStr = $this->normalizeTagsCsv($tagsStr);
        if ($tagsStr === '') {
            return [];
        }
        $names = array_map('trim', explode(',', $tagsStr));

        return array_values(array_unique(array_filter(
            $names,
            fn (string $n): bool => $this->isValidTagName($n)
        )));
    }

public function isValidTagName(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            return false;
        }
        if (preg_match('#^https?://#i', $name)) {
            return false;
        }
        if (preg_match('#https?://#i', $name)) {
            return false;
        }

        return true;
    }

/**
     * @param array<string, mixed> $row tags 表行
     * @return array<string, mixed> 前台标签列表页变量
     */
    public function buildPublicListViewVars(array $row): array
    {
        $name = (string) ($row['name'] ?? '');
        $desc = trim((string) ($row['description'] ?? ''));
        $seoTitle = trim((string) ($row['seo_title'] ?? ''));
        $seoKeywords = trim((string) ($row['seo_keywords'] ?? ''));
        $seoDesc = trim((string) ($row['seo_description'] ?? ''));
        $pageTitle = $name !== '' ? $name : '标签';

        $canRead       = app(\app\common\service\front\FrontAuthService::class)->canReadTag($row);
        $readLevelId   = (int) ($row['read_level_id'] ?? 0);
        $loginRequired = (int) ($row['read_perm'] ?? 0) === 1 && !$canRead;

        $bannerImage = trim((string) ($row['list_top_image'] ?? $row['litpic'] ?? ''));
        $extra       = $this->normalizeExtraFields($row['extra_json'] ?? null);

        $vars = [
            'tag_id'                => (int) ($row['id'] ?? 0),
            'tag_kind'              => $this->normalizeKind($row['kind'] ?? self::KIND_LABEL),
            'tag_name'              => $name,
            'tag_slug'              => (string) ($row['slug'] ?? ''),
            'tag_url'               => SiteUrl::tagFromRow($row),
            'tag_description'       => $desc,
            'tag_litpic'            => (string) ($row['litpic'] ?? ''),
            'channel_banner_image'  => $bannerImage,
            'page_title'            => $pageTitle,
            'seo_title'             => $seoTitle !== '' ? $seoTitle : $pageTitle,
            'seo_keywords'          => $seoKeywords,
            'seo_description'       => $seoDesc !== '' ? $seoDesc : ($desc !== '' ? mb_substr(strip_tags($desc), 0, 160) : ''),
            'tag_login_required'    => $loginRequired,
            'tag_read_level_id'     => $readLevelId,
            'member_login_url'      => SiteUrl::memberLogin(SiteUrl::tagFromRow($row)),
            'tag_extra'             => $extra,
        ];
        foreach ($extra as $key => $value) {
            $vars['tag_extra_' . $key] = $value;
        }

        return $vars;
    }

    /**
     * @return array<string, array{type: string, value: string, scope: string, options: list<string>}>
     */
    public function normalizeExtraFieldDefs(mixed $raw): array
    {
        $decoded = $this->decodeExtraFieldRaw($raw);
        $out     = [];
        foreach ($decoded as $key => $value) {
            $name = trim((string) $key);
            if ($name === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/i', $name)) {
                continue;
            }
            $entry = $this->normalizeExtraFieldEntry($value);
            if ($entry === null) {
                continue;
            }
            $out[$name] = $entry;
        }

        return $out;
    }

    /**
     * 按作用范围筛字段定义（栏目 → 文档接收用）。
     *
     * @param array<string, array{type: string, value: string, scope: string, options: list<string>}> $defs
     * @param list<string> $scopes list|document|both
     * @return array<string, array{type: string, value: string, scope: string, options: list<string>}>
     */
    public function filterExtraFieldDefsByScope(array $defs, array $scopes): array
    {
        $allow = [];
        foreach ($scopes as $scope) {
            $scope = strtolower(trim((string) $scope));
            if (in_array($scope, ['list', 'document', 'both'], true)) {
                $allow[$scope] = true;
            }
        }
        if ($allow === []) {
            return [];
        }
        $out = [];
        foreach ($defs as $key => $def) {
            $scope = (string) ($def['scope'] ?? 'list');
            if (isset($allow[$scope])) {
                $out[$key] = $def;
            }
        }

        return $out;
    }

    /**
     * 文档可接收：scope=document|both
     *
     * @param array<string, array{type: string, value: string, scope: string, options: list<string>}> $defs
     * @return array<string, array{type: string, value: string, scope: string, options: list<string>}>
     */
    public function documentReceivableExtraFieldDefs(array $defs): array
    {
        return $this->filterExtraFieldDefsByScope($defs, ['document', 'both']);
    }

    /**
     * 按作用范围输出展示值（栏目列表页用 list|both）。
     *
     * @param list<string> $scopes
     * @return array<string, string>
     */
    public function normalizeExtraFieldsForScopes(mixed $raw, array $scopes): array
    {
        $defs = $this->filterExtraFieldDefsByScope($this->normalizeExtraFieldDefs($raw), $scopes);
        $out  = [];
        foreach ($defs as $key => $def) {
            $out[$key] = $this->formatExtraFieldDisplayValue($def);
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public function normalizeExtraFields(mixed $raw): array
    {
        $defs = $this->normalizeExtraFieldDefs($raw);
        $out  = [];
        foreach ($defs as $key => $def) {
            $out[$key] = $this->formatExtraFieldDisplayValue($def);
        }

        return $out;
    }

    /**
     * @param array{type: string, value: string, scope?: string, options?: list<string>} $def
     */
    public function formatExtraFieldDisplayValue(array $def): string
    {
        $type = (string) ($def['type'] ?? 'text');
        $raw  = trim((string) ($def['value'] ?? ''));
        if ($type === 'multi_select') {
            $parts = $this->decodeMultiSelectValue($raw);
            return implode('、', $parts);
        }

        return $raw;
    }

    /** @return string|null JSON 或 NULL（空对象不落库） */
    public function encodeExtraFields(mixed $raw): ?string
    {
        $defs = is_array($raw) ? $this->normalizeExtraFieldDefs($raw) : [];
        if ($defs === []) {
            return null;
        }
        $json = json_encode($defs, JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeExtraFieldRaw(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{type: string, value: string, scope: string, options: list<string>}|null
     */
    private function normalizeExtraFieldEntry(mixed $value): ?array
    {
        if (is_string($value)) {
            return [
                'type'    => 'text',
                'value'   => mb_substr(trim($value), 0, 500),
                'scope'   => 'list',
                'options' => [],
            ];
        }
        if (!is_array($value)) {
            return null;
        }
        $type = strtolower(trim((string) ($value['type'] ?? 'text')));
        if (!in_array($type, ['text', 'textarea', 'image', 'select', 'multi_select'], true)) {
            $type = 'text';
        }
        $scope = strtolower(trim((string) ($value['scope'] ?? 'list')));
        if (!in_array($scope, ['list', 'document', 'both'], true)) {
            $scope = 'list';
        }
        $options = $this->normalizeExtraFieldOptions($value['options'] ?? []);
        if (!in_array($type, ['select', 'multi_select'], true)) {
            $options = [];
        }

        $rawValue = $value['value'] ?? '';
        if ($type === 'multi_select') {
            $selected = $this->decodeMultiSelectValue($rawValue);
            if ($options !== []) {
                $selected = array_values(array_filter(
                    $selected,
                    static fn (string $item): bool => in_array($item, $options, true),
                ));
            }
            $encoded = $selected === [] ? '' : (string) json_encode($selected, JSON_UNESCAPED_UNICODE);
            $valueStr = mb_substr($encoded, 0, 2000);
        } else {
            $max = $type === 'textarea' ? 2000 : 500;
            $valueStr = mb_substr(trim((string) $rawValue), 0, $max);
            if ($type === 'select' && $options !== [] && $valueStr !== '' && !in_array($valueStr, $options, true)) {
                $valueStr = '';
            }
        }

        return [
            'type'    => $type,
            'value'   => $valueStr,
            'scope'   => $scope,
            'options' => $options,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeExtraFieldOptions(mixed $raw): array
    {
        if (is_string($raw)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            $raw = $lines;
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            $label = trim((string) $item);
            if ($label === '' || isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;
            $out[] = mb_substr($label, 0, 64);
            if (count($out) >= 64) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function decodeMultiSelectValue(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $str = trim((string) $raw);
            if ($str === '') {
                return [];
            }
            if (str_starts_with($str, '[')) {
                $decoded = json_decode($str, true);
                $parts = is_array($decoded) ? $decoded : [];
            } else {
                $parts = preg_split('/[,，、|]+/u', $str) ?: [];
            }
        }
        $out = [];
        $seen = [];
        foreach ($parts as $part) {
            $label = trim((string) $part);
            if ($label === '' || isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;
            $out[] = mb_substr($label, 0, 64);
        }

        return $out;
    }

    /**
     * 本 Tag + 启用子孙 ID（BFS；频道列表「含子栏目」用）。
     *
     * @param list<int> $rootIds
     * @return list<int>
     */
    public function idsWithDescendants(array $rootIds, int $maxDepth = 4): array
    {
        $byId = [];
        foreach ($rootIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $byId[$id] = true;
            }
        }
        if ($byId === []) {
            return [];
        }

        $frontier = array_keys($byId);
        $depth    = 0;
        $maxDepth = max(1, min($maxDepth, 8));
        while ($frontier !== [] && $depth < $maxDepth) {
            $query = Tag::where('status', 1)->whereIn('parent_id', $frontier);
            $this->applySiteDomainGroupScope($query);
            $children = array_map('intval', $query->column('id') ?: []);
            $next     = [];
            foreach ($children as $cid) {
                if ($cid < 1 || isset($byId[$cid])) {
                    continue;
                }
                $byId[$cid] = true;
                $next[]     = $cid;
            }
            $frontier = $next;
            $depth++;
        }

        return array_map('intval', array_keys($byId));
    }
}
