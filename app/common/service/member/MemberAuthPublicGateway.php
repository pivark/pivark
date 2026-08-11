<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\service\front\FrontAuthService;
use app\common\service\front\FrontCsrfService;

/**
 * 会员 API 鉴权 v1 可注入门面（Bearer Token · Session · CSRF）。
 */
final class MemberAuthPublicGateway
{

    public function __construct(
        private readonly MemberApiTokenService $memberApiToken,
        private readonly MemberMpWechatAuthService $mpWechatAuth,
        private readonly FrontAuthService $frontAuth,
        private readonly FrontCsrfService $frontCsrf,
    ) {
    }

    public function resolveUserId(?string $token = null): int
    {
        return $this->memberApiToken->resolveUserId($token);
    }

    public function requireUserId(): int
    {
        return $this->memberApiToken->requireUserId();
    }

    public function openidForUser(int $userId): string
    {
        return $this->mpWechatAuth->openidForUser($userId);
    }

    /** @return array<string, mixed> */
    public function publicMember(int $userId): array
    {
        return $this->mpWechatAuth->publicMember($userId);
    }

    public function frontIsLoggedIn(): bool
    {
        return $this->frontAuth->isLoggedIn();
    }

    public function csrfFieldName(): string
    {
        return $this->frontCsrf->fieldName();
    }

    public function validateCsrfRequest(string $tokenFromBody, ?string $tokenFromHeader): bool
    {
        return $this->frontCsrf->validateRequest($tokenFromBody, $tokenFromHeader);
    }
}
