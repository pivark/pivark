<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\tag;
use app\common\service\tag\TagCore;

use app\common\model\Tag;
use app\common\service\infra\MetaSqlCacheService;
use app\common\service\infra\UrlPathService;

final class TagSlugIndexService
{

    public function __construct(
        private readonly MetaSqlCacheService $metaSqlCacheService,
        private readonly TagCore $tagCore,
        private readonly UrlPathService $urlPathService,
    ) {
    }

    private const META_TAG = 'tag_public_index_v2';

    /** @var array<string, int>|null */
    private static ?array $slugIdMap = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $slugRowMap = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $urlPathRowMap = null;

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $idRowMap = null;

    private function ensureLoaded(): void
    {
        if (self::$slugIdMap !== null) {
            return;
        }

        /** @var array{slug_ids: array<string, int>, slug_rows: array<string, array<string, mixed>>, path_rows: array<string, array<string, mixed>>, id_rows: array<int, array<string, mixed>>} $data */
        $data = $this->metaSqlCacheService->remember(
            self::META_TAG,
            function (): array {
                $slugIds   = [];
                $slugRows  = [];
                $pathRows  = [];
                $idRows    = [];
                $query     = Tag::where('status', 1);
                $this->tagCore->applySiteDomainGroupScope($query);
                foreach ($query->select()->toArray() as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id > 0) {
                        $idRows[$id] = $row;
                    }
                    $slug = trim((string) ($row['slug'] ?? ''));
                    if ($slug !== '') {
                        $slugIds[$slug]  = $id;
                        $slugRows[$slug] = $row;
                    }
                    $path = trim((string) ($row['url_path'] ?? ''));
                    if ($path !== '') {
                        $pathRows[$path] = $row;
                    }
                }

                return [
                    'slug_ids'   => $slugIds,
                    'slug_rows'  => $slugRows,
                    'path_rows'  => $pathRows,
                    'id_rows'    => $idRows,
                ];
            }
        );

        // 缓存反序列化会把纯数字 slug key 变成 int，破坏 rowBySlug(string)
        self::$slugIdMap     = $this->normalizeSlugKeyedInts(is_array($data['slug_ids'] ?? null) ? $data['slug_ids'] : []);
        self::$slugRowMap    = $this->normalizeSlugKeyedRows(is_array($data['slug_rows'] ?? null) ? $data['slug_rows'] : []);
        self::$urlPathRowMap = $this->normalizeSlugKeyedRows(is_array($data['path_rows'] ?? null) ? $data['path_rows'] : []);
        self::$idRowMap      = [];
        foreach (is_array($data['id_rows'] ?? null) ? $data['id_rows'] : [] as $id => $row) {
            $id = (int) $id;
            if ($id > 0 && is_array($row)) {
                self::$idRowMap[$id] = $row;
            }
        }
        if (self::$idRowMap === [] && self::$slugRowMap !== []) {
            foreach (self::$slugRowMap as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    self::$idRowMap[$id] = $row;
                }
            }
        }
    }

    /**
     * @param array<array-key, mixed> $map
     * @return array<string, int>
     */
    private function normalizeSlugKeyedInts(array $map): array
    {
        $out = [];
        foreach ($map as $slug => $id) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }
            $out[$slug] = (int) $id;
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $map
     * @return array<string, array<string, mixed>>
     */
    private function normalizeSlugKeyedRows(array $map): array
    {
        $out = [];
        foreach ($map as $slug => $row) {
            $slug = trim((string) $slug);
            if ($slug === '' || !is_array($row)) {
                continue;
            }
            $out[$slug] = $row;
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function rowsByIds(array $ids): array
    {
        $this->ensureLoaded();
        $out = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $ids)))) as $id) {
            if (isset(self::$idRowMap[$id])) {
                $out[$id] = self::$idRowMap[$id];
            }
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    public function slugIdMap(): array
    {
        $this->ensureLoaded();

        return self::$slugIdMap ?? [];
    }

    /**
     * @param list<string> $slugs
     * @return list<int>
     */
    public function idsForSlugs(array $slugs): array
    {
        $slugs = array_values(array_filter(array_map(
            static fn (string $s): string => trim($s),
            $slugs
        )));
        if ($slugs === []) {
            return [];
        }

        $map = $this->slugIdMap();
        $ids = [];
        foreach ($slugs as $slug) {
            if (!isset($map[$slug])) {
                return [];
            }
            $ids[] = $map[$slug];
        }

        return array_values(array_unique($ids));
    }

    public function rowBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $this->ensureLoaded();

        return self::$slugRowMap[$slug] ?? null;
    }

    public function rowByUrlPath(string $path): ?array
    {
        $path = $this->urlPathService->normalize($path);
        if ($path === '' || $this->urlPathService->isReserved($path)) {
            return null;
        }
        $this->ensureLoaded();

        // 仅 url_path 索引；禁 slug 冒充门牌
        return self::$urlPathRowMap[$path] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rowsByParentId(int $parentId): array
    {
        if ($parentId < 1) {
            return [];
        }
        $this->ensureLoaded();
        $out = [];
        foreach (self::$idRowMap ?? [] as $row) {
            if ((int) ($row['parent_id'] ?? 0) === $parentId) {
                $out[] = $row;
            }
        }

        usort($out, static function (array $a, array $b): int {
            $cmp = ((int) ($a['nav_sort'] ?? 0)) <=> ((int) ($b['nav_sort'] ?? 0));
            return $cmp !== 0 ? $cmp : ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $out;
    }

    public function forgetCaches(): void
    {
        self::$slugIdMap     = null;
        self::$slugRowMap    = null;
        self::$urlPathRowMap = null;
        self::$idRowMap      = null;
        $this->metaSqlCacheService->forget(self::META_TAG);
        $this->metaSqlCacheService->forget('tag_slug_id_map');
    }
}
