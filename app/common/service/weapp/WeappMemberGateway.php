<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappMemberGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\support\ServiceResult;
use app\common\model\Document;
use app\common\model\Role;
use app\common\model\User;
use app\common\model\UserRole;
use app\common\service\member\PluginMemberConsumptionRegistry;
use app\common\service\member\MemberBalanceService;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberCenterNavRegistry;
use app\common\service\member\MemberCenterPageRegistry;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberPointGiftService;
use app\common\service\member\MemberPointService;
use app\common\service\member\MemberService;
use app\common\service\member\MemberDeleteLockRegistry;

final class WeappMemberGateway
{

    public function __construct(
        private readonly MemberConfigService $memberConfig,
        private readonly MemberPointService $memberPoint,
        private readonly MemberLevelService $memberLevel,
        private readonly MemberService $member,
        private readonly MemberBalanceService $memberBalance,
        private readonly MemberPointGiftService $memberPointGift,
        private readonly MemberCenterNavRegistry $memberCenterNav,
        private readonly MemberCenterPageRegistry $memberCenterPage,
    ) {
    }

    public function memberConfigPointsEnabled(): bool
    {
        return $this->memberConfig->isPointsEnabled();
    }

    public function memberConfigPointsLabel(): string
    {
        return $this->memberConfig->pointsLabel();
    }

    public function memberPointBalance(int $userId): int
    {
        return $this->memberPoint->balance($userId);
    }

    public function memberPointAdjust(int $userId, int $delta, string $reason, int $adminId = 0): ServiceResult
    {
        return $this->memberPoint->adjust($userId, $delta, $reason, $adminId);
    }

    public function memberLevelDefaultId(): int
    {
        return $this->memberLevel->defaultLevelId();
    }

    public function memberLevelRank(int $levelId): int
    {
        return $this->memberLevel->getRank($levelId);
    }

    /** @return array<string, mixed>|null */
    public function memberFindById(int $userId): ?array
    {
        return $this->member->findById($userId);
    }

    /**
     * 后台关键字筛会员：username|nickname LIKE（插件消费/订单搜索经 Gateway，禁止直引 User Model）。
     *
     * @return list<int>
     */
    public function memberUserIdsByUsernameNicknameLike(string $likePattern): array
    {
        $likePattern = trim($likePattern);
        if ($likePattern === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            User::whereLike('username|nickname', $likePattern)->column('id'),
        ), static fn (int $id): bool => $id > 0));
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function memberRegisterFromOAuth(array $data): ServiceResult
    {
        return $this->member->registerFromOAuth($data);
    }

    /**
     * 后台建会员（迁移/导入用；经 Gateway，禁止插件直引 MemberService）。
     *
     * @param array<string, mixed> $data
     */
    public function memberCreateAdmin(array $data): ServiceResult
    {
        return $this->member->createAdmin($data);
    }

    /** 按用户名找会员 id（0=无） */
    public function memberIdByUsername(string $username): int
    {
        $username = trim($username);
        if ($username === '') {
            return 0;
        }
        $id = (int) User::where('username', $username)->value('id');

        return $id > 0 ? $id : 0;
    }

    public function memberBalance(int $userId): float
    {
        return $this->memberBalance->balance($userId);
    }

    public function memberBalanceAdjust(
        int $userId,
        float $delta,
        string $reason,
        int $adminId = 0,
        string $ref = '',
        bool $allowNegative = false,
    ): ServiceResult {
        return $this->memberBalance->adjust($userId, $delta, $reason, $adminId, $ref, $allowNegative);
    }

    public function memberPointGiftTryGrantConsume(int $userId, float $amount, string $reason): void
    {
        $this->memberPointGift->tryGrantConsume($userId, $amount, $reason);
    }

    /** @return list<array<string, mixed>> */
    public function memberLevelsActive(): array
    {
        return $this->memberLevel->listActive();
    }

    /**
     * @param array<string, mixed>|\app\common\model\Document $document
     * @param array<string, mixed>|null $member
     */
    public function memberLevelCanReadDocument(array|\app\common\model\Document $document, ?array $member = null): bool
    {
        return $this->memberLevel->canReadDocument($document, $member);
    }

    public function memberLevelGetName(int $levelId): string
    {
        return $this->memberLevel->getName($levelId);
    }

    public function memberLevelIdByName(string $name, int $fallbackId = 0): int
    {
        $name = trim($name);
        if ($name === '') {
            return max(0, $fallbackId);
        }
        foreach ($this->memberLevelsActive() as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['name'] ?? '') === $name) {
                $id = (int) ($row['id'] ?? 0);

                return $id > 0 ? $id : max(0, $fallbackId);
            }
        }

        return max(0, $fallbackId);
    }

    /**
     * @param callable(array<string,mixed>): bool $filter
     */
    public function memberCenterNavRegisterRouteFilter(string $identifier, callable $filter): void
    {
        $this->memberCenterNav->registerRouteFilter($identifier, $filter);
    }

    /** @param callable(array<string,mixed>): mixed $handler */
    public function memberCenterPageRegister(
        string $identifier,
        string $action,
        callable $handler,
        ?string $getMemberPath = null,
        ?string $postMemberPath = null,
        ?string $pageTitle = null,
    ): void {
        $this->memberCenterPage->register($identifier, $action, $handler, $getMemberPath, $postMemberPath, $pageTitle);
    }

    public function memberRoleActiveIdByCode(string $code = MemberService::ROLE_CODE): int
    {
        return Role::activeIdByCode($code);
    }

    public function memberUserRoleExists(int $userId, int $roleId): bool
    {
        if ($userId < 1 || $roleId < 1) {
            return false;
        }

        return UserRole::where('user_id', $userId)->where('role_id', $roleId)->find() !== null;
    }

    public function memberUserRoleAttach(int $userId, int $roleId): void
    {
        if ($userId < 1 || $roleId < 1 || $this->memberUserRoleExists($userId, $roleId)) {
            return;
        }
        UserRole::create([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function memberUserRowById(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
        $row = User::where('id', $userId)->find()?->toArray();

        return is_array($row) ? $row : null;
    }

    public function memberConsumptionRegister(string $identifier, string $serviceShortName): void
    {
        app(PluginMemberConsumptionRegistry::class)->register($identifier, $serviceShortName);
    }

    /**
     * @param array{point_unlock_like?: list<string>, point_unlock_prefixes?: list<string>, point_biz_type?: string, paid_biz_type?: string, point_biz_label?: string, paid_biz_label?: string} $meta
     */
    public function memberConsumptionRegisterMeta(string $identifier, array $meta): void
    {
        app(PluginMemberConsumptionRegistry::class)->registerMeta($identifier, $meta);
    }

    public function memberDeleteLockRegisterId(int $memberId): void
    {
        app(MemberDeleteLockRegistry::class)->registerId($memberId);
    }

    public function memberDeleteLockRegisterUsername(string $username): void
    {
        app(MemberDeleteLockRegistry::class)->registerUsername($username);
    }
}
