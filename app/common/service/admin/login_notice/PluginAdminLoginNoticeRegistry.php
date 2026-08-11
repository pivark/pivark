<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin\login_notice;


/**
 * 插件登录提醒注册表（boot 时 register · 禁止平行弹窗队列）
 *
 * 用法（weapp 插件 boot）：
 * PluginAdminLoginNoticeRegistry — register via app(PluginAdminLoginNoticeRegistry::class)->register('oa.pending_tasks', [
 *     'provider' => OaPendingLoginNoticeProvider::class,
 *     'permission' => 'admin.notice.oa_pending',
 *     'audience' => 'personal',
 *     'label' => 'OA 待办弹窗',
 * ]);
 */
final class PluginAdminLoginNoticeRegistry
{

    /** @var array<string, array<string, mixed>> */
    private static array $notices = [];

    public function reset(): void
    {
        self::$notices = [];
    }

    /** @param array<string, mixed> $meta */
    public function register(string $noticeId, array $meta): void
    {
        $noticeId = trim($noticeId);
        if ($noticeId === '' || $meta === []) {
            return;
        }
        self::$notices[$noticeId] = $meta;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return self::$notices;
    }

    /** @return array<string, array<string, mixed>> */
    public function mergedWithKernel(): array
    {
        $kernel = config('admin.login_notices.notices', []);
        if (!is_array($kernel)) {
            $kernel = [];
        }

        return array_merge($kernel, self::$notices);
    }
}
