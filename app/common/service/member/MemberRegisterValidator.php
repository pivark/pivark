<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\ServiceResult;

/** 会员注册输入校验（纯逻辑，可单测；MemberService 门面委托） */
final class MemberRegisterValidator
{

    /** 用户名格式（注册实时校验与 credentials 共用） */
    public function username(string $username): ServiceResult
    {
        $username = trim($username);
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
            return ServiceResult::fail('用户名须为 3~32 位字母数字下划线');
        }

        return ServiceResult::ok(['username' => $username]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function credentials(array $data): ServiceResult
    {
        $nameCheck = $this->username((string) ($data['username'] ?? ''));
        if (!$nameCheck->isOk()) {
            return ServiceResult::fail($nameCheck->message());
        }
        $username = (string) ($nameCheck->dataArray()['username'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $confirm  = (string) ($data['password_confirm'] ?? $data['confirm_password'] ?? '');

        if (strlen($password) < 8) {
            return ServiceResult::fail('密码至少 8 位');
        }
        if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/\d/', $password)) {
            return ServiceResult::fail('密码须包含字母与数字');
        }
        if ($password !== $confirm) {
            return ServiceResult::fail('两次密码不一致');
        }

        return ServiceResult::ok(['username' => $username, 'password' => $password]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function email(array $data): ServiceResult
    {
        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            return ServiceResult::ok(['email' => '']);
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ServiceResult::fail('邮箱格式不正确');
        }

        return ServiceResult::ok(['email' => $email]);
    }
}
