<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\security;

use app\common\service\plugin\WeappContext;

/** 第三方插件数据库表访问白名单（官方插件不校验，见 PluginThirdPartyPolicyService） */
final class PluginDataAccessGuard
{
    public static function assertWritableTable(string $identifier, string $table): void
    {
        if (PluginThirdPartyPolicyService::isOfficialIdentifier($identifier)) {
            return;
        }
        if (!app(WeappContext::class)->isTableOwnedByIdentifier($identifier, $table)) {
            throw new \RuntimeException('第三方插件禁止访问表：' . $table);
        }
    }
}
