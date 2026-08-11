<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin\login_notice;

/** 后台登录后弹窗提醒 · 单条供应方（内核 / 未来插件桥接） */
interface AdminLoginNoticeProviderInterface
{
    /**
     * @param array<string, mixed> $admin Session admin_user
     * @return list<array<string, mixed>> 见 AdminLoginNoticeService::normalizeNotice
     */
    public function collect(array $admin): array;
}
