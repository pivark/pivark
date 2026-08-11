<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\service\auth\CaptchaService;
use app\common\service\auth\CsrfService;
use app\common\service\config\ConfigService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\service\release\CoreUpdateApplyService;
use app\common\service\site\SiteBrandService;
use app\common\service\user\UserPasswordService;
use app\common\support\SiteUrl;

/** Vue 后台 SPA 启动 / UserInfo payload（从 Spa 控制器 batch 8 下沉） */
class AdminSpaBootstrapService
{

    public const SESSION_TOKEN = 'pivark-session';

    /**
     * @param array<string, mixed>|null $admin
     * @return array<string, mixed>
     */
    public function bootstrapPayload(?array $admin): array
    {
        $captcha  = app(CaptchaService::class)->forScene('admin');
        $loggedIn = is_array($admin) && !empty($admin['id']);
        $healNotice = '';
        if ($loggedIn) {
            $heal = app(CoreUpdateApplyService::class)->healStaleState();
            if ($heal->isOk() && !empty($heal->dataArray()['healed'])) {
                $healNotice = $heal->message() !== '' ? $heal->message() : '已自动清理异常维护状态';
            }
        }

        $navProfile = app(AdminNavProfileService::class);
        $userId     = is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;
        $navPersona = $loggedIn ? app(AdminNavPersonaService::class)->payload($userId) : null;

        return [
            'logged_in'               => $loggedIn,
            'accessToken'             => $loggedIn ? self::SESSION_TOKEN : '',
            'user'                    => $this->formatUser($admin),
            'nav_profile'             => $navProfile->payload(),
            'nav_persona'             => $navPersona,
            'csrf_token'              => app(CsrfService::class)->token(),
            'csrf_field'              => app(CsrfService::class)->fieldName(),
            'captcha'                 => [
                'enabled' => $captcha->isEnabled(),
                'url'     => SiteUrl::adminRestApiPath('auth/captcha'),
            ],
            'site_name'               => $this->plainSiteName(),
            'site_home'               => SiteUrl::configuredPublicHome(),
            'site_brand'              => app(SiteBrandService::class)->adminPayload(),
            'admin_home'              => SiteUrl::adminHome(),
            'admin_spa_home'          => SiteUrl::adminSpa(),
            'core_update'             => null,
            'maintenance_heal_notice' => $healNotice,
            'plugin_boot_failures'    => $loggedIn ? app(PluginService::class)->bootFailures() : [],
            /** 发行受限宿主插件后台前缀（SPA resolveAdminRestUrl） */
            'host_admin_route_prefixes' => PluginDistributionPolicy::identifiers(),
        ];
    }

    /**
     * @param array<string, mixed>|null $admin
     * @return array<string, mixed>|null
     */
    public function formatUser(?array $admin, bool $full = false): ?array
    {
        if (!is_array($admin) || empty($admin['id'])) {
            return null;
        }
        $roles = $admin['role_codes'] ?? [];
        if (!is_array($roles)) {
            $roles = [];
        }
        if (!empty($admin['is_super']) && !in_array('super_admin', $roles, true)) {
            $roles[] = 'super_admin';
        }
        $userId = (int) $admin['id'];
        $user = [
            'userId'             => (string) $userId,
            'username'           => (string) ($admin['username'] ?? ''),
            'realName'           => (string) ($admin['realname'] ?? $admin['username'] ?? ''),
            'avatar'             => '',
            'roles'              => array_values(array_unique(array_map('strval', $roles))),
            'navPersona'         => app(AdminNavPersonaService::class)->resolve($userId),
            'homePath'           => app(AdminNavProfileService::class)->defaultHomePath(),
            'mustChangePassword' => !empty($admin['must_change_password'])
                || app(UserPasswordService::class)->mustChange((int) $admin['id']),
            'totpEnabled'        => app(AdminTotpService::class)->isEnabled((int) $admin['id']),
        ];
        if ($full) {
            $user['desc']  = !empty($admin['is_super']) ? '超级管理员' : '管理员';
            $user['token'] = self::SESSION_TOKEN;
        }

        return $user;
    }

    private function plainSiteName(): string
    {
        $name = trim(strip_tags((string) app(ConfigService::class)->get('site_name', '元舟 PivArk')));

        return $name !== '' ? $name : '元舟 PivArk';
    }
}
