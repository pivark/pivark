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

/** 会员角色校验（轻量，避免 adjust/balance 热路径拉 MemberService→Register→Config hub） */
final class MemberRoleCheckService
{

    public function hasMemberRole(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $roleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($roleId < 1) {
            return false;
        }

        return in_array($roleId, UserRole::getRoleIdsByUserId($userId), true);
    }

    public function isMemberAccount(int $userId): bool
    {
        if (!$this->hasMemberRole($userId)) {
            return false;
        }

        return User::where('id', $userId)->find() !== null;
    }
}
