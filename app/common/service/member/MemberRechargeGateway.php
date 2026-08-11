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

/** 会员充值 Gateway（前台 API + 构造注入） */
final class MemberRechargeGateway
{

    public function __construct(
        private readonly MemberRechargeService $recharge,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listPublic(): array
    {
        return $this->recharge->listPublic();
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @return list<array<string, mixed>>
     */
    public function enrichPublicForUser(array $packages, int $userId): array
    {
        return $this->recharge->enrichPublicForUser($packages, $userId);
    }

    /** @param array<string, mixed> $pkg */
    public function benefitSummary(array $pkg): string
    {
        return $this->recharge->benefitSummary($pkg);
    }

    /**
     * @param array<string, mixed> $extra
     * @return ServiceResult
     */
    public function createPaymentOrder(int $userId, int $packageId, string $channel, array $extra = []): ServiceResult
    {
        return $this->recharge->createPaymentOrder($userId, $packageId, $channel, $extra);
    }
}
