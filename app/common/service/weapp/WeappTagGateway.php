<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappTagGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\model\Tag;
use app\common\service\tag\TagSlugIndexService;
use app\common\service\tag\TagGroupService;
use app\common\service\tag\TagAdminService;
use app\common\service\tag\TagCore;
use app\common\service\tag\TagNavImportService;
use app\common\service\tag\TagService;

final class WeappTagGateway
{

    public function __construct(
        private readonly TagAdminService $tagAdmin,
        private readonly TagNavImportService $tagNavImport,
        private readonly TagGroupService $tagGroup,
        private readonly TagService $tag,
        private readonly TagSlugIndexService $tagSlugIndex,
    ) {
    }

    public const TAG_KIND_TOPIC = TagCore::KIND_TOPIC;

    /** @param array<string, mixed> $data */
    public function tagSaveAdmin(array $data): \app\common\support\ServiceResult
    {
        return $this->tagAdmin->saveAdmin($data);
    }

    /**
     * 迁移专用：Tag 树一次性写入 site_nav 真分类（后台导入已关闭）。
     *
     * @param array<string, mixed> $options
     */
    public function tagNavImportExecute(array $options = []): \app\common\support\ServiceResult
    {
        $options['migrate'] = 1;

        return $this->tagNavImport->execute($options);
    }

    /** @return list<array<string, mixed>> */
    public function tagGroupListActive(): array
    {
        return $this->tagGroup->listActive();
    }

    /** @return list<array{id:int,name:string}> */
    public function tagGroupListForSelect(): array
    {
        return $this->tagGroup->listForSelect();
    }

    /** 按名称找启用分组 id（0=无） */
    public function tagGroupIdByName(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        foreach ($this->tagGroup->listActive() as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['name'] ?? '')) === $name) {
                return (int) ($row['id'] ?? 0);
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $data */
    public function tagGroupSaveAdmin(array $data): \app\common\support\ServiceResult
    {
        return $this->tagGroup->saveAdmin($data);
    }

    /** @return list<array<string, mixed>> */
    public function tagListAllActive(): array
    {
        return $this->tag->listAllActive();
    }

    /** @param list<int> $documentIds @return array<int, list<array<string, mixed>>> */
    public function tagGetForDocuments(array $documentIds): array
    {
        return $this->tag->getTagsForDocuments($documentIds);
    }

    /** @return list<array<string, mixed>> */
    public function tagGetForDocument(int $documentId): array
    {
        return $this->tag->getTagsForDocument($documentId);
    }

    /** @param array<string, mixed> $detail @return array<string, mixed>|null */
    public function tagPrimaryFromDocument(array $detail): ?array
    {
        return $this->tag->primaryTagFromDocument($detail);
    }

    /** @return array<string, mixed>|null */
    public function tagFindBySlug(string $slug): ?array
    {
        return $this->tag->findBySlug($slug);
    }

    /** @return array<string, mixed>|null */
    public function tagFindById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $rows = $this->tagSlugIndex->rowsByIds([$id]);

        return $rows[0] ?? $this->tag->findRowById($id);
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    public function tagSlugIndexRowsByIds(array $ids): array
    {
        return $this->tagSlugIndex->rowsByIds($ids);
    }

    /** @return array<int, int> id => parent_id */
    public function tagParentIdIndex(): array
    {
        $index = [];
        foreach (Tag::field('id,parent_id')->select()->toArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index[(int) ($row['id'] ?? 0)] = (int) ($row['parent_id'] ?? 0);
        }

        return $index;
    }
}
