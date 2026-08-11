<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\seed;

use app\common\service\admin\AdminNavPersonaRegistry;

/** 插件变更后刷新 Nav Persona 合并缓存 */
final class NavPersonaPackSeedService
{
    public function __construct(
        private readonly AdminNavPersonaRegistry $adminNavPersonaRegistry,
    ) {
    }

    public function afterPluginChange(string $identifier): void
    {
        if (trim($identifier) === '') {
            return;
        }
        $this->adminNavPersonaRegistry->bustCache();
    }
}
