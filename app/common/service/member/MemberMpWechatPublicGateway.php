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

/**
 * 微信小程序会员 v1 API 可注入门面（Phase 2 DI）。
 */
final class MemberMpWechatPublicGateway
{

    public function __construct(
        private readonly MemberMpWechatAuthService $mpWechatAuth,
        private readonly MemberApiTokenService $memberApiToken,
    ) {
    }

    public function loginByCode(string $code): ServiceResult
    {
        return $this->mpWechatAuth->loginByCode($code);
    }

    /** @return array<string, mixed> */
    public function publicMember(int $userId): array
    {
        return $this->mpWechatAuth->publicMember($userId);
    }

    public function revokeCurrentToken(): void
    {
        $this->memberApiToken->revokeCurrentRequest();
    }
}
