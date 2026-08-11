<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin\login_notice;


/** 聚合登录弹窗提醒（内核 Registry · 插件后续桥接注册） */
class AdminLoginNoticeService
{

    public function __construct(
        private readonly AdminLoginNoticeAccessService $accessService,
        private readonly AdminLoginNoticeConfigService $configService,
    ) {
    }

    /**
     * @param array<string, mixed> $admin
     * @return list<array<string, mixed>>
     */
    public function collectForAdmin(array $admin): array
    {
        $userId = (int) ($admin['id'] ?? 0);
        if ($userId < 1) {
            return [];
        }

        $registry = app(PluginAdminLoginNoticeRegistry::class)->mergedWithKernel();
        if (!is_array($registry)) {
            return [];
        }

        $notices = [];
        foreach ($registry as $noticeId => $meta) {
            if (!is_array($meta) || !empty($meta['client_only'])) {
                continue;
            }
            if (!$this->configService->isChannelEnabled((string) $noticeId, 'popup')) {
                continue;
            }
            if (!$this->accessService->canReceiveNotice($userId, (string) $noticeId, $meta)) {
                continue;
            }
            $class = (string) ($meta['provider'] ?? '');
            if ($class === '' || !class_exists($class)) {
                continue;
            }
            $provider = app($class);
            if (!$provider instanceof AdminLoginNoticeProviderInterface) {
                continue;
            }
            foreach ($provider->collect($admin) as $notice) {
                $normalized = $this->normalizeNotice($notice, (string) $noticeId, $meta);
                if ($normalized !== null) {
                    $notices[] = $normalized;
                }
            }
        }

        usort($notices, static function (array $a, array $b): int {
            return ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
        });

        return $notices;
    }

    /**
     * @param array<string, mixed> $notice
     * @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    private function normalizeNotice(array $notice, string $noticeId, array $meta): ?array
    {
        $id = trim((string) ($notice['id'] ?? $noticeId));
        if ($id === '') {
            $id = $noticeId;
        }

        $actions = [];
        foreach ((array) ($notice['actions'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }
            $label = trim((string) ($action['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $actions[] = array_filter([
                'label'   => $label,
                'primary' => !empty($action['primary']),
                'dismiss' => !empty($action['dismiss']),
                'route'   => trim((string) ($action['route'] ?? '')),
                'href'    => trim((string) ($action['href'] ?? '')),
            ], static fn ($v): bool => $v !== '' && $v !== false);
        }
        if ($actions === []) {
            $actions[] = ['label' => '知道了', 'dismiss' => true];
        }

        $scope = (string) ($notice['dismiss_scope'] ?? 'session');
        if (!in_array($scope, ['session', 'day', 'fingerprint'], true)) {
            $scope = 'session';
        }

        return [
            'id'            => $id,
            'priority'      => (int) ($notice['priority'] ?? 0),
            'title'         => trim((string) ($notice['title'] ?? $meta['label'] ?? '提醒')),
            'message'       => trim((string) ($notice['message'] ?? '')),
            'urgent'        => !empty($notice['urgent']),
            'fingerprint'   => trim((string) ($notice['fingerprint'] ?? '')),
            'footer_hint'   => trim((string) ($notice['footer_hint'] ?? '')),
            'dismiss_scope' => $scope,
            'audience'      => (string) ($meta['audience'] ?? 'personal'),
            'items'         => array_values(array_filter(
                (array) ($notice['items'] ?? []),
                static fn ($row): bool => is_array($row) && trim((string) ($row['slug'] ?? '')) !== '',
            )),
            'actions'       => $actions,
        ];
    }
}
