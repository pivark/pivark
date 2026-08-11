<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split from MemberService — MemberProfileService
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\ServiceResult;

use app\common\model\MemberFieldValue;
use app\common\model\UserOauthBinding;
use app\common\model\MemberCancelRequest;
use app\common\model\MemberBalanceLog;
use app\common\model\MemberPointLog;

use think\facade\Request;

use app\common\service\user\UserService;
use app\common\model\Role;
use app\common\model\User;
use app\common\model\UserRole;
use app\common\service\audit\AuditLogService;
use app\common\service\content\ContentSearchService;
use app\common\support\HtmlSanitizer;
use app\common\support\SiteUrl;
use think\facade\Db;

class MemberProfileService
{

    public function __construct(
        private readonly MemberRegisterValidator $registerValidator,
        private readonly UserService $users,
        private readonly MemberFieldService $fields,
        private readonly MemberProfileValidator $profileValidator,
        private readonly MemberEnterpriseProfileService $enterpriseProfiles,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function updateProfile(int $userId, array $data): ServiceResult
    {
        if ($userId < 1 || !$this->hasMemberRole($userId)) {
            return ServiceResult::fail('会员不存在');
        }

        $emailCheck = $this->registerValidator->email($data);
        if (!$emailCheck->isOk()) {
            return ServiceResult::fail($emailCheck->message());
        }
        $email = (string) ($emailCheck->dataArray()['email'] ?? '');
        if ($email !== '') {
            $exists = User::where('email', $email)->where('id', '<>', $userId)->find();
            if ($exists) {
                return ServiceResult::fail('邮箱已被使用');
            }
        }

        $user = User::find($userId);
        if (!$user instanceof User) {
            return ServiceResult::fail('会员不存在');
        }

        $profile = [
            'nickname' => trim((string) ($data['nickname'] ?? $user->nickname)),
            'email'    => $email,
            'mobile'   => trim((string) ($data['mobile'] ?? $user->mobile)),
        ];
        if (array_key_exists('avatar', $data)) {
            $profile['avatar'] = $this->users->normalizeAvatarPublic($data['avatar']);
        }
        $user->allowField(['nickname', 'email', 'mobile', 'avatar'])->save($profile);

        $kind = MemberAccountKind::normalize($user->getAttr('account_kind') ?? MemberAccountKind::PERSONAL);
        $wantKind = array_key_exists('account_kind', $data) || array_key_exists('kind', $data)
            ? MemberAccountKind::normalize($data['account_kind'] ?? $data['kind'] ?? $kind)
            : $kind;

        // 个人 → 企业：须企业注册开关开启（与前台企业通道一致）
        if ($kind === MemberAccountKind::PERSONAL && $wantKind === MemberAccountKind::ENTERPRISE) {
            if (!app(MemberConfigService::class)->isEnterpriseRegisterOpen()) {
                return ServiceResult::fail('企业注册通道已关闭，暂无法升级为企业账号');
            }
            $entCheck = $this->enterpriseProfiles->validatePayload($data, true);
            if (!$entCheck->isOk()) {
                return ServiceResult::fail($entCheck->message());
            }
            User::where('id', $userId)->update(['account_kind' => MemberAccountKind::ENTERPRISE]);
            $this->enterpriseProfiles->upsert($userId, $entCheck->dataArray());
            $kind = MemberAccountKind::ENTERPRISE;
        } elseif ($kind === MemberAccountKind::ENTERPRISE) {
            $entCheck = $this->enterpriseProfiles->validatePayload($data, true);
            if (!$entCheck->isOk()) {
                return ServiceResult::fail($entCheck->message());
            }
            $this->enterpriseProfiles->upsert($userId, $entCheck->dataArray());
        }

        $fieldSync = $this->fields->validateAndSyncUser($userId, $data, 'profile');
        if (!$fieldSync->isOk()) {
            return $fieldSync;
        }

        return ServiceResult::ok(null, '资料已保存');
    }

    /**
     * @return ServiceResult
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword, string $confirm): ServiceResult
    {
        if ($userId < 1 || !$this->hasMemberRole($userId)) {
            return ServiceResult::fail('会员不存在');
        }
        $pwdCheck = $this->profileValidator->changePassword($newPassword, $confirm);
        if (!$pwdCheck->isOk()) {
            return ServiceResult::fail($pwdCheck->message());
        }

        $user = User::find($userId);
        if (!$user instanceof User || !password_verify($oldPassword, (string) $user->password)) {
            return ServiceResult::fail('原密码错误');
        }

        $user->save(['password' => password_hash($newPassword, PASSWORD_BCRYPT)]);
        app(\app\common\service\member\MemberApiTokenService::class)->revokeAllForUser($userId);

        return ServiceResult::ok(null, '密码已修改');
    }

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

    /** 有效会员账号：member 角色且 users 行存在（排除 orphan UserRole） */
    public function isMemberAccount(int $userId): bool
    {
        if (!$this->hasMemberRole($userId)) {
            return false;
        }

        return User::where('id', $userId)->find() !== null;
    }

    /** @return array<string, mixed>|null */
    public function profile(int $userId): ?array
    {
        if ($userId < 1 || !$this->hasMemberRole($userId)) {
            return null;
        }
        $user = User::find($userId);

        return $user instanceof User ? $user->toArray() : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $userId): ?array
    {
        return $this->profile($userId);
    }
}
