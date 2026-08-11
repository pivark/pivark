<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin\login_notice;

use app\common\service\site\SiteFormService;

/** 自定表单未读提交 · 登录提醒（可见性由 AdminLoginNoticeAccessService 控制） */
final class FormPendingLoginNoticeProvider implements AdminLoginNoticeProviderInterface
{
    public function __construct(
        private readonly SiteFormService $siteFormService,
    ) {
    }

    public function collect(array $admin): array
    {
        if (($admin['id'] ?? 0) < 1) {
            return [];
        }

        $summary = $this->siteFormService->pendingLoginNoticeSummary();
        $total   = (int) ($summary['total'] ?? 0);
        $forms   = is_array($summary['forms'] ?? null) ? $summary['forms'] : [];
        if ($total < 1 || $forms === []) {
            return [];
        }

        $fingerprint = $this->buildFingerprint($forms);
        $message     = $this->buildMessage($total, $forms);
        $primary     = $forms[0];
        $primarySlug = (string) ($primary['slug'] ?? '');

        $actions = [
            [
                'label'   => '知道了',
                'dismiss' => true,
            ],
        ];
        if ($primarySlug !== '') {
            array_unshift($actions, [
                'label'   => count($forms) === 1 ? '查看提交记录' : '查看最早未读',
                'primary' => true,
                'route'   => '/site/form/submissions/' . $primarySlug . '?pending=1',
            ]);
        }
        if (count($forms) > 1) {
            $actions[] = [
                'label' => '表单列表',
                'route' => '/site/form',
            ];
        }

        $items = [];
        foreach ($forms as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $items[] = [
                'slug'    => $slug,
                'title'   => (string) ($row['title'] ?? $slug),
                'pending' => (int) ($row['pending'] ?? 0),
                'route'   => '/site/form/submissions/' . $slug . '?pending=1',
            ];
        }

        return [[
            'id'             => 'form.pending_submissions',
            'priority'       => 50,
            'title'          => '新留言待处理',
            'message'        => $message,
            'fingerprint'    => $fingerprint,
            'dismiss_scope'  => 'session',
            'items'          => $items,
            'actions'        => $actions,
        ]];
    }

    /**
     * @param list<array<string, mixed>> $forms
     */
    private function buildFingerprint(array $forms): string
    {
        $parts = [];
        foreach ($forms as $row) {
            $parts[] = (string) ($row['slug'] ?? '') . '=' . (int) ($row['pending'] ?? 0);
        }

        return implode(',', $parts);
    }

    /**
     * @param list<array<string, mixed>> $forms
     */
    private function buildMessage(int $total, array $forms): string
    {
        if (count($forms) === 1) {
            $one = $forms[0];
            $title = (string) ($one['title'] ?? $one['slug'] ?? '表单');

            return sprintf('「%s」有 %d 条未读提交待处理。', $title, $total);
        }

        return sprintf('共 %d 条未读表单提交，涉及 %d 个表单。', $total, count($forms));
    }
}
