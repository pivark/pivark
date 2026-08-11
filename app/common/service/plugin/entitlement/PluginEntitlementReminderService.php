<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\entitlement;

use app\common\service\audit\AuditLogService;
use app\common\service\config\ConfigService;
use app\common\service\mail\MailService;
use app\common\service\plugin\PluginService;
use app\common\support\AppTime;

final class PluginEntitlementReminderService
{
    /** @var list<int> */
    private const REMIND_DAYS = [7, 3, 1];

    /** @return array{sent:int,emails:int} */
    public function runDailyReminders(): array
    {
        $sent   = 0;
        $emails = 0;
        foreach (self::REMIND_DAYS as $days) {
            $batch = $this->remindExpiringWithin($days);
            $sent += $batch['sent'];
            $emails += $batch['emails'];
        }
        $batch = $this->remindRecentlyExpired();
        $sent += $batch['sent'];
        $emails += $batch['emails'];

        return ['sent' => $sent, 'emails' => $emails];
    }

    /**
     * @return array{expiring:int,expired:int,items:list<array<string,mixed>>}
     */
    public function dashboardAlerts(): array
    {
        $items    = [];
        $expiring = 0;
        $expired  = 0;
        $now      = time();

        foreach (app(EntitlementService::class)->listEntitledAdmin() as $row) {
            $identifier = $this->normalizeSlug((string) ($row['identifier'] ?? ''));
            if ($identifier === '') {
                continue;
            }
            $ent       = is_array($row['entitlement'] ?? null) ? $row['entitlement'] : [];
            $expireRaw = trim((string) ($ent['expire_at'] ?? ''));
            if ($expireRaw === '') {
                continue;
            }
            $ts = strtotime($expireRaw);
            if ($ts === false) {
                continue;
            }
            $name = $this->displayName($identifier, (string) ($row['name'] ?? ''));
            if ($ts < $now) {
                $expired++;
                $items[] = [
                    'identifier' => $identifier,
                    'name'       => $name,
                    'expire_at'  => $expireRaw,
                    'level'      => 'error',
                    'label'      => '已过期',
                ];
                continue;
            }
            $daysLeft = (int) ceil(($ts - $now) / 86400);
            if ($daysLeft <= 7) {
                $expiring++;
                $items[] = [
                    'identifier' => $identifier,
                    'name'       => $name,
                    'expire_at'  => $expireRaw,
                    'days_left'  => $daysLeft,
                    'level'      => $daysLeft <= 3 ? 'warning' : 'info',
                    'label'      => $daysLeft . ' 天后到期',
                ];
            }
        }

        return ['expiring' => $expiring, 'expired' => $expired, 'items' => $items];
    }

    /** @return array{sent:int,emails:int} */
    private function remindExpiringWithin(int $days): array
    {
        $sent   = 0;
        $emails = 0;
        $now   = time();
        $from  = AppTime::format('Y-m-d H:i:s', $now + max(0, $days - 1) * 86400);
        $to    = AppTime::format('Y-m-d H:i:s', $now + $days * 86400);

        foreach (app(EntitlementQueryService::class)->listActiveExpiringBetween($from, $to) as $row) {
            $id = $this->normalizeSlug((string) ($row['plugin_identifier'] ?? ''));
            if ($id === '' || $this->alreadySent($id, 'expiring_' . $days)) {
                continue;
            }
            $name    = $this->displayName($id);
            $expire  = (string) ($row['expire_at'] ?? '');
            $message = sprintf('插件「%s」将在 %d 天内到期（%s）', $name, $days, $expire);
            $notify  = $this->notify($id, $message);
            if ($notify['audit']) {
                $this->markSent($id, 'expiring_' . $days);
                $sent++;
                if ($notify['email_sent']) {
                    $emails++;
                }
            }
        }

        return ['sent' => $sent, 'emails' => $emails];
    }

    /** @return array{sent:int,emails:int} */
    private function remindRecentlyExpired(): array
    {
        $sent   = 0;
        $emails = 0;
        $since = AppTime::format('Y-m-d H:i:s', time() - 86400);
        foreach (app(EntitlementQueryService::class)->listExpiredUpdatedSince($since) as $row) {
            $id = $this->normalizeSlug((string) ($row['plugin_identifier'] ?? ''));
            if ($id === '' || $this->alreadySent($id, 'expired')) {
                continue;
            }
            $name    = $this->displayName($id);
            $message = sprintf('插件「%s」授权已过期，请前往插件市场续费或续领', $name);
            $notify  = $this->notify($id, $message);
            if ($notify['audit']) {
                $this->markSent($id, 'expired');
                $sent++;
                if ($notify['email_sent']) {
                    $emails++;
                }
            }
        }

        return ['sent' => $sent, 'emails' => $emails];
    }

    /**
     * @return array{audit:bool,email_sent:bool}
     */
    private function notify(string $identifier, string $message): array
    {
        app(AuditLogService::class)->write('system', 'plugin_entitlement_remind', 'admin.plugin', [
            'identifier' => $identifier,
            'message'    => $message,
        ], true);

        $emailSent = false;
        $email     = trim((string) app(ConfigService::class)->get('site_admin_email', ''));
        if ($email !== '' && app(MailService::class)->isConfigured()) {
            $adminLink = rtrim((string) app(ConfigService::class)->get('site_url', ''), '/')
                . '/admin/#/plugin/cloud';
            $body = '<p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '<p><a href="' . htmlspecialchars($adminLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '">打开后台插件市场</a></p>';
            $mailResult = app(MailService::class)->send($email, 'PivArk 插件授权提醒', $body);
            $emailSent  = $mailResult->isOk();
        }

        return ['audit' => true, 'email_sent' => $emailSent];
    }

    private function normalizeSlug(string $identifier): string
    {
        $raw = strtolower(trim($identifier));
        if ($raw === '') {
            return '';
        }
        $slug = app(EntitlementService::class)->resolveSlugIdentifier($raw);
        if ($slug === '' || $raw !== $slug) {
            return '';
        }

        return $slug;
    }

    private function displayName(string $identifier, string $fallback = ''): string
    {
        if ($fallback !== '' && $fallback !== $identifier) {
            return $fallback;
        }
        $manifest = app(PluginService::class)->readManifest($identifier);

        return (string) ($manifest['name'] ?? $identifier);
    }

    private function alreadySent(string $identifier, string $bucket): bool
    {
        $log = $this->sentLog();
        $key = $identifier . '|' . $bucket;

        return isset($log[$key]) && (string) $log[$key] === AppTime::today();
    }

    private function markSent(string $identifier, string $bucket): void
    {
        $log                              = $this->sentLog();
        $log[$identifier . '|' . $bucket] = AppTime::today();
        app(ConfigService::class)->set('plugin_entitlement_remind_log', json_encode($log, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, string> */
    private function sentLog(): array
    {
        $raw = app(ConfigService::class)->get('plugin_entitlement_remind_log', '');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $parsed = json_decode($raw, true);

        return is_array($parsed) ? $parsed : [];
    }
}
