<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\model\Role;
use app\common\model\User;
use app\common\model\UserRole;
use app\common\support\CursorPaginator;
use app\common\support\DbRead;
use app\common\support\ModelRelationLoad;

/**
 * 会员开放 API 可注入门面（Phase 2 DI：v1 Member）。
 */
final class MemberPublicGateway
{

    public function __construct(
        private readonly MemberService $members,
        private readonly MemberMpWechatAuthService $mpWechatAuth,
    ) {
    }

    public function enabled(): bool
    {
        return $this->members->publicApiEnabled();
    }

    /**
     * @return array{
     *     list:list<array<string,mixed>>,
     *     total:int,
     *     page:int,
     *     limit:int,
     *     next_cursor_id?:int
     * }
     */
    public function listPublic(int $page, int $limit, int $levelId, int $cursorId): array
    {
        $page     = max(1, $page);
        $limit    = min(max($limit, 1), 50);
        $levelId  = max(0, $levelId);
        $cursorId = max(0, $cursorId);
        $roleId   = DbRead::model(Role::class)->where('code', MemberService::ROLE_CODE)->where('status', 1)->value('id');
        $roleId   = (int) ($roleId ?? 0);
        if ($roleId < 1) {
            return ['list' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }

        $userIds = UserRole::where('role_id', $roleId)->column('user_id');
        if ($userIds === []) {
            return ['list' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }

        $query = DbRead::model(User::class)
            ->with(['memberLevel' => static function ($levelQuery): void {
                $levelQuery->field('id,name');
            }])
            ->whereIn('id', $userIds)
            ->where('status', 1)
            ->field('id,username,nickname,avatar,member_level_id,member_points,member_growth');
        if ($levelId > 0) {
            $query->where('member_level_id', $levelId);
        }
        $allowCursor = $levelId < 1;
        $pageResult  = CursorPaginator::paginateById($query, $limit, $page, $cursorId, 'id', $allowCursor, 'id');
        $list        = [];
        foreach ($pageResult['rows'] as $row) {
            $list[] = $this->formatPublicRow(ModelRelationLoad::mergeBelongsTo($row, 'memberLevel', ['name' => 'member_level_name']));
        }

        $out = [
            'list'  => $list,
            'total' => (int) $pageResult['total'],
            'page'  => $page,
            'limit' => $limit,
        ];
        if ($pageResult['next_cursor_id'] > 0 && $allowCursor) {
            $out['next_cursor_id'] = (int) $pageResult['next_cursor_id'];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function readPublic(int $id): ?array
    {
        if ($id < 1 || !$this->members->hasMemberRole($id)) {
            return null;
        }
        $row = DbRead::model(User::class)
            ->with(['memberLevel' => static function ($levelQuery): void {
                $levelQuery->field('id,name');
            }])
            ->where('id', $id)
            ->where('status', 1)
            ->field('id,username,nickname,avatar,member_level_id,member_points,member_growth,account_kind')
            ->find();
        if (!$row) {
            return null;
        }

        return $this->formatPublicRow(ModelRelationLoad::mergeBelongsTo($row, 'memberLevel', ['name' => 'member_level_name']));
    }

    /** @return array<string, mixed> */
    public function profile(int $userId): array
    {
        return $this->mpWechatAuth->publicMember($userId);
    }

    /** @param array<string, mixed> $arr
     *  @return array<string, mixed>
     */
    private function formatPublicRow(array $arr): array
    {
        $kind = MemberAccountKind::normalize($arr['account_kind'] ?? MemberAccountKind::PERSONAL);

        return [
            'id'                => (int) ($arr['id'] ?? 0),
            'nickname'          => (string) ($arr['nickname'] ?? ''),
            'avatar'            => (string) ($arr['avatar'] ?? ''),
            'member_level_id'   => (int) ($arr['member_level_id'] ?? 0),
            'member_level_name' => (string) ($arr['member_level_name'] ?? ''),
            'member_points'     => (int) ($arr['member_points'] ?? 0),
            'member_growth'     => (int) ($arr['member_growth'] ?? 0),
            'account_kind'      => $kind,
            'account_kind_label'=> MemberAccountKind::label($kind),
        ];
    }
}
