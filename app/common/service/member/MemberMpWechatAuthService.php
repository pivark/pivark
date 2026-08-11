<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\service\member\MemberBalanceService;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberApiTokenService;
use app\common\service\member\MemberService;
use app\common\model\UserOauthBinding;

use app\common\service\auth\SocialAuthService;
use app\common\service\channel\MiniprogramConfigService;
use app\common\service\auth\SocialAuthCapabilityRegistry;
use app\common\service\plugin\PluginService;
use app\common\service\config\ConfigService;
use app\common\support\OpsLog;

/** 微信小程序 code 登录（无 OAuth state，供 /api/v1 使用） */
class MemberMpWechatAuthService
{

    public function __construct(
        private readonly MiniprogramConfigService $miniprogramConfigService,
        private readonly ConfigService $configService,
        private readonly MemberService $memberService,
        private readonly MemberApiTokenService $memberApiTokenService,
        private readonly MemberLevelService $memberLevelService,
        private readonly MemberBalanceService $memberBalanceService,
        private readonly SocialAuthCapabilityRegistry $socialAuthCapabilityRegistry,
        private readonly SocialAuthService $socialAuthService,
    ) {
    }

    /**
     * @return ServiceResult
     */
    public function loginByCode(string $code): ServiceResult
    {
        if (!$this->miniprogramConfigService->isWechatChannelOpen()) {
            return ServiceResult::fail('小程序渠道未启用');
        }
        if ((int) $this->configService->get('mp_wechat_login_open', 1) !== 1) {
            return ServiceResult::fail('小程序登录未开启');
        }
        $code = trim($code);
        if ($code === '') {
            return ServiceResult::fail('缺少 code');
        }

        $socialPlugin = $this->socialAuthCapabilityRegistry->activeIdentifier();
        if ($socialPlugin !== '') {
            PluginService::registerAutoloadPublic($socialPlugin);
        }
        $profile = $this->socialAuthService->fetchMpWechatProfileByCode($code);
        if (!is_array($profile)) {
            return ServiceResult::fail('微信小程序登录未配置，请在后台填写 AppID/AppSecret');
        }

        $uid = trim((string) ($profile['provider_uid'] ?? ''));
        if ($uid === '') {
            return ServiceResult::fail('未获取 openid');
        }

        $userId = $this->resolveUserIdByOpenid($uid);
        if ($userId < 1) {
            $register = $this->memberService->registerFromOAuth([
                'provider'     => 'mp_wechat',
                'provider_uid' => $uid,
                'nickname'     => (string) ($profile['nickname'] ?? '微信用户'),
                'avatar'       => (string) ($profile['avatar'] ?? ''),
                'extra'        => $profile['extra'] ?? [],
            ]);
            if (!$register->isOk()) {
                return ServiceResult::fail((string) ($register->message() ?? '注册失败'));
            }
            $userId = (int) ($register['user_id'] ?? 0);
        } else {
            $this->touchBinding($userId, $profile);
        }

        if ($userId < 1 || $this->memberService->findById($userId) === null) {
            return ServiceResult::fail('登录失败');
        }

        try {
            $issued = $this->memberApiTokenService->issue($userId);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('member_mp_wechat_token_issue_failed', [
                'user_id' => $userId,
                'msg'     => $e->getMessage(),
            ]);

            return ServiceResult::fail('签发 Token 失败');
        }

        return ServiceResult::ok(['member' => $this->publicMember($userId), 'token' => $issued['token'], 'expires_at' => $issued['expires_at'], 'openid' => $uid], '登录成功');
    }

    public function openidForUser(int $userId): string
    {
        if ($userId < 1) {
            return '';
        }
        foreach (['mp_wechat', 'wechat'] as $provider) {
            $row = UserOauthBinding::where('user_id', $userId)
                ->where('provider', $provider)
                ->find();
            $uid = trim((string) ($row['provider_uid'] ?? ''));
            if ($uid !== '') {
                return $uid;
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    public function publicMember(int $userId): array
    {
        $row = $this->memberService->findById($userId);
        if ($row === null) {
            return [];
        }
        $levelId = (int) ($row['member_level_id'] ?? 0);

        return [
            'id'                    => $userId,
            'nickname'              => (string) ($row['nickname'] ?? ''),
            'avatar'                => (string) ($row['avatar'] ?? ''),
            'member_level_id'       => $levelId,
            'member_level_name'     => $levelId > 0 ? $this->memberLevelService->getName($levelId) : '',
            'member_points'         => (int) ($row['member_points'] ?? 0),
            'member_balance'        => $this->memberBalanceService->balance($userId),
            'member_level_expire_at'=> (string) ($row['member_level_expire_at'] ?? ''),
        ];
    }

    private function resolveUserIdByOpenid(string $openid): int
    {
        $binding = UserOauthBinding::where('provider', 'mp_wechat')
            ->where('provider_uid', $openid)
            ->find();

        return $binding ? (int) ($binding['user_id'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $profile */
    private function touchBinding(int $userId, array $profile): void
    {
        $uid = trim((string) ($profile['provider_uid'] ?? ''));
        if ($uid === '') {
            return;
        }
        UserOauthBinding::where('user_id', $userId)
            ->where('provider', 'mp_wechat')
            ->where('provider_uid', $uid)
            ->update([
                'extra_json' => json_encode($profile['extra'] ?? [], JSON_UNESCAPED_UNICODE),
                'updated_at' => AppTime::now(),
            ]);
    }
}
