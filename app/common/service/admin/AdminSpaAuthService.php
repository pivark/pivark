<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\service\user\UserPasswordService;
use app\common\support\ServiceResult;

/** Vue 后台 SPA 账号/TOTP（从 Spa 控制器 batch 10 下沉） */
class AdminSpaAuthService
{

    /**
     * @param array<string, mixed> $admin
     * @return list<string>
     */
    public function permissionCodes(array $admin): array
    {
        $codes = $admin['permission_codes'] ?? [];
        if (!is_array($codes)) {
            $codes = [];
        }

        return array_values(array_unique(array_map('strval', $codes)));
    }

    public function changePassword(int $userId, string $oldPassword, string $newPassword): ServiceResult
    {
        $res = app(UserPasswordService::class)->changeOwn($userId, $oldPassword, $newPassword);
        if (!$res->isOk()) {
            return ServiceResult::fail((string) ($res->message() ?? '修改失败'));
        }

        return ServiceResult::ok(null, (string) ($res->message() ?? '密码已更新'));
    }

    /** @return array{enabled:bool,issuer:string} */
    public function totpStatus(int $userId): array
    {
        return app(AdminTotpService::class)->status($userId);
    }

    /** @return array{secret:string,otpauth_uri:string} */
    public function totpSetup(int $userId, string $username): array
    {
        return app(AdminTotpService::class)->beginSetup($userId, $username);
    }

    public function totpEnable(int $userId, string $code): ServiceResult
    {
        $res = app(AdminTotpService::class)->enable($userId, $code);
        if (!$res->isOk()) {
            return ServiceResult::fail((string) ($res->message() ?? '修改失败'));
        }

        return ServiceResult::ok(null, (string) ($res->message() ?? '两步验证已启用'));
    }

    public function totpDisable(int $userId, string $code): ServiceResult
    {
        $res = app(AdminTotpService::class)->disable($userId, $code);
        if (!$res->isOk()) {
            return ServiceResult::fail((string) ($res->message() ?? '修改失败'));
        }

        return ServiceResult::ok(null, (string) ($res->message() ?? '两步验证已关闭'));
    }
}
