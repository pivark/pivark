<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\service\front\FrontAuthService;
use app\common\service\member\MemberLevelService;
use app\common\service\search\SearchConfigService;

/** 前台搜索：会员可见性 + 屏蔽 TAG（供 SQL / Meili 共用） */
final class SearchMemberContext
{

    public function __construct(
        private readonly FrontAuthService $frontAuth,
        private readonly MemberLevelService $memberLevels,
        private readonly SearchConfigService $searchConfig,
    ) {
    }

    /**
     * @return array{
     *   is_guest:bool,
     *   member:array<string,mixed>|null,
     *   member_level_id:int,
     *   member_rank:int,
     *   readable_level_ids:list<int>
     * }
     */
    public function forPublicSearch(): array
    {
        $member = $this->frontAuth->current();
        $isGuest = $member === null || empty($member['id']);
        $levelId = $isGuest ? 0 : (int) ($member['member_level_id'] ?? 0);
        if (!$isGuest && $levelId < 1) {
            $levelId = $this->memberLevels->defaultLevelId();
        }
        $rank = $isGuest ? 0 : $this->memberLevels->getRank($levelId);
        $readable = [];
        if (!$isGuest) {
            foreach ($this->memberLevels->listActive() as $row) {
                $lid = (int) ($row['id'] ?? 0);
                if ($lid > 0 && $this->memberLevels->getRank($lid) <= $rank) {
                    $readable[] = $lid;
                }
            }
        }

        return [
            'is_guest'             => $isGuest,
            'member'               => $isGuest ? null : $member,
            'member_level_id'      => $levelId,
            'member_rank'          => $rank,
            'readable_level_ids'   => array_values(array_unique($readable)),
            'blocked_tag_ids'      => $this->searchConfig->blockedTagIds(),
        ];
    }

    /**
     * Meilisearch filter 表达式（与 status=1 组合使用）。
     *
     * @param array<string, mixed> $context
     */
    public function meiliFilter(array $context): string
    {
        $parts = ['status = 1'];
        $parts[] = $this->meiliReadPermClause($context);
        $blocked = $context['blocked_tag_ids'] ?? $this->searchConfig->blockedTagIds();
        if (is_array($blocked) && $blocked !== []) {
            $ids = array_values(array_filter(array_map('intval', $blocked), static fn (int $id): bool => $id > 0));
            if ($ids !== []) {
                $parts[] = 'tag_ids NOT IN [' . implode(', ', $ids) . ']';
            }
        }

        return implode(' AND ', $parts);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function meiliReadPermClause(array $context): string
    {
        if (!empty($context['is_guest'])) {
            return 'read_perm = 0';
        }
        $readable = $context['readable_level_ids'] ?? [];
        if (!is_array($readable)) {
            $readable = [];
        }
        $readable = array_values(array_filter(array_map('intval', $readable), static fn (int $id): bool => $id > 0));
        $levelIn = $readable !== [] ? 'read_level_id IN [' . implode(', ', $readable) . ']' : 'read_level_id = 0';

        return '(read_perm = 0 OR (read_perm = 1 AND read_level_id = 0) OR (read_perm = 1 AND ' . $levelIn . '))';
    }

    /**
     * SQL：限制前台可见文档（阅读权限）。
     *
     * @param \think\db\Query $query
     * @param array<string, mixed> $context
     */
    public function applyReadPermToQuery($query, array $context): void
    {
        if (!empty($context['is_guest'])) {
            $query->where('read_perm', 0);

            return;
        }
        $readable = $context['readable_level_ids'] ?? [];
        if (!is_array($readable)) {
            $readable = [];
        }
        $readable = array_values(array_filter(array_map('intval', $readable), static fn (int $id): bool => $id > 0));
        $query->where(function ($sub) use ($readable): void {
            $sub->where('read_perm', 0)
                ->whereOr(function ($inner): void {
                    $inner->where('read_perm', 1)->where('read_level_id', 0);
                });
            if ($readable !== []) {
                $sub->whereOr(function ($inner) use ($readable): void {
                    $inner->where('read_perm', 1)->whereIn('read_level_id', $readable);
                });
            }
        });
    }

    /**
     * @param \think\db\Query $query
     * @param array<string, mixed> $context
     */
    public function applyBlockedTagsToQuery(\think\db\Query $query, array $context): void
    {
        $blocked = $context['blocked_tag_ids'] ?? $this->searchConfig->blockedTagIds();
        if (!is_array($blocked) || $blocked === []) {
            return;
        }
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $blocked), static fn (int $id): bool => $id > 0)));
        if ($tagIds === []) {
            return;
        }
        // 子查询，禁止 column()+whereNotIn 万级 ID
        $query->whereNotIn('id', static function ($sub) use ($tagIds): void {
            $sub->name('document_tags')->whereIn('tag_id', $tagIds)->field('document_id');
        });
    }
}
