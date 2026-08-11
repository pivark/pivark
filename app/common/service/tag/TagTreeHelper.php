<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\tag;

use app\common\model\TagGroup as TagGroupModel;
use think\facade\Db;
use app\common\model\Document;
use app\common\model\Tag;
use app\common\model\DocumentTag;
use app\common\support\DbTable;
use app\common\support\QueryLimit;
use app\common\support\SiteDomainContext;
use app\common\support\SiteUrl;
use think\db\Query;

/** 标签（TAG）实现 */

/** 标签树与导航 */
class TagTreeHelper
{

    /** @var list<array<string, mixed>>|null 请求/worker 级 id+parent_id 缓存，避免多次全表扫描 */
    private static ?array $idParentRowsCache = null;

    /** @return list<array<string, mixed>> */
    private function idParentRows(): array
    {
        if (self::$idParentRowsCache !== null) {
            return self::$idParentRowsCache;
        }
        self::$idParentRowsCache = Tag::field('id,parent_id')
            ->order('id', 'asc')
            ->limit(QueryLimit::TAG_TREE_ROWS)
            ->select()
            ->toArray();

        return self::$idParentRowsCache;
    }

public function buildTagForest(array $tagRows, int $parentId = 0): array
    {
        $forest = [];
        foreach ($tagRows as $row) {
            if ((int) ($row['parent_id'] ?? 0) !== $parentId) {
                continue;
            }
            $node = $this->mapRowToNavTag($row);
            $children = $this->buildTagForest($tagRows, (int) ($row['id'] ?? 0));
            if ($children !== []) {
                $node['children'] = $children;
            }
            $forest[] = $node;
        }
        usort(
            $forest,
            static fn (array $a, array $b): int => ($a['nav_sort'] <=> $b['nav_sort']) ?: ($a['id'] <=> $b['id'])
        );

        return $forest;
    }

public function flattenNavTree(array $nodes, int $depth = 0): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];
            $item = $node;
            unset($item['children']);
            $prefix = $depth > 0 ? str_repeat('　', $depth) . '└ ' : '';
            $item['depth'] = $depth;
            $item['name_display'] = $prefix . (string) ($item['name'] ?? '');
            $out[] = $item;
            if ($children !== []) {
                $out = array_merge($out, $this->flattenNavTree($children, $depth + 1));
            }
        }

        return $out;
    }

public function listParentOptions(int $excludeId = 0, int $groupId = 0): array
    {
        $query = Tag::where('kind', TagCore::KIND_TOPIC)->order('nav_sort', 'asc')->order('id', 'asc');
        if ($groupId > 0) {
            $query->where('group_id', $groupId);
        }
        $rows = $query->select()->toArray();
        $exclude = $excludeId > 0
            ? array_fill_keys(array_merge([$excludeId], $this->collectDescendantIds($excludeId)), true)
            : [];

        $options = [['id' => 0, 'label' => '（顶级标签）']];
        $this->appendParentOptions($options, $this->buildTagForest($rows), 0, $exclude);

        return $options;
    }

private function appendParentOptions(array &$options, array $forest, int $depth, array $exclude): void
    {
        foreach ($forest as $node) {
            $id = (int) ($node['id'] ?? 0);
            if ($id < 1 || isset($exclude[$id])) {
                continue;
            }
            $prefix = $depth > 0 ? str_repeat('　', $depth) . '└ ' : '';
            $options[] = [
                'id'    => $id,
                'label' => $prefix . (string) ($node['name'] ?? ''),
            ];
            if (!empty($node['children']) && is_array($node['children'])) {
                $this->appendParentOptions($options, $node['children'], $depth + 1, $exclude);
            }
        }
    }

public function collectDescendantIds(int $tagId): array
    {
        if ($tagId < 1) {
            return [];
        }
        $rows = $this->idParentRows();
        $out  = [];
        $this->collectDescendantIdsFromRows($rows, $tagId, $out);

        return $out;
    }

private function collectDescendantIdsFromRows(array $rows, int $parentId, array &$out): void
    {
        foreach ($rows as $row) {
            if ((int) ($row['parent_id'] ?? 0) === $parentId) {
                $cid = (int) ($row['id'] ?? 0);
                if ($cid > 0) {
                    $out[] = $cid;
                    $this->collectDescendantIdsFromRows($rows, $cid, $out);
                }
            }
        }
    }

public function validateParentAssignment(int $tagId, int $parentId, int $groupId, string $kind): string
    {
        $parentId = max(0, $parentId);
        if ($parentId === 0) {
            return '';
        }
        if ($tagId > 0 && $parentId === $tagId) {
            return '上级栏目不能选择自己';
        }
        $parent = Tag::where('id', $parentId)->find()?->toArray();
        if (!$parent) {
            return '上级栏目不存在';
        }
        if (app(TagCore::class)->normalizeKind($parent['kind'] ?? TagCore::KIND_LABEL) !== TagCore::KIND_TOPIC) {
            return '上级须为主题标签（栏目），标注标签不能作为父级';
        }
        if ($groupId > 0 && (int) ($parent['group_id'] ?? 0) !== $groupId) {
            return '上级栏目须与当前标签在同一分组';
        }
        if ($tagId > 0) {
            $desc = $this->collectDescendantIds($tagId);
            if (in_array($parentId, $desc, true)) {
                return '上级栏目不能是当前栏目的子级';
            }
            $depth = $this->parentDepth($parentId);
            if ($depth >= TagCore::MAX_PARENT_DEPTH) {
                return '栏目层级过深，请减少上级层级';
            }
        }

        return '';
    }

