<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split from MemberService — MemberRegisterService
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\service\member\MemberUxService;
use app\common\service\member\MemberRegisterVerifyService;
use app\common\service\member\MemberPointService;
use app\common\service\member\MemberFieldService;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberRegisterValidator;

use app\common\service\config\ConfigService;
use app\common\service\mail\MailService;

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

class MemberRegisterService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly MemberRegisterValidator $memberRegisterValidator,
        private readonly MemberConfigService $memberConfigService,
        private readonly MailService $mailService,
        private readonly MemberLevelService $memberLevelService,
        private readonly MemberFieldService $memberFieldService,
        private readonly MemberRegisterVerifyService $memberRegisterVerifyService,
        private readonly MemberUxService $memberUxService,
        private readonly MemberPointService $memberPointService,
        private readonly MemberEnterpriseProfileService $memberEnterpriseProfileService,
    ) {
    }

    /** 开放 API GET /api/v1/members 是否启用（configs.members_api_public，默认 0 · 需站长显式开启） */
    public function publicApiEnabled(): bool
    {
        return (int) $this->configService->get('members_api_public', 0) === 1;
    }

    /**
     * 注册页实时校验用户名是否可用（不写库）。
     * 恒返回 ok：data.available + reason(format|forbidden|taken|ok)。
     */
    public function checkUsernameAvailability(string $username): ServiceResult
    {
        $format = $this->memberRegisterValidator->username($username);
        if (!$format->isOk()) {
            return ServiceResult::ok([
                'available' => false,
                'reason'    => 'format',
            ], $format->message());
        }
        $username = (string) ($format->dataArray()['username'] ?? '');

        if ($this->memberConfigService->isUsernameForbidden($username)) {
            return ServiceResult::ok([
                'available' => false,
                'reason'    => 'forbidden',
                'username'  => $username,
            ], '该用户名禁止注册');
        }
        if (User::where('username', $username)->find()) {
            return ServiceResult::ok([
                'available' => false,
                'reason'    => 'taken',
                'username'  => $username,
            ], '用户名已被注册');
        }

        return ServiceResult::ok([
            'available' => true,
            'reason'    => 'ok',
            'username'  => $username,
        ], '用户名可用');
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function registerPublic(array $data): ServiceResult
    {
        $cred = $this->memberRegisterValidator->credentials($data);
        if (!$cred->isOk()) {
            return ServiceResult::fail($cred->message());
        }
        $credData = $cred->dataArray();
        $username = (string) ($credData['username'] ?? '');
        $password = (string) ($credData['password'] ?? '');

        $avail = $this->checkUsernameAvailability($username);
        $availData = $avail->dataArray();
        if (!(bool) ($availData['available'] ?? false)) {
            return ServiceResult::fail($avail->message() !== '' ? $avail->message() : '用户名不可用');
        }
        if (!$this->memberConfigService->isRegisterOpen()) {
            return ServiceResult::fail('暂未开放注册');
        }

        $accountKind = MemberAccountKind::normalize($data['account_kind'] ?? $data['kind'] ?? MemberAccountKind::PERSONAL);
        $enterprisePayload = null;
        if ($accountKind === MemberAccountKind::ENTERPRISE) {
            if (!$this->memberConfigService->isEnterpriseRegisterOpen()) {
                return ServiceResult::fail('暂未开放企业注册');
            }
            $entCheck = $this->memberEnterpriseProfileService->validatePayload($data, true);
            if (!$entCheck->isOk()) {
                return ServiceResult::fail($entCheck->message());
            }
            $enterprisePayload = $entCheck->dataArray();
        }

        $emailCheck = $this->memberRegisterValidator->email($data);
        if (!$emailCheck->isOk()) {
            return ServiceResult::fail($emailCheck->message());
        }
        $email = (string) ($emailCheck->dataArray()['email'] ?? '');
        if ($email !== '' && User::where('email', $email)->find()) {
            return ServiceResult::fail('邮箱已被使用');
        }
        $mobile = trim((string) ($data['mobile'] ?? ''));

        $verify = $this->memberConfigService->registerVerifyMode();
        if ($verify === 'sms') {
            return ServiceResult::fail('手机验证注册尚未对接，请在后台改为「不验证」「后台激活」或「邮件验证」');
        }
        $emailVerifyPending = false;
        if ($verify === 'email') {
            if (!$this->mailService->isConfigured()) {
                return ServiceResult::fail('邮件验证需先配置 SMTP（系统设置 → 邮件），或改为「不验证」/「后台激活」');
            }
            if ($email === '') {
                return ServiceResult::fail('邮件验证注册须填写邮箱');
            }
            $emailVerifyPending = true;
        }

        $ip = trim(Request::ip() ?: '');
        $ipHours = $this->memberConfigService->registerIpLimitHours();
        if ($ipHours > 0 && $ip !== '') {
            $since = AppTime::format('Y-m-d H:i:s', time() - $ipHours * 3600);
            $exists = User::where('register_ip', $ip)->where('created_at', '>=', $since)->find();
            if ($exists) {
                return ServiceResult::fail("同一 IP 在 {$ipHours} 小时内仅可注册一次");
            }
        }

        $memberRoleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($memberRoleId < 1) {
            return ServiceResult::fail('会员角色未初始化，请联系管理员');
        }

        Db::startTrans();
        try {
            $payload = [
                'username'     => $username,
                'password'     => password_hash($password, PASSWORD_BCRYPT),
                'nickname'     => trim((string) ($data['nickname'] ?? '')) ?: $username,
                'status'       => ($this->memberConfigService->registerNeedsAdminActivation() || $emailVerifyPending) ? 0 : 1,
                'account_kind' => $accountKind,
            ];
            if ($ip !== '') {
                $payload['register_ip'] = $ip;
            }
            if ($email !== '') {
                $payload['email'] = $email;
            }
            if ($mobile !== '') {
                $payload['mobile'] = $mobile;
            }
            $user = User::create($payload);
            UserRole::syncForUser((int) $user->id, [$memberRoleId]);
            $defaultLevelId = $this->memberLevelService->defaultLevelId();
            if ($defaultLevelId > 0) {
                User::where('id', (int) $user->id)->update(['member_level_id' => $defaultLevelId]);
            }
            if ($accountKind === MemberAccountKind::ENTERPRISE && is_array($enterprisePayload)) {
                $this->memberEnterpriseProfileService->upsert((int) $user->id, $enterprisePayload);
            }
            $fieldSync = $this->memberFieldService->validateAndSyncUser((int) $user->id, $data, 'register');
            if (!$fieldSync->isOk()) {
                throw new \RuntimeException($fieldSync->message());
            }
            if ($this->memberConfigService->isPointsEnabled()
                && !$emailVerifyPending
                && !$this->memberConfigService->registerNeedsAdminActivation()) {
                $gift = $this->memberConfigService->registerGiftPoints();
                if ($gift > 0) {
                    $this->memberPointService->grant((int) $user->id, $gift, '注册赠送');
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail($e->getMessage() !== '' && $e->getMessage() !== '0' ? $e->getMessage() : '注册失败，请稍后重试');
        }

        if ($emailVerifyPending) {
            $mailRes = $this->memberRegisterVerifyService->sendForUser((int) $user->id, $email);
            if (!$mailRes->isOk()) {
                $uid = (int) $user->id;
                try {
                    Db::transaction(function () use ($uid): void {
                        UserRole::syncForUser($uid, []);
                        User::where('id', $uid)->delete();
                    });
                } catch (\Throwable $e) {
                    return ServiceResult::fail('验证邮件发送失败，且未能回滚注册，请联系管理员');
                }

                return ServiceResult::fail($mailRes->message());
            }
        }

        $msg = $this->memberUxService->registerSuccessMessage(
            $this->memberConfigService->registerNeedsAdminActivation(),
            $emailVerifyPending
        );

        return ServiceResult::ok(['user_id' => (int) $user->id, 'pending' => $this->memberConfigService->registerNeedsAdminActivation() ? 1 : 0, 'email_pending' => $emailVerifyPending ? 1 : 0], $msg);
    }

    /**
     * OAuth 首次登录自动注册
     *
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function registerFromOAuth(array $data): ServiceResult
    {
        $provider = trim((string) ($data['provider'] ?? ''));
        $uid      = trim((string) ($data['provider_uid'] ?? ''));
        if ($provider === '' || $uid === '') {
            return ServiceResult::fail('OAuth 参数不完整');
        }
        if (!$this->memberConfigService->isRegisterOpen()) {
            return ServiceResult::fail('暂未开放注册');
        }

        $memberRoleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($memberRoleId < 1) {
            return ServiceResult::fail('会员角色未初始化');
        }

        $nickname = trim((string) ($data['nickname'] ?? ''));
        $base     = preg_replace('/[^a-zA-Z0-9_]/', '', $provider) ?: 'oauth';
        $username = $base . '_' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $uid) ?: bin2hex(random_bytes(4)), 0, 20);
        $suffix   = 0;
        while (User::where('username', $username)->find()) {
            $suffix++;
            $username = $base . '_' . substr(hash('crc32', $uid . (string) $suffix), 0, 8);
        }

        Db::startTrans();
        try {
            $user = User::create([
                'username'     => $username,
                'password'     => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'nickname'     => $nickname !== '' ? mb_substr($nickname, 0, 50) : $username,
                'avatar'       => mb_substr(trim((string) ($data['avatar'] ?? '')), 0, 255),
                'status'       => 1,
                'account_kind' => MemberAccountKind::PERSONAL,
            ]);
            $userId = (int) $user->id;
            UserRole::syncForUser($userId, [$memberRoleId]);
            $defaultLevelId = $this->memberLevelService->defaultLevelId();
            if ($defaultLevelId > 0) {
                User::where('id', $userId)->update(['member_level_id' => $defaultLevelId]);
            }
            UserOauthBinding::insert([
                'user_id'      => $userId,
                'provider'     => $provider,
                'provider_uid' => $uid,
                'nickname'     => $nickname !== '' ? mb_substr($nickname, 0, 100) : null,
                'avatar'       => mb_substr(trim((string) ($data['avatar'] ?? '')), 0, 255) ?: null,
                'extra_json'   => json_encode($data['extra'] ?? [], JSON_UNESCAPED_UNICODE),
                'created_at'   => AppTime::now(),
                'updated_at'   => AppTime::now(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail('注册失败');
        }

        return ServiceResult::ok(['user_id' => $userId], '注册成功');
    }
}
