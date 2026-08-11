<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 核心在线升级：覆盖路径白名单与跳过规则（各发行形态共用内核）。
 *
 * 只描述「客户站升级时别动什么」；形态裁剪属于开发仓 PackRules。
 */
declare(strict_types=1);

namespace app\common\support;

final class CoreUpdatePathRules
{
    /** 是否跳过覆盖该相对路径 */
    public static function shouldSkipCoreUpdatePath(string $rel): bool
    {
        $rel = self::normalizeRel($rel);
        if ($rel === '') {
            return true;
        }
        // 客户站只有已构建的 admin dist，勿覆盖 Vue 源码树；物理入口除外
        if (str_starts_with($rel, 'admin/')) {
            if ($rel === 'admin/index.php'
                || $rel === 'admin/.htaccess'
                || str_starts_with($rel, 'admin/bootstrap/')
            ) {
                return false;
            }

            return true;
        }
        // 站点本地状态
        if ($rel === '.env' || str_starts_with($rel, 'data/') || str_starts_with($rel, 'public/uploads/')) {
            return true;
        }
        // 迁移 PHP 禁止落入客户 app/（dig 真源在 digtools；升级仅工作区执行）
        if ($rel === 'app/database/migrations' || str_starts_with($rel, 'app/database/migrations/')) {
            return true;
        }
        // config 仅白名单可覆盖
        if (str_starts_with($rel, 'config/')) {
            return !in_array($rel, self::coreUpdateConfigFiles(), true);
        }

        return false;
    }

    /** @return list<string> */
    public static function coreUpdateApplyPrefixes(): array
    {
        return [
            'app/',
            'public/static/admin/dist/',
            // 主题已发布 CSS/图：门户联系页等依赖 public 而非仅 template 源码
            'public/static/theme/',
            'template/default/',
            'template/member/',
            'vendor/',
            'bootstrap/',
            'index.php',
            // 不覆盖 .htaccess：发行包根是安装占位（无 Rewrite），盖掉后客户站 /admin·API 全 404
        ];
    }

    /**
     * 覆盖后按包内文件集「整树对齐」的前缀：目标站多出的文件删除（典型：admin dist 哈希垃圾）。
     * 仅目录前缀；永不包含 data/、uploads/、weapp 客户插件。
     *
     * @return list<string>
     */
    public static function coreUpdateReplaceTreePrefixes(): array
    {
        return [
            'public/static/admin/dist/',
        ];
    }

    /**
     * 显式删除相对路径（文件或目录）。用于已退役、且不在新包中的残留。
     * 路径相对站点根；禁止 data/、public/uploads/、.env。
     *
     * @return list<string>
     */
    public static function coreUpdateDeletePaths(): array
    {
        return [
            // 历史错误落盘：客户站不得保留可执行 migrate 树（木马伪装面）
            'app/database/migrations',
            // HTTP 真入口已迁 bootstrap/web_entry.php；禁根目录旧文件残留
            'web_entry.php',
        ];
    }

    /** @return list<string> */
    public static function coreUpdateConfigFiles(): array
    {
        return [
            'config/app.php',
            'config/database.php',
            'config/pivark.php',
            'config/cache.php',
            'config/session.php',
            'config/cookie.php',
            'config/log.php',
            'config/view.php',
            'config/upload.php',
            'config/api.php',
            'config/audit.php',
            'config/captcha.php',
            'config/cross.php',
            'config/admin.php',
            'config/kernel.php',
            'config/plugin.php',
            'config/enterprise.php',
            'config/release.php',
            'config/security.php',
            'config/di.php',
            'config/miniprogram_channels.php',
        ];
    }

    private static function normalizeRel(string $rel): string
    {
        return trim(str_replace('\\', '/', $rel), '/');
    }
}
