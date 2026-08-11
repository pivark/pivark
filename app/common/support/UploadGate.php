<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\enum\ApiErrorCode;
use app\common\service\front\FrontAuthService;
use app\common\service\member\MemberApiTokenService;
use app\common\service\member\MemberService;
use app\common\service\upload\UploadService;
use think\facade\Session;

class UploadGate
{
    public const ERR_UNAUTHORIZED = '未登录或无权上传';
    public const ERR_SCENE        = '不允许的上传场景';
    public const ERR_TERMINAL     = '当前终端不允许使用该场景';

    /**
     * @return array{terminal:string,user_id:int,username:string}|null
     */
    public static function identity(): ?array
    {
        $admin = Session::get('admin_user');
        if (is_array($admin) && !empty($admin['id'])) {
            return [
                'terminal' => 'admin',
                'user_id'  => (int) $admin['id'],
                'username' => (string) ($admin['username'] ?? ''),
            ];
        }

        $member = app(FrontAuthService::class)->current();
        if ($member !== null && !empty($member['id'])) {
            return [
                'terminal' => 'home',
                'user_id'  => (int) $member['id'],
                'username' => (string) ($member['username'] ?? ''),
            ];
        }

        $tokenUserId = app(MemberApiTokenService::class)->resolveUserId();
        if ($tokenUserId > 0) {
            $memberRow = app(MemberService::class)->findById($tokenUserId);

            return [
                'terminal' => 'miniprogram',
                'user_id'  => $tokenUserId,
                'username' => is_array($memberRow) ? (string) ($memberRow['username'] ?? '') : '',
            ];
        }

        return null;
    }

    public static function assertUpload(string $scene): void
    {
        $identity = self::identity();
        if ($identity === null) {
            throw new UploadGateException(self::ERR_UNAUTHORIZED, 401);
        }

        $scene = UploadService::normalizeScene($scene);
        $meta  = app(UploadService::class)->sceneMeta($scene);
        if ($meta === []) {
            throw new UploadGateException(self::ERR_SCENE, 403);
        }

        $status = (string) ($meta['status'] ?? 'active');
        if ($status === 'reserved' && $identity['terminal'] !== 'admin') {
            throw new UploadGateException('该上传场景尚未对当前终端开放', 403);
        }

        $allowed = $meta['allowed_terminals'] ?? ['admin'];
        if (!is_array($allowed)) {
            $allowed = ['admin'];
        }
        if (!in_array($identity['terminal'], $allowed, true)) {
            throw new UploadGateException(self::ERR_TERMINAL, 403);
        }
    }

    /** 素材列表：v1 与上传同策略（已登录 admin / 会员 Session / Bearer） */
    public static function assertMediaList(): void
    {
        if (self::identity() === null) {
            throw new UploadGateException(self::ERR_UNAUTHORIZED, 401);
        }
    }

    /** 前台会员 Session（非 admin / 小程序 Bearer） */
    public static function isMemberTerminal(): bool
    {
        $identity = self::identity();

        return is_array($identity) && ($identity['terminal'] ?? '') === 'home';
    }

    public static function memberUserId(): int
    {
        $identity = self::identity();
        if (!is_array($identity) || ($identity['terminal'] ?? '') !== 'home') {
            return 0;
        }

        return max(0, (int) ($identity['user_id'] ?? 0));
    }

    /**
     * API 未登录时 JSON 响应
     */
    public static function failJson(UploadGateException $e): \think\response\Json
    {
        $httpCode = $e->getHttpCode();
        $errCode  = match ($httpCode) {
            401     => ApiErrorCode::AUTH_REQUIRED,
            429     => ApiErrorCode::RATE_LIMITED,
            default => ApiErrorCode::PERMISSION_DENIED,
        };

        return ApiResponse::httpFailCode($httpCode, $errCode, $e->getMessage());
    }
}

class UploadGateException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpCode = 403
    ) {
        parent::__construct($message);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