public function parentDepth(int $tagId): int
    {
        $depth = 0;
        $seen  = [];
        $cur   = $tagId;
        while ($cur > 0 && !isset($seen[$cur])) {
            $seen[$cur] = true;
            $row = Tag::where('id', $cur)->field('id,parent_id')->find()?->toArray();
            if (!$row) {
                break;
            }
            ++$depth;
            $cur = (int) ($row['parent_id'] ?? 0);
        }

        return $depth;
    }

private function mapRowToNavTag(array $row): array
    {
        $slug = (string) ($row['slug'] ?? '');

        return [
            'id'        => (int) ($row['id'] ?? 0),
            'name'      => (string) ($row['name'] ?? ''),
            'slug'      => $slug,
            'url'       => SiteUrl::tagFromRow($row),
            'nav_sort'  => (int) ($row['nav_sort'] ?? 0),
            'parent_id' => (int) ($row['parent_id'] ?? 0),
        ];
    }

public function listNavGrouped(): array
    {
        $boundGroupId = SiteDomainContext::tagGroupId();
        $cacheKey     = 'tag_nav_grouped_g' . $boundGroupId;

        // 标签分组导航树已退役；栏目请用 site_nav / {pv:nav}
        unset($cacheKey, $boundGroupId);

        return [];
    }

public function buildNavGroupTree(array $tags, array $groups): array
    {
        $groupMap = [];
        foreach ($groups as $g) {
            $gid = (int) ($g['id'] ?? 0);
            if ($gid < 1) {
                continue;
            }
            $groupMap[$gid] = [
                'id'   => $gid,
                'name' => (string) ($g['name'] ?? ''),
                'sort' => (int) ($g['sort'] ?? 0),
                'tags' => [],
            ];
        }

        $ungrouped = [
            'id'   => 0,
            'name' => '更多',
            'sort' => 9999,
            'tags' => [],
        ];

        $byGroup = [];
        foreach ($tags as $row) {
            $gid = (int) ($row['group_id'] ?? 0);
            $key = $gid > 0 && isset($groupMap[$gid]) ? (string) $gid : '0';
            $byGroup[$key][] = $row;
        }

        foreach ($byGroup as $key => $rows) {
            $forest = $this->buildTagForest($rows);
            if ((string) $key === '0') {
                $ungrouped['tags'] = $forest;
                continue;
            }
            $gid = (int) $key;
            if ($gid > 0 && isset($groupMap[$gid])) {
                $groupMap[$gid]['tags'] = $forest;
            } else {
                $ungrouped['tags'] = array_merge($ungrouped['tags'], $forest);
            }
        }

        $out = array_values($groupMap);
        usort($out, static fn (array $a, array $b): int => (int) ($a['sort'] ?? 0) <=> (int) ($b['sort'] ?? 0));
        $out = array_values(array_filter($out, static fn (array $g): bool => $g['tags'] !== []));

        if ($ungrouped['tags'] !== []) {
            $out[] = $ungrouped;
        }

        return $out;
    }

    /** @var list<array<string, mixed>>|null */
    private static ?array $activeTagsCache = null;

public function listAllActive(): array
    {
        if (self::$activeTagsCache !== null) {
            return self::$activeTagsCache;
        }
        self::$activeTagsCache = Tag::where('status', 1)
            ->order('use_count', 'desc')
            ->limit(QueryLimit::TAG_TREE_ROWS)
            ->select()
            ->toArray();

        return self::$activeTagsCache;
    }

public function buildAdminParentPath(int $tagId, ?array $index = null): string
    {
        if ($tagId < 1) {
            return '';
        }
        $index ??= $this->adminTagIndex();
        if (!isset($index[$tagId])) {
            return '';
        }
        $parts = [];
        $cur   = (int) ($index[$tagId]['parent_id'] ?? 0);
        $guard = 0;
        while ($cur > 0 && isset($index[$cur]) && $guard < TagCore::MAX_PARENT_DEPTH) {
            array_unshift($parts, (string) ($index[$cur]['name'] ?? ''));
            $cur = (int) ($index[$cur]['parent_id'] ?? 0);
            ++$guard;
        }

        return implode(' › ', array_filter($parts, static fn (string $s): bool => $s !== ''));
    }

public function adminTagDepth(int $tagId, ?array $index = null): int
    {
        if ($tagId < 1) {
            return 0;
        }
        $index ??= $this->adminTagIndex();
        $depth = 0;
        $cur   = (int) ($index[$tagId]['parent_id'] ?? 0);
        $guard = 0;
        while ($cur > 0 && isset($index[$cur]) && $guard < TagCore::MAX_PARENT_DEPTH) {
            ++$depth;
            $cur = (int) ($index[$cur]['parent_id'] ?? 0);
            ++$guard;
        }

        return $depth;
    }

private function adminTagIndex(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        foreach (Tag::field('id,name,parent_id')
            ->order('id', 'asc')
            ->limit(QueryLimit::TAG_TREE_ROWS)
            ->select()
            ->toArray() as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $cache[$id] = [
                    'id'        => $id,
                    'name'      => (string) ($row['name'] ?? ''),
                    'parent_id' => (int) ($row['parent_id'] ?? 0),
                ];
            }
        }

        return $cache;
    }
}
