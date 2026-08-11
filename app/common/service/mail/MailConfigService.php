<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\mail;

use app\common\service\config\ConfigSecretService;
use app\common\service\config\ConfigService;
use app\common\support\ServiceResult;
/** 后台 · 发信 SMTP 配置（凭据层 · 与提醒设置分离） */
class MailConfigService
{
    private const PASS_KEY = 'mail_smtp_pass';

    /** @return list<string> */
    public function keys(): array
    {
        return [
            'mail_smtp_host',
            'mail_smtp_port',
            'mail_smtp_secure',
            'mail_smtp_user',
            self::PASS_KEY,
            'mail_from_address',
            'mail_from_name',
            'mail_log_fallback',
        ];
    }

  /** @return array<string, string> */
    public function allForAdmin(): array
    {
        $this->ensurePassMigrated();
        $out = [];
        foreach ($this->keys() as $key) {
            if ($key === self::PASS_KEY) {
                $out[$key] = '';
                $out['mail_smtp_pass_set'] = $this->secretConfigured(self::PASS_KEY) ? '1' : '0';
                continue;
            }
            $out[$key] = (string) $this->configService->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function metaForAdmin(): array
    {
        $inbox = trim((string) $this->configService->get('mail_sandbox_inbox', ''));

        return [
            'cfg'                 => $this->allForAdmin(),
            'ready'               => $this->isReady(),
            'notify_usage'        => '会员验证、找回密码、后台事件提醒等共用此 SMTP。',
            'sandbox_inbox_url'   => $inbox,
            'ethereal_dev_only'   => (bool) env('APP_DEBUG', false),
        ];
    }

    public function isReady(): bool
    {
        return $this->mailService->isConfigured();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $this->ensurePassMigrated();
        $existing = $this->allForAdmin();
        $host = trim((string) ($data['mail_smtp_host'] ?? $existing['mail_smtp_host'] ?? ''));
        $port = max(1, min(65535, (int) ($data['mail_smtp_port'] ?? $existing['mail_smtp_port'] ?? 465)));
        $secure = strtolower(trim((string) ($data['mail_smtp_secure'] ?? $existing['mail_smtp_secure'] ?? 'ssl')));
        if (!in_array($secure, ['ssl', 'tls', 'none'], true)) {
            $secure = 'ssl';
        }

        $payload = [
            'mail_smtp_host'     => $host,
            'mail_smtp_port'     => (string) $port,
            'mail_smtp_secure'   => $secure,
            'mail_smtp_user'     => trim((string) ($data['mail_smtp_user'] ?? $existing['mail_smtp_user'] ?? '')),
            'mail_from_address'  => trim((string) ($data['mail_from_address'] ?? $existing['mail_from_address'] ?? '')),
            'mail_from_name'     => trim((string) ($data['mail_from_name'] ?? $existing['mail_from_name'] ?? '')),
            'mail_log_fallback'  => !empty($data['mail_log_fallback']) ? '1' : '0',
        ];

        $pass = trim((string) ($data[self::PASS_KEY] ?? ''));
        if ($pass !== '') {
            $payload[self::PASS_KEY] = $pass;
        } elseif ($this->secretConfigured(self::PASS_KEY)) {
            $payload[self::PASS_KEY] = (string) $this->configService->get(self::PASS_KEY, '');
        }

        foreach ($payload as $key => $value) {
            $this->configService->set($key, $value);
        }
        $this->configService->forgetRequestCache();

        return ServiceResult::ok(null, '邮件接口配置已保存');
    }

    /** 从 Ethereal Email 拉临时 SMTP（免费 · 仅开发联调） */
    public function applyEtherealSandbox(bool $persist = true): ServiceResult
    {
        if (!(bool) env('APP_DEBUG', false)) {
            return ServiceResult::fail('Ethereal 测试接入仅在 APP_DEBUG 开发站可用');
        }

        $cred = $this->fetchEtherealCredentials();
        if ($cred === null) {
            return ServiceResult::fail('无法连接 Ethereal 测试邮箱服务，请稍后重试');
        }

        $host = trim((string) ($cred['smtp']['host'] ?? 'smtp.ethereal.email'));
        $port = max(1, (int) ($cred['smtp']['port'] ?? 587));
        $secure = !empty($cred['smtp']['secure']) ? 'ssl' : 'tls';
        if ($port === 465) {
            $secure = 'ssl';
        }
        $user = trim((string) ($cred['user'] ?? ''));
        $pass = trim((string) ($cred['pass'] ?? ''));
        $web  = trim((string) ($cred['web'] ?? ''));
        if ($user === '' || $pass === '') {
            return ServiceResult::fail('Ethereal 返回凭据不完整');
        }

        $preview = [
            'user'      => $user,
            'host'      => $host,
            'port'      => (string) $port,
            'secure'    => $secure,
            'inbox_url' => $web,
            'applied'   => $persist,
        ];

        if (!$persist) {
            return ServiceResult::ok($preview, 'Ethereal 凭据已获取（未写入）');
        }

        $save = $this->saveAdmin([
            'mail_smtp_host'     => $host,
            'mail_smtp_port'     => (string) $port,
            'mail_smtp_secure'   => $secure,
            'mail_smtp_user'     => $user,
            'mail_smtp_pass'     => $pass,
            'mail_from_address'  => $user,
            'mail_from_name'     => 'PivArk Test',
            'mail_log_fallback'  => 0,
        ]);
        if (!$save->ok()) {
            return $save;
        }
        if ($web !== '') {
            $this->configService->set('mail_sandbox_inbox', $web);
        }
        $this->configService->forgetRequestCache();

        return ServiceResult::ok($preview, 'Ethereal 测试 SMTP 已写入，可在下方发测试信并在收件箱查看');
    }

    public function sendTestMail(string $to = ''): ServiceResult
    {
        $to = trim($to);
        if ($to === '') {
            $to = trim((string) $this->configService->get('mail_smtp_user', ''));
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ServiceResult::fail('请填写有效测试收件邮箱');
        }

        $site = trim((string) $this->configService->get('site_name', 'PivArk'));
        $subject = "{$site} 邮件接口测试";
        $html    = '<p>这是一封来自 PivArk 后台「邮件接口」的测试邮件。</p><p>时间：'
            . date('Y-m-d H:i:s') . '</p>';
        $text = '这是一封来自 PivArk 后台「邮件接口」的测试邮件。';

        $result = $this->mailService->send($to, $subject, $html, $text);
        if (!$result->ok()) {
            return $result;
        }

        $inbox = trim((string) $this->configService->get('mail_sandbox_inbox', ''));

        return ServiceResult::ok(
            ['inbox_url' => $inbox],
            $inbox !== ''
                ? '测试邮件已发送，请到 Ethereal 收件箱查看（链接见下方）'
                : ($result->message() ?: '测试邮件已发送'),
        );
    }

    /** @return array<string, mixed>|null */
    private function fetchEtherealCredentials(): ?array
    {
        $payload = json_encode([
            'requestor' => 'PivArk MailConfig',
            'version'   => '1.0.0',
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 20,
            ],
        ]);
        $raw = file_get_contents('https://api.nodemailer.com/user', false, $ctx);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) && !empty($data['user']) ? $data : null;
    }

    private function secretConfigured(string $key): bool
    {
        $val = trim((string) $this->configService->get($key, ''));

        return $val !== '';
    }

    /** 旧站 configs 明文 → config_secrets（幂等） */
    private function ensurePassMigrated(): void
    {
        try {
            app(ConfigSecretService::class)->migrateKeyFromConfigs(self::PASS_KEY);
        } catch (\Throwable) {
            // 未安装/无表时跳过
        }
    }

    private function defaultFor(string $key): string
    {
        return match ($key) {
            'mail_smtp_port'    => '465',
            'mail_smtp_secure'  => 'ssl',
            'mail_log_fallback' => '1',
            default             => '',
        };
    }

    public function __construct(
        private readonly ConfigService $configService,
        private readonly MailService $mailService,
    ) {
    }
}
