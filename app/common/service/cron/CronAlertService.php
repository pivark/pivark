<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\cron;
use app\common\support\AppTime;

use app\common\service\config\ConfigService;
use app\common\service\mail\MailService;
use think\facade\Cache;

/** 计划任务连续失败时邮件告警（同任务 1h 内只发一封） */
final class CronAlertService
{

    public function __construct(
        private readonly ConfigService $config,
        private readonly MailService $mail,
    ) {
    }

    /**
     * @param array<string, mixed> $job
     */
    public function notifyJobFailed(array $job, string $message): void
    {
        if ((string) $this->config->get('cron_alert_enabled', '1') !== '1') {
            return;
        }

        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId < 1) {
            return;
        }

        $cacheKey = 'cron_alert:' . $jobId;
        if (Cache::get($cacheKey)) {
            return;
        }

        $to = trim((string) $this->config->get('cron_alert_email', ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $to = trim((string) $this->config->get('site_email', ''));
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $name    = trim((string) ($job['name'] ?? ''));
        $handler = trim((string) ($job['handler'] ?? ''));
        $site    = trim((string) $this->config->get('site_name', 'PivArk'));
        $subject = '[' . $site . '] 计划任务失败：' . ($name !== '' ? $name : $handler);
        $body    = '<p>任务 ID：' . $jobId . '</p>'
            . '<p>名称：' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>处理器：' . htmlspecialchars($handler, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>错误：' . htmlspecialchars(mb_substr($message, 0, 500), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>时间：' . AppTime::now() . '</p>';

        $sent = $this->mail->send($to, $subject, $body);
        if ($sent->isOk()) {
            Cache::set($cacheKey, 1, 3600);
        }
    }
}
