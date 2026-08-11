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
use app\common\support\OpsLog;
use app\common\support\AppTime;
use app\common\service\member\MemberPointService;
use app\common\service\member\MemberConfigService;
use app\common\model\MemberSigninLog;
use app\common\model\MemberPointLog;
use app\common\model\User;

use think\facade\Db;

class MemberPointGiftService
{

    public function __construct(
        private readonly MemberConfigService $memberConfigService,
        private readonly MemberPointService $memberPointService,
    ) {
    }

    public function tryGrantLogin(int $userId): void
    {
        if ($userId < 1 || !$this->memberConfigService->isLoginGiftEnabled()) {
            return;
        }
        $pts = $this->memberConfigService->loginGiftPoints();
        if ($pts < 1) {
            return;
        }

        // 与 tryGrantSignin 同构：行锁 + 再检查，避免并发登录双赠
        Db::startTrans();
        try {
            $user = User::where('id', $userId)->lock(true)->find();
            if (!$user || $this->hasPointLogToday($userId, '登录赠送')) {
                Db::rollback();

                return;
            }
            $grant = $this->memberPointService->grant($userId, $pts, '登录赠送');
            if (!$grant->isOk() && ($grant->message() ?? '') !== 'skip') {
                Db::rollback();
                OpsLog::businessWarning('member_login_gift_failed', [
                    'user_id' => $userId,
                    'msg'     => (string) ($grant->message() ?? ''),
                ]);

                return;
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            OpsLog::businessWarning('member_login_gift_failed', [
                'user_id' => $userId,
                'msg'     => $e->getMessage(),
            ]);
        }
    }

    /** @return ServiceResult */
    public function tryGrantSignin(int $userId): ServiceResult
    {
        if ($userId < 1) {
            return ServiceResult::fail('请先登录');
        }
        if (!$this->memberConfigService->isSigninGiftEnabled()) {
            return ServiceResult::fail('签到功能未开启');
        }
        $pts = $this->memberConfigService->signinGiftPoints();
        if ($pts < 1) {
            return ServiceResult::fail('签到积分未配置');
        }
        if ($this->hasSigninToday($userId)) {
            return ServiceResult::fail('今日已签到');
        }

        $today = AppTime::today();
        Db::startTrans();
        try {
            MemberSigninLog::insert([
                'user_id'    => $userId,
                'sign_date'  => $today,
                'points'     => $pts,
                'created_at' => AppTime::now(),
            ]);
            $grant = $this->memberPointService->grant($userId, $pts, '每日签到');
            if (!$grant->isOk() && ($grant->message() ?? '') !== 'skip') {
                Db::rollback();

                return ServiceResult::fail((string) ($grant->message() ?? '积分发放失败'));
            }
            $this->addGrowth($userId, 1);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            if (str_contains($e->getMessage(), 'uk_user_date') || str_contains($e->getMessage(), 'Duplicate')) {
                return ServiceResult::fail('今日已签到');
            }
            OpsLog::businessWarning('member_signin_failed', [
                'user_id' => $userId,
                'msg'     => $e->getMessage(),
            ]);

            return ServiceResult::fail('签到失败，请稍后重试');
        }

        $label = $this->memberConfigService->pointsDisplayName();

        return ServiceResult::ok(['points' => $pts], "签到成功，+{$pts} {$label}");
    }

    public function tryGrantConsume(int $userId, float $amountYuan, string $reason): void
    {
        if ($userId < 1 || !$this->memberConfigService->isConsumeGiftEnabled()) {
            return;
        }
        $per = $this->memberConfigService->consumePointsPerYuan();
        if ($per < 1 || $amountYuan <= 0) {
            return;
        }
        $pts = (int) floor($amountYuan * $per);
        if ($pts < 1) {
            return;
        }
        $this->memberPointService->grant($userId, $pts, $reason);
        $this->addGrowth($userId, min($pts, 50));
    }

    public function addGrowth(int $userId, int $delta): void
    {
        if ($userId < 1 || $delta < 1) {
            return;
        }
        try {
            User::where('id', $userId)->inc('member_growth', $delta)->update();
        } catch (\Throwable $e) { OpsLog::businessWarning('member_point_gift_optional_failed', ['msg' => $e->getMessage()]); }
    }

    public function hasSigninToday(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        try {
            return MemberSigninLog::where('user_id', $userId)
                ->where('sign_date', AppTime::today())
                ->count() > 0;
        } catch (\Throwable $e) {
            OpsLog::businessWarning('member_signin_log_probe_failed', [
                'user_id' => $userId,
                'msg'     => $e->getMessage(),
            ]);

            return $this->hasPointLogToday($userId, '每日签到');
        }
    }

    private function hasPointLogToday(int $userId, string $reason): bool
    {
        $start = AppTime::startOfToday();

        return MemberPointLog::where('user_id', $userId)
            ->where('reason', $reason)
            ->where('created_at', '>=', $start)
            ->count() > 0;
    }
}
