<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 安装向导规则（仅 /install 装机流程；装完可随 install/ 删除）
 *
 * 名单来自 ReleaseEditionConfig（按当前发行形态）。
 * 打包裁剪 → 开发仓 CommunityPackRules（仅打开源 zip 时用 Community 名无妨）。
 */
declare(strict_types=1);

namespace install\support;

use app\common\support\ReleaseEditionConfig;

final class InstallEditionRules
{
    /**
     * 安装向导必装插件（不展示在增强包勾选）
     *
     * @return list<string>
     */
    public static function installRequiredWeappIdentifiers(): array
    {
        return ReleaseEditionConfig::list('install_required_weapp');
    }

    /**
     * 宝塔「伪静态」粘贴用短片段（Nginx location 片段，非完整 server{}）。
     * $docRoot 须以 / 结尾的站点根绝对路径；空则退化为相对 alias（仍建议装完走软链自愈）。
     * 装完 finish 还会写入站点根 `_pv_baota_rewrite.conf` 与 `data/baota-rewrite.conf`（同源）。
     */
    public static function baotaRewriteSnippet(string $docRoot = ''): string
    {
        return \app\common\service\site\SiteUrlRewriteGuideService::baotaNginxSnippet($docRoot);
    }

    /**
     * @return array{title:string,hint:string,steps:list<string>}
     */
    public static function rewriteSetupGuideZh(string $type): array
    {
        return \app\common\service\site\SiteUrlRewriteGuideService::setupGuideZh($type);
    }

    /** 兼容旧调用；等价 rewriteSetupGuideZh('nginx')['steps'] */
    public static function baotaPasteStepsZh(): array
    {
        return self::rewriteSetupGuideZh('nginx')['steps'];
    }

    /** 安装完成后写入站点根：动态访问入口（未命中文件 → index.php） */
    public static function postInstallDynamicRootHtaccessBody(): string
    {
        return \app\common\service\site\SiteUrlRewriteGuideService::apacheRootHtaccessBody();
    }
}
