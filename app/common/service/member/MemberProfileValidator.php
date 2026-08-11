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

/** 会员资料/改密输入校验（纯逻辑，可单测；MemberService 门面委托） */
final class MemberProfileValidator
{

    public function changePassword(string $newPassword, string $confirm): ServiceResult
    {
        if (strlen($newPassword) < 6) {
            return ServiceResult::fail('新密码至少 6 位');
        }
        if ($newPassword !== $confirm) {
            return ServiceResult::fail('两次密码不一致');
        }

        return ServiceResult::ok();
    }
}
