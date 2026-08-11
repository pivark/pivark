<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

/** AdminSpaMenuRouteService 图标映射表（冗余审计 §3 外置） */
final class AdminSpaMenuRouteIconRegistry
{
    /** 侧栏分组标题 → lucide（无 route 时用，避免多组共用 file-text） */
    public static function groupTitleIcon(string $title): ?string
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }

        return match ($title) {
            '内容发布' => 'lucide:files',
            '产品中心' => 'lucide:package',
            '运营互动' => 'lucide:megaphone',
            '插件中心' => 'lucide:puzzle',
            '系统设置' => 'lucide:settings',
            '会员中心' => 'lucide:users',
            '用户权限' => 'lucide:shield',
            '运维工具' => 'lucide:wrench',
            '网站管理' => 'lucide:globe',
            '搜索收录' => 'lucide:scan-search',
            'SEO' => 'lucide:scan-search',
            default => null,
        };
    }

    public static function layuiIconRules(): array
    {
        return [
            'fa-users'         => 'lucide:users',
            'fa-user-circle'   => 'lucide:user-cog',
            'fa-user-o'        => 'lucide:user',
            'fa-user'          => 'lucide:user',
            'fa-id-card'       => 'lucide:id-card',
            'fa-folder-open'   => 'lucide:folder-open',
            'fa-folder-o'      => 'lucide:folder',
            'fa-file-text'     => 'lucide:file-text',
            'fa-file-o'        => 'lucide:file',
            'fa-file'          => 'lucide:file-text',
            'fa-line-chart'    => 'lucide:line-chart',
            'fa-bar-chart'     => 'lucide:bar-chart-2',
            'fa-picture-o'     => 'lucide:image',
            'fa-picture'       => 'lucide:image',
            'fa-envelope'      => 'lucide:mail',
            'fa-life-ring'     => 'lucide:life-buoy',
            'fa-tachometer'    => 'lucide:layout-dashboard',
            'fa-dashboard'     => 'lucide:layout-dashboard',
            'fa-home'          => 'lucide:layout-dashboard',
            'fa-globe'         => 'lucide:globe',
            'fa-tags'          => 'lucide:tags',
            'fa-plug'          => 'lucide:puzzle',
            'fa-puzzle-piece'  => 'lucide:puzzle',
            'fa-puzzle'        => 'lucide:puzzle',
            'fa-list-alt'         => 'lucide:clipboard-list',
            'fa-wpforms'          => 'lucide:clipboard-list',
            'fa-clipboard-check'  => 'lucide:clipboard-check',
            'fa-gears'            => 'lucide:settings-2',
            'fa-cog'              => 'lucide:settings',
            'fa-gear'             => 'lucide:settings',
            'fa-shield'           => 'lucide:shield',
            'fa-sitemap'          => 'lucide:network',
            'fa-link'             => 'lucide:link',
            'fa-history'          => 'lucide:history',
            'fa-database'         => 'lucide:database',
            'fa-clock'            => 'lucide:clock',
            'fa-star'             => 'lucide:star',
            'fa-list'             => 'lucide:list',
            'fa-bars'             => 'lucide:menu',
            'fa-pencil'           => 'lucide:pencil',
            'fa-edit'             => 'lucide:pencil',
            'fa-plus'             => 'lucide:plus',
            'fa-download'         => 'lucide:download',
            'fa-shopping-cart'    => 'lucide:shopping-cart',
            'fa-shopping-bag'     => 'lucide:shopping-bag',
            'fa-credit-card'      => 'lucide:credit-card',
            'fa-upload'           => 'lucide:upload',
            'fa-cloud-upload'     => 'lucide:cloud-upload',
            'fa-exchange'         => 'lucide:refresh-cw',
            'fa-archive'          => 'lucide:archive',
            'fa-wrench'           => 'lucide:wrench',
            'fa-bullhorn'         => 'lucide:megaphone',
            'fa-search'           => 'lucide:search',
            'fa-code'             => 'lucide:file-code',
            'fa-heart'            => 'lucide:heart',
            'fa-key'              => 'lucide:key-round',
            'fa-weixin'           => 'lucide:smartphone',
            'fa-cubes'            => 'lucide:package',
            'fa-cube'             => 'lucide:box',
            'fa-magic'            => 'lucide:sparkles',
            'fa-bolt'             => 'lucide:sparkles',
            'fa-phone'            => 'lucide:headphones',
            'fa-th-large'         => 'lucide:layout-grid',
            'fa-map-signs'        => 'lucide:map',
            'fa-building'         => 'lucide:briefcase',
            'fa-handshake'        => 'lucide:users',
            'fa-industry'         => 'lucide:briefcase',
            'fa-bell'             => 'lucide:bell',
            'fa-terminal'         => 'lucide:terminal',
            'fa-circle-o'         => 'lucide:circle',
            'fa-circle'           => 'lucide:circle',
        ];
    }

    /** @return array<string, string> */
    public static function routeExactIcons(): array
    {
        return [
            '/dashboard/welcome'                     => 'lucide:layout-dashboard',
            '/site/nav'                              => 'lucide:network',
            '/site/page'                             => 'lucide:layout-template',
            '/site/ads'                              => 'lucide:images',
            '/site/form/submissions/contact'         => 'lucide:mail',
            '/site/link'                             => 'lucide:link',
            '/site/domain'                           => 'lucide:globe-2',
            '/site/stats'                            => 'lucide:bar-chart-2',
            '/site/form'                             => 'lucide:clipboard-list',
            '/content/document'                      => 'lucide:file-text',
            '/content/document/create'               => 'lucide:plus',
            '/content/tag'                           => 'lucide:tags',
            '/content/tag-group'                     => 'lucide:folders',
            '/system/media'                          => 'lucide:image',
            '/system/enterprise-resource'            => 'lucide:folder-open',
            '/seo/url'                               => 'lucide:scan-search',
            '/seo/sitemap'                           => 'lucide:map',
            '/seo/robots'                            => 'lucide:bot',
            '/seo/static'                            => 'lucide:file-code',
            '/plugin/mine'                           => 'lucide:box',
            '/plugin/cloud'                          => 'lucide:cloud-upload',
            '/plugin/purchased'                      => 'lucide:shopping-bag',
            '/plugin/scaffold'                       => 'lucide:package-plus',
            '/plugin/workbench'                      => 'lucide:terminal',
            '/member/list'                           => 'lucide:user',
            '/member/level'                          => 'lucide:star',
            '/member/center/field'                   => 'lucide:text-cursor-input',
            '/member/center/config'                  => 'lucide:sliders-horizontal',
            '/member/center/points'                  => 'lucide:coins',
            '/member/center/consumption'             => 'lucide:receipt',
            '/member/center/orders'                  => 'lucide:banknote',
            '/member/center/balance'                 => 'lucide:wallet',
            '/member/center/recharge'                => 'lucide:credit-card',
            '/member/center/cancel'                  => 'lucide:user-x',
            '/system/user'                           => 'lucide:user-cog',
            '/system/role'                           => 'lucide:shield',
            '/system/config'                         => 'lucide:settings-2',
            '/system/menu'                           => 'lucide:menu',
            '/system/log'                            => 'lucide:history',
            '/system/backup'                         => 'lucide:database',
            '/system/sql-console'                    => 'lucide:file-code',
            '/system/data-retention'                 => 'lucide:archive',
            '/system/rate-limit'                     => 'lucide:gauge',
            '/system/cron'                           => 'lucide:clock',
            '/system/payment'                        => 'lucide:credit-card',
            '/system/search'                         => 'lucide:search',
            '/system/ai-config'                      => 'lucide:sparkles',
            '/system/float-contact'                  => 'lucide:headphones',
            '/site/favorite'                         => 'lucide:heart',
            '/product/item'                          => 'lucide:package',
            '/product/params'                        => 'lucide:list',
            '/product/settings'                      => 'lucide:settings-2',
            '/system/captcha'                        => 'lucide:shield-check',
            '/system/miniprogram/wechat'             => 'lucide:smartphone',
            '/system/miniprogram/decor'              => 'lucide:layout-grid',
            '/weapp/host/mp-wechat/guide'            => 'lucide:map',
            '/system/upgrade'                        => 'lucide:arrow-up-circle',
            '/system/login-notice'                   => 'lucide:bell',
            '/system/channels'                       => 'lucide:radio',
            '/site/cockpit'                          => 'lucide:gauge',
            '/content/tools/bulk-replace'            => 'lucide:refresh-cw',
            '/plugin/import'                         => 'lucide:upload',
        ];
    }

    /** @return array<string, string> pattern => lucide */
    public static function routePatternIcons(): array
    {
        return [
            '#/plugin/cloud#'         => 'lucide:cloud-upload',
            '#/plugin/purchased#'     => 'lucide:shopping-bag',
            '#/plugin/scaffold#'      => 'lucide:package-plus',
            '#/plugin/workbench#'     => 'lucide:terminal',
            '#/plugin#'               => 'lucide:puzzle',
            '#/accessstats|/stats#'   => 'lucide:bar-chart-2',
            '#/siteform|/form/index#' => 'lucide:clipboard-list',
            '#/sitedomain#'           => 'lucide:globe-2',
            '#/siteinquiry#'          => 'lucide:inbox',
            '#/payment#'              => 'lucide:credit-card',
            '#/search#'               => 'lucide:search',
            '#/captcha#'              => 'lucide:shield-check',
            '#/comment#'              => 'lucide:message-square',
            '#/doc_gallery#'              => 'lucide:images',
            '#/video#'                => 'lucide:clapperboard',
            '#/thumb#'                => 'lucide:image-down',
            '#/download#'             => 'lucide:download',
            '#/ask#'                  => 'lucide:help-circle',
            '#/talent#'               => 'lucide:users-round',
            '#/tender#'               => 'lucide:briefcase',
            '#/ai_config#'          => 'lucide:sparkles',
            '#/favorite#'             => 'lucide:heart',
            '#/float_contact#'        => 'lucide:headphones',
            '#/usage#'                => 'lucide:book-open',
            '#/guide#'                => 'lucide:book-marked',
            '#/resources#'            => 'lucide:folder-open',
            '#/weapp/#'               => 'lucide:puzzle',
            '#/list#'                 => 'lucide:list',
            '#/settings#'             => 'lucide:sliders-horizontal',
        ];
    }
}
