<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\security;

use app\common\support\SiteEnv;

/** 安装/运维：生成并持久化插件紧急 URL token（PIVARK_PLUGIN_EMERGENCY_TOKEN） */
final class PluginEmergencyTokenService
{
    public function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function readToken(string $root): string
    {
        $path = SiteEnv::resolvePath($root);
        if ($path === null) {
            return '';
        }
        $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
        if (!is_array($parsed)) {
            return '';
        }

        return trim((string) ($parsed['PIVARK_PLUGIN_EMERGENCY_TOKEN'] ?? ''));
    }

    /** 若 site.env 尚无 token 则写入并返回（已有则原样返回） */
    public function ensureConfigured(string $root): string
    {
        $existing = $this->readToken($root);
        if ($existing !== '') {
            return $existing;
        }

        $token = $this->generateToken();
        SiteEnv::upsertKey($root, 'PIVARK_PLUGIN_EMERGENCY_TOKEN', $token);

        return $token;
    }
}
