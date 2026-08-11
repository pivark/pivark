<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin\login_notice;

use app\common\service\config\ConfigService;
use app\common\service\mail\MailService;
use app\common\service\member\MemberUxService;
use app\common\support\ServiceResult;

/** 登录/事件提醒渠道配置（弹窗 · 邮件 · 短信） */
class AdminLoginNoticeConfigService
{

    public const CONFIG_KEY = 'admin_login_notice_settings';

    public function __construct(
        private readonly ConfigService $configService,
        private readonly AdminLoginNoticeAccessService $accessService,
        private readonly MailService $mailService,
        private readonly MemberUxService $memberUxService,
    ) {
    }

    /** @return array<string, array{popup:bool,email:bool,sms:bool}> */
    public function defaults(): array
    {
        $out = [];
        foreach ($this->registryNotices() as $noticeId => $meta) {
            $audience = (string) ($meta['audience'] ?? 'personal');
            $out[$noticeId] = [
                'popup' => true,
                'email' => $noticeId === 'form.pending_submissions',
                'sms'   => false,
            ];
            if ($audience === 'broadcast' && $noticeId === 'core.update') {
                $out[$noticeId]['email'] = false;
            }
        }

        return $out;
    }

    /** @return array<string, array{popup:bool,email:bool,sms:bool}> */
    public function noticeChannels(): array
    {
        $raw = $this->readRaw();
        $saved = is_array($raw['notices'] ?? null) ? $raw['notices'] : [];
        $merged = $this->defaults();
        foreach ($merged as $noticeId => $channels) {
            if (!is_array($saved[$noticeId] ?? null)) {
                continue;
            }
            foreach (['popup', 'email', 'sms'] as $key) {
                if (array_key_exists($key, $saved[$noticeId])) {
                    $merged[$noticeId][$key] = $this->toBool($saved[$noticeId][$key]);
                }
            }
        }

        return $merged;
    }

    public function isChannelEnabled(string $noticeId, string $channel): bool
    {
        $channels = $this->noticeChannels();
        if (!isset($channels[$noticeId])) {
            return false;
        }

        return !empty($channels[$noticeId][$channel]);
    }

    /** @return array<string, mixed> */
    public function metaForAdmin(): array
    {
        $caps = $this->memberUxService->adminCapabilityHints();

        return [
            'edition_mode' => $this->accessService->editionMode(),
            'channels'     => $this->noticeChannels(),
            'catalog'      => $this->accessService->catalogForRoleForm(),
            'capabilities' => [
                'mail_ready' => $this->mailService->isConfigured(),
                'sms_ready'  => (bool) ($caps['sms_ready'] ?? false),
            ],
            'help'         => [
                'broadcast' => '全员广播：企业站有控制台权限即可收到；host_only 须角色勾选对应权限。',
                'personal'  => '独有提醒：须在角色中勾选 admin.notice.*，并具备相关业务权限。',
                'email'     => '邮件在事件发生时即时发送给有权限的管理员；须先在「邮件接口」填写 SMTP。',
                'sms'       => '短信未配置时可开启「写日志」在 data/runtime/sms/ 模拟发送；网关对接后可真发。',
            ],
        ];
    }

    /** @param array<string, mixed> $post */
    public function saveAdmin(array $post): ServiceResult
    {
        $incoming = $post['notices'] ?? $post;
        if (is_string($incoming)) {
            $decoded = json_decode($incoming, true);
            $incoming = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($incoming)) {
            return ServiceResult::fail('参数无效');
        }

        $next = $this->defaults();
        foreach ($next as $noticeId => $channels) {
            if (!is_array($incoming[$noticeId] ?? null)) {
                continue;
            }
            $row = $incoming[$noticeId];
            foreach (['popup', 'email', 'sms'] as $key) {
                if (array_key_exists($key, $row)) {
                    $next[$noticeId][$key] = $this->toBool($row[$key]);
                }
            }
        }

        $encoded = json_encode(['notices' => $next], JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return ServiceResult::fail('保存失败');
        }
        $this->configService->set(self::CONFIG_KEY, $encoded);

        return ServiceResult::ok(['channels' => $next], '提醒设置已保存');
    }

    /** @return array<string, array<string, mixed>> */
    private function registryNotices(): array
    {
        $registry = config('admin.login_notices.notices', []);

        return is_array($registry) ? $registry : [];
    }

    /** @return array<string, mixed> */
    private function readRaw(): array
    {
        $raw = $this->configService->get(self::CONFIG_KEY, '');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
