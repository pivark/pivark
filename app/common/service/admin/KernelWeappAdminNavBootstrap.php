<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\service\weapp\WeappAdminGateway;

/** L1 内核模块后台 Tab 注册（读 config/kernel/l1_admin_nav.php · 不写死 identifier） */
final class KernelWeappAdminNavBootstrap
{
    public static function register(): void
    {
        $gw   = app(WeappAdminGateway::class);
        $cfg  = config('kernel.l1_admin_nav');
        $l1   = config('kernel.l1_modules');
        if (!is_array($cfg) || !is_array($l1)) {
            return;
        }
        $allowed = array_fill_keys(array_map(
            static fn ($id): string => strtolower(trim((string) $id)),
            $l1,
        ), true);
        foreach ($cfg as $moduleId => $tabs) {
            $moduleId = strtolower(trim((string) $moduleId));
            if ($moduleId === '' || !isset($allowed[$moduleId]) || !is_array($tabs)) {
                continue;
            }
            $navTabs = [];
            foreach ($tabs as $tab) {
                if (!is_array($tab)) {
                    continue;
                }
                $key = trim((string) ($tab['key'] ?? ''));
                $title = trim((string) ($tab['title'] ?? ''));
                $segment = trim((string) ($tab['segment'] ?? $key));
                if ($key === '' || $title === '') {
                    continue;
                }
                $navTabs[] = WeappAdminNavRegistry::tab($key, $title, $segment);
            }
            if ($navTabs !== []) {
                $gw->adminWeappNavTabsRegister($moduleId, $navTabs);
            }
        }
    }
}