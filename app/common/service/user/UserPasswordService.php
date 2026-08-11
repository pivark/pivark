<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\user;

use app\common\support\AppTime;

use app\common\support\ServiceResult;

use app\common\service\audit\AuditLogService;
use app\common\model\User;
use think\facade\Session;

/** 用户密码策略：首登改密、自助改密 */
class UserPasswordService
{

    public function __construct(
        private readonly AuditLogService $auditLog,
    ) {
    }

    public function mustChange(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $row = User::where('id', $userId)->field('must_change_password')->find();

        return $row !== null && (int) ($row['must_change_password'] ?? 0) === 1;
    }

    /** 刷新 Session 中 must_change_password 标记 */
    public function syncSessionFlag(int $userId): void
    {
        $admin = Session::get('admin_user');
        if (!is_array($admin) || (int) ($admin['id'] ?? 0) !== $userId) {
            return;
        }
        $admin['must_change_password'] = $this->mustChange($userId) ? 1 : 0;
        Session::set('admin_user', $admin);
    }

    /**
     * @return ServiceResult
     */
    public function changeOwn(int $userId, string $oldPassword, string $newPassword): ServiceResult
    {
        if ($userId < 1) {
            return ServiceResult::fail('请先登录');
        }
        $user = User::find($userId);
        if (!$user) {
            return ServiceResult::fail('用户不存在');
        }

        $mustChange = (int) ($user['must_change_password'] ?? 0) === 1;
        if (!$mustChange) {
            if ($oldPassword === '' || !password_verify($oldPassword, (string) $user->password)) {
                return ServiceResult::fail('原密码不正确');
            }
        }

        $err = $this->validateNewPassword($newPassword, (string) $user->username);
        if ($err !== '') {
            return ServiceResult::fail($err);
        }

        User::where('id', $userId)->update([
            'password'              => password_hash($newPassword, PASSWORD_BCRYPT),
            'must_change_password'  => 0,
            'updated_at'            => AppTime::now(),
        ]);
        // 改密不轮换 Session ID：避免 SPA 随后请求丢 Cookie/CSRF；登录时已 regenerate
        $this->syncSessionFlag($userId);

        $this->auditLog->operate('修改登录密码', 'admin.profile', ['user_id' => $userId]);

        return ServiceResult::ok(null, '密码已更新');
    }

    /** 新密码强度校验；通过返回空字符串 */
    public function validateNewPassword(string $password, string $username = ''): string
    {
        if (strlen($password) < 8) {
            return '新密码至少 8 位';
        }
        if (strlen($password) > 128) {
            return '新密码过长';
        }
        if ($username !== '' && strcasecmp($password, $username) === 0) {
            return '新密码不能与用户名相同';
        }
        if (in_array(strtolower($password), ['admin', 'password', '12345678', 'admin123'], true)) {
            return '新密码过于简单，请使用更复杂的密码';
        }

        return '';
    }
}
