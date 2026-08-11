<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\mail;

use app\common\support\AppTime;
use app\common\support\ServiceResult;

use app\common\service\config\ConfigService;
class MailService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    /**
     * @return ServiceResult
     */
    public function send(string $to, string $subject, string $bodyHtml, string $bodyText = ''): ServiceResult
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ServiceResult::fail('收件邮箱无效');
        }

        $from     = $this->fromAddress();
        $fromName = $this->fromName();
        $subject  = trim($subject);
        if ($subject === '') {
            return ServiceResult::fail('邮件主题不能为空');
        }

        if ($bodyText === '') {
            $bodyText = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml)));
        }
        if (trim($bodyHtml) === '' && $bodyText === '') {
            return ServiceResult::fail('邮件正文不能为空');
        }

        $host = trim((string) $this->configService->get('mail_smtp_host', ''));
        if ($host === '') {
            return $this->logFallback($to, $subject, $bodyHtml, $bodyText);
        }

        try {
            $this->sendSmtp($host, $to, $from, $fromName, $subject, $bodyHtml, $bodyText);
        } catch (\Throwable $e) {
            if ((bool) env('APP_DEBUG', false)) {
                return ServiceResult::fail('邮件发送失败：' . $e->getMessage());
            }
            \think\facade\Log::error('MailService send failed: ' . $e->getMessage());

            return ServiceResult::fail('邮件发送失败，请稍后重试或联系管理员');
        }

        return ServiceResult::ok(null, '邮件已发送');
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->configService->get('mail_smtp_host', '')) !== ''
            || (string) $this->configService->get('mail_log_fallback', '1') === '1';
    }

    private function fromAddress(): string
    {
        $addr = trim((string) $this->configService->get('mail_from_address', ''));
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            return $addr;
        }

        return trim((string) $this->configService->get('site_email', 'noreply@localhost'));
    }

    private function fromName(): string
    {
        $name = trim((string) $this->configService->get('mail_from_name', ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) $this->configService->get('site_name', 'PivArk'));
    }

    /**
     * @return ServiceResult
     */
    private function logFallback(string $to, string $subject, string $bodyHtml, string $bodyText): ServiceResult
    {
        if ((string) $this->configService->get('mail_log_fallback', '1') !== '1') {
            return ServiceResult::fail('邮件服务未配置，请联系管理员');
        }

        $dir = runtime_path() . 'mail';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . DIRECTORY_SEPARATOR . AppTime::format('Ymd_His') . '_' . preg_replace('/[^a-z0-9@._-]/i', '_', $to) . '.eml';
        $content = "To: {$to}\nSubject: {$subject}\nDate: " . AppTime::format('r') . "\n\n" . $bodyText . "\n\n-- HTML --\n" . $bodyHtml;
        file_put_contents($file, $content);

        return ServiceResult::ok(null, '邮件已写入日志（开发模式）');
    }

    private function sendSmtp(
        string $host,
        string $to,
        string $from,
        string $fromName,
        string $subject,
        string $bodyHtml,
        string $bodyText,
    ): void {
        $port   = max(1, (int) $this->configService->get('mail_smtp_port', 465));
        $user   = (string) $this->configService->get('mail_smtp_user', '');
        $pass   = (string) $this->configService->get('mail_smtp_pass', '');
        $secure = strtolower(trim((string) $this->configService->get('mail_smtp_secure', 'ssl')));

        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp     = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if ($fp === false) {
            throw new \RuntimeException($errstr !== '' ? $errstr : '无法连接 SMTP');
        }
        stream_set_timeout($fp, 15);

        try {
            $this->smtpExpect($fp, [220]);
            $ehloHost = 'pivark.local';
            $this->smtpCmd($fp, "EHLO {$ehloHost}\r\n", [250]);
            if ($secure === 'tls') {
                $this->smtpCmd($fp, "STARTTLS\r\n", [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS 失败');
                }
                $this->smtpCmd($fp, "EHLO {$ehloHost}\r\n", [250]);
            }
            if ($user !== '') {
                $this->smtpCmd($fp, "AUTH LOGIN\r\n", [334]);
                $this->smtpCmd($fp, base64_encode($user) . "\r\n", [334]);
                $this->smtpCmd($fp, base64_encode($pass) . "\r\n", [235]);
            }

            $this->smtpCmd($fp, 'MAIL FROM:<' . $from . ">\r\n", [250]);
            $this->smtpCmd($fp, 'RCPT TO:<' . $to . ">\r\n", [250, 251]);
            $this->smtpCmd($fp, "DATA\r\n", [354]);

            $boundary = 'pv_' . bin2hex(random_bytes(8));
            $headers  = [
                'From: ' . $this->encodeHeader($fromName) . " <{$from}>",
                'To: <' . $to . '>',
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                'Date: ' . AppTime::format('r'),
            ];
            $message  = implode("\r\n", $headers) . "\r\n\r\n";
            $message .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$bodyText}\r\n";
            $message .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$bodyHtml}\r\n";
            $message .= "--{$boundary}--\r\n";
            $message  = preg_replace('/^\./m', '..', $message) ?? $message;

            fwrite($fp, $message . "\r\n.\r\n");
            $this->smtpExpect($fp, [250]);
            $this->smtpCmd($fp, "QUIT\r\n", [221]);
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

  /** @param resource $fp */
    private function smtpCmd($fp, string $cmd, array $okCodes): void
    {
        fwrite($fp, $cmd);
        $this->smtpExpect($fp, $okCodes);
    }

    /** @param resource $fp */
    private function smtpExpect($fp, array $okCodes): void
    {
        $line = '';
        while (($buf = fgets($fp, 515)) !== false) {
            $line .= $buf;
            if (isset($buf[3]) && $buf[3] === ' ') {
                break;
            }
        }
        $code = (int) substr(trim($line), 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new \RuntimeException('SMTP 响应异常：' . trim($line));
        }
    }

    private function encodeHeader(string $text): string
    {
        if ($text === '' || preg_match('/^[\x20-\x7E]+$/', $text)) {
            return $text;
        }

        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
}
