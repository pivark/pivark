<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\security;

use app\common\service\plugin\gateway\PluginGatewayCallerContext;
use app\common\support\ProjectPaths;

/** 第三方插件文件系统写路径白名单 */
final class PluginFileAccessGuard
{
    /**
     * @return list<string> 绝对目录（含尾斜杠）
     */
    public static function writableDirs(string $identifier): array
    {
        $identifier = trim($identifier);
        $root       = rtrim(str_replace('\\', '/', root_path()), '/') . '/';

        return [
            $root . 'weapp/' . $identifier . '/',
            $root . 'public/weapp/' . $identifier . '/',
            ProjectPaths::runtimeDir() . 'weapp/' . $identifier . '/',
        ];
    }

    public static function assertWritablePath(string $identifier, string $path): void
    {
        if (PluginThirdPartyPolicyService::isOfficialIdentifier($identifier)) {
            return;
        }
        $path = str_replace('\\', '/', $path);
        $real = realpath($path);
        if ($real === false) {
            $real = $path;
        }
        $real = rtrim(str_replace('\\', '/', $real), '/') . '/';
        foreach (self::writableDirs($identifier) as $dir) {
            $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
            if (str_starts_with($real, $dir)) {
                return;
            }
        }
        throw new \RuntimeException('第三方插件禁止写入路径：' . $path);
    }

    /** Gateway 写文件入口：按调用栈 caller 校验（生产 enforce 时） */
    public static function assertCallerWritablePath(string $path): void
    {
        if (!(bool) config('plugin.security.file_access_guard_enforce', false)) {
            return;
        }
        self::assertCallerPathAlways($path, false);
    }

    /**
     * Gateway 危险文件 API：第三方始终校验（不依赖 enforce 开关）；官方插件或不在 Gateway 调用栈内时不校验。
     * $allowHttpUrl=true 时允许 http(s) 远程读取（如插件 HTTP 客户端）。
     */
    public static function assertCallerPathAlways(string $path, bool $allowHttpUrl = false): void
    {
        $path = trim($path);
        if ($path === '') {
            throw new \RuntimeException('第三方插件禁止空路径');
        }
        if ($allowHttpUrl && preg_match('#^https?://#i', $path) === 1) {
            return;
        }
        $caller = PluginGatewayCallerContext::currentIdentifier();
        if ($caller === null || $caller === '') {
            return;
        }
        if (PluginThirdPartyPolicyService::isOfficialIdentifier($caller)) {
            return;
        }
        self::assertWritablePath($caller, $path);
    }
}
