<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\support\AppTime;

use app\common\support\ServiceResult;

use app\common\model\User;
use app\common\service\audit\AuditLogService;
use app\common\service\config\ConfigService;
use think\facade\Session;

/** 管理员 TOTP（RFC 6238，30s 步长） */
final class AdminTotpService
{

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly ConfigService $configService,
    ) {
    }

    public const FIELD_NAME = 'admin_confirm_totp';

    private const SESSION_PENDING = 'admin_totp_pending_secret';
    private const ISSUER          = 'PivArk Admin';

    public function isEnabled(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $row = User::where('id', $userId)->field('totp_enabled,totp_secret')->find();
        if ($row === null) {
            return false;
        }

        return (int) ($row['totp_enabled'] ?? 0) === 1
            && trim((string) ($row['totp_secret'] ?? '')) !== '';
    }

    /**
     * @return array{enabled:bool,issuer:string}
     */
    public function status(int $userId): array
    {
        return [
            'enabled' => $this->isEnabled($userId),
            'issuer'  => self::ISSUER,
        ];
    }

    /**
     * @return array{secret:string,otpauth_uri:string}
     */
    public function beginSetup(int $userId, string $username): array
    {
        $secret = $this->generateSecret();
        Session::set(self::SESSION_PENDING, [
            'user_id' => $userId,
            'secret'  => $secret,
            'until'   => time() + 600,
        ]);

        return [
            'secret'       => $secret,
            'otpauth_uri'  => $this->otpauthUri($username, $secret),
        ];
    }

    public function enable(int $userId, string $code): ServiceResult
    {
        $pending = Session::get(self::SESSION_PENDING);
        if (!is_array($pending) || (int) ($pending['user_id'] ?? 0) !== $userId) {
            return ServiceResult::fail('请先获取绑定密钥');
        }
        if ((int) ($pending['until'] ?? 0) < time()) {
            return ServiceResult::fail('绑定密钥已过期，请重新获取');
        }
        $secret = trim((string) ($pending['secret'] ?? ''));
        if ($secret === '' || !$this->verifyCode($secret, $code)) {
            return ServiceResult::fail('验证码不正确');
        }
        User::where('id', $userId)->update([
            'totp_secret'  => $secret,
            'totp_enabled' => 1,
            'updated_at'   => AppTime::now(),
        ]);
        Session::delete(self::SESSION_PENDING);
        $this->auditLogService->operate('启用 TOTP', 'admin.profile', ['user_id' => $userId]);

        return ServiceResult::ok(null, '两步验证已启用');
    }

    public function disable(int $userId, string $code): ServiceResult
    {
        if (!$this->isEnabled($userId)) {
            return ServiceResult::fail('尚未启用两步验证');
        }
        if (!$this->verifyForUser($userId, $code)) {
            return ServiceResult::fail('验证码不正确');
        }
        User::where('id', $userId)->update([
            'totp_secret'  => '',
            'totp_enabled' => 0,
            'updated_at'   => AppTime::now(),
        ]);
        $this->auditLogService->operate('关闭 TOTP', 'admin.profile', ['user_id' => $userId]);

        return ServiceResult::ok(null, '两步验证已关闭');
    }

    public function verifyForUser(int $userId, string $code): bool
    {
        if ($userId < 1) {
            return false;
        }
        $row = User::where('id', $userId)->field('totp_secret,totp_enabled')->find();
        if ($row === null || (int) ($row['totp_enabled'] ?? 0) !== 1) {
            return false;
        }

        return $this->verifyCode((string) ($row['totp_secret'] ?? ''), $code);
    }

    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', trim($code)) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $secret = strtoupper(preg_replace('/\s+/', '', $secret) ?? '');
        if ($secret === '') {
            return false;
        }
        $timeSlice = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->hotp($secret, $timeSlice + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    private function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out      = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, 31)];
        }

        return $out;
    }

    private function otpauthUri(string $username, string $secret): string
    {
        $site = trim((string) $this->configService->get('site_name', 'PivArk'));
        $label = rawurlencode($site . ':' . $username);

        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode(self::ISSUER)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    private function hotp(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        if ($key === '') {
            return '000000';
        }
        $binCounter = pack('N*', 0, $counter);
        $hash       = hash_hmac('sha1', $binCounter, $key, true);
        $offset     = ord($hash[19]) & 0x0f;
        $truncated  = (
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)
        );

        return str_pad((string) ($truncated % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7=]/', '', $secret) ?? '');
        if ($secret === '') {
            return '';
        }
        $map = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $bits = '';
        for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
            $ch = $secret[$i];
            if ($ch === '=') {
                break;
            }
            if (!isset($map[$ch])) {
                return '';
            }
            $bits .= str_pad(decbin($map[$ch]), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0, $len = strlen($bits); $i + 8 <= $len; $i += 8) {
            $out .= chr((int) bindec(substr($bits, $i, 8)));
        }

        return $out;
    }
}
