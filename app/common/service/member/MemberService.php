<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * 前台会员 Facade — 委托至域 Service
 */
declare(strict_types=1);

namespace app\common\service\member;
use app\common\service\member\MemberAdminCrudService;
use app\common\service\member\MemberProfileService;
use app\common\service\member\MemberRegisterService;

use app\common\support\ServiceResult;

/** 前台会员（注册、资料、改密；与后台运维 users 共用表） */
class MemberService
{

    public function __construct(
        private readonly MemberRegisterService $memberRegisterService,
        private readonly MemberProfileService $memberProfileService,
        private readonly MemberAdminCrudService $memberAdminCrudService,
    ) {
    }

    public const ROLE_CODE = 'member';

    /**
     * 会员删除锁：系统账号用户名 + 插件注册的锁定 id（列表可见，接口与 UI 禁删）。
     */
    public static function isDeleteLockedMember(int $id, string $username = ''): bool
    {
        if (app(MemberDeleteLockRegistry::class)->isLocked($id, $username)) {
            return true;
        }
        $u = strtolower(trim($username));
        if ($u === '') {
            return false;
        }

        return $u === 'admin' || $u === 'pivark' || $u === 'pivark_official';
    }

    public function publicApiEnabled(): bool {
        return $this->memberRegisterService->publicApiEnabled();
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function registerPublic(array $data): ServiceResult {
        return $this->memberRegisterService->registerPublic($data);
    }

    public function checkUsernameAvailability(string $username): ServiceResult {
        return $this->memberRegisterService->checkUsernameAvailability($username);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateProfile(int $userId, array $data): ServiceResult {
        return $this->memberProfileService->updateProfile($userId, $data);
    }

    public function changePassword(int $userId, string $oldPassword, string $newPassword, string $confirm): ServiceResult {
        return $this->memberProfileService->changePassword($userId, $oldPassword, $newPassword, $confirm);
    }

    public function hasMemberRole(int $userId): bool {
        return $this->memberProfileService->hasMemberRole($userId);
    }

    public function isMemberAccount(int $userId): bool {
        return $this->memberProfileService->isMemberAccount($userId);
    }

    /**
     * @return \think\Paginator<\think\Model>
     */
    public function listAdmin(int $page = 1,
        int $limit = 15,
        string $keyword = '',
        int $levelId = 0,
        int $statusFilter = -1,
        string $source = '',
        string $dateFrom = '',
        string $dateTo = '',
        string $listFilter = '',
        string $mobile = '',
        string $accountKind = ''){
        return $this->memberAdminCrudService->listAdmin($page, $limit, $keyword, $levelId, $statusFilter, $source, $dateFrom, $dateTo, $listFilter, $mobile, $accountKind);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function enrichListRows(array $rows): array {
        return $this->memberAdminCrudService->enrichListRows($rows);
    }

    public function createAdminBatch(string $usernamesText, string $password, int $memberLevelId): ServiceResult {
        return $this->memberAdminCrudService->createAdminBatch($usernamesText, $password, $memberLevelId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createAdmin(array $data): ServiceResult {
        return $this->memberAdminCrudService->createAdmin($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateAdmin(int $id, array $data): ServiceResult {
        return $this->memberAdminCrudService->updateAdmin($id, $data);
    }

    public function extendLevelExpireAt(int $userId, int $days): string {
        return $this->memberAdminCrudService->extendLevelExpireAt($userId, $days);
    }

    public function levelDaysLeft(?string $expireAt): ?int {
        return $this->memberAdminCrudService->levelDaysLeft($expireAt);
    }

    /** @return array<string, mixed>|null */
    public function detailForAdmin(int $userId): ?array {
        return $this->memberAdminCrudService->detailForAdmin($userId);
    }

    public function findUserForAdminForm(int $userId): ?\app\common\model\User {
        return $this->memberAdminCrudService->findUserForAdminForm($userId);
    }

    public function deleteAdmin(int $id): ServiceResult {
        return $this->memberAdminCrudService->deleteAdmin($id);
    }

    /**
     * @param list<int|string> $ids
     */
    public function deleteAdminBatch(array $ids): ServiceResult {
        return $this->memberAdminCrudService->deleteAdminBatch($ids);
    }

    /** @return array<string, mixed>|null */
    public function profile(int $userId): ?array {
        return $this->memberProfileService->profile($userId);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $userId): ?array {
        return $this->memberProfileService->findById($userId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function registerFromOAuth(array $data): ServiceResult {
        return $this->memberRegisterService->registerFromOAuth($data);
    }
}
