<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\service\admin\AdminSpaExplicitRouteRegistry;
use app\common\service\product\ProductCenterGateService;
use app\common\service\menu\MenuService;
use app\common\service\plugin\weapp\PluginNav;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\weapp\WeappAdminUiService;
use app\common\service\plugin\WeappContext;

final class PivarkVueRoute
{
    private const PLUGIN_HUB_ACTIVE = '/plugin/mine';
    /** 系统设置页内 Tab · 顶栏合并为同一标签（与 inferTabMergeGroup system-settings 对齐） */
    private const SYSTEM_SETTINGS_TAB_META = [
        'tabMergeGroup' => 'system-settings',
        'tabMergeTitle' => '系统设置',
    ];
    /** 运营互动页内 Tab · 顶栏合并 */
    private const OPS_INTERACTION_TAB_META = [
        'tabMergeGroup' => 'ops-interaction',
        'tabMergeTitle' => '运营互动',
    ];
    /** 搜索收录页内 Tab · 顶栏合并 */
    private const SEO_TAB_META = [
        'tabMergeGroup' => 'seo',
        'tabMergeTitle' => '搜索收录',
    ];
    /** @var list<string> */
    private const SKIP = [
        '/admin',
        '/admin/index',
        '/admin/index/index',
        '/admin/index/welcome',
        '/admin/auth/login',
        '/admin/login/index',
    ];

    /** @var array<string, array<string, mixed>> */
    private const EXPLICIT = [

        '/dashboard/welcome' => [
            'name'      => 'DashboardWelcome',
            'path'      => '/dashboard/welcome',
            'component' => '/dashboard/welcome/index',
            'meta'      => ['title' => '工作台'],
        ],
        '/product/item' => [
            'name'      => 'ProductItem',
            'path'      => '/product/item',
            'component' => '/product/item/index',
            'meta'      => ['title' => '品项列表', 'tabMergeGroup' => 'product-center', 'tabMergeTitle' => '产品中心'],
        ],
        '/content/document' => [
            'name'      => 'ContentDocument',
            'path'      => '/content/document',
            'component' => '/content/document/index',
        ],
        '/content/tag' => [
            'name'      => 'ContentTag',
            'path'      => '/content/tag',
            'component' => '/content/tag/index',
        ],
        '/content/tag-group' => [
            'name'      => 'ContentTagGroup',
            'path'      => '/content/tag-group',
            'component' => '/content/tag-group/index',
        ],
        '/system/user' => [
            'name'      => 'SystemUser',
            'path'      => '/system/user',
            'component' => '/system/user/index',
        ],
        '/system/role' => [
            'name'      => 'SystemRole',
            'path'      => '/system/role',
            'component' => '/system/role/index',
        ],
        '/system/log' => [
            'name'      => 'SystemLog',
            'path'      => '/system/log',
            'component' => '/system/log/index',
            'meta'      => ['title' => '操作日志', 'tabMergeGroup' => 'ops-tools', 'tabMergeTitle' => '运维工具'],
        ],
        '/site/domain' => [
            'name'      => 'SiteDomain',
            'path'      => '/site/domain',
            'component' => '/site/domain/index',
            'meta'      => self::SYSTEM_SETTINGS_TAB_META + ['title' => '多域名分站'],
        ],
        '/site/link' => [
            'name'      => 'SiteLink',
            'path'      => '/site/link',
            'component' => '/site/link/index',
            'meta'      => self::OPS_INTERACTION_TAB_META + ['title' => '友情链接'],
        ],
        '/system/config' => [
            'name'      => 'SystemConfig',
            'path'      => '/system/config',
            'component' => '/system/config/index',
            'meta'      => self::SYSTEM_SETTINGS_TAB_META + ['title' => '系统配置'],
        ],
        '/system/media' => [
            'name'      => 'SystemMedia',
            'path'      => '/system/media',
            'component' => '/system/media/index',
            'meta'      => ['title' => '素材库'],
        ],
        '/system/enterprise-resource' => [
            'name'      => 'SystemEnterpriseResource',
            'path'      => '/system/enterprise-resource',
            'component' => '/system/enterprise-resource/index',
            'meta'      => ['title' => '企业资源库'],
        ],
        '/system/backup' => [
            'name'      => 'SystemBackup',
            'path'      => '/system/backup',
            'component' => '/system/backup/index',
            'meta'      => ['title' => '数据备份', 'tabMergeGroup' => 'ops-tools', 'tabMergeTitle' => '运维工具'],
        ],
        '/system/sql-console' => [
            'name'      => 'SystemSqlConsole',
            'path'      => '/system/sql-console',
            'component' => '/system/sql-console/index',
            'meta'      => [
                'title'         => 'SQL 控制台',
                'hideInMenu'    => true,
                // 侧栏隐藏：与搜索词日志同款，锚定运维工具可见子路由
                'activePath'    => '/system/cron',
                'tabMergeGroup' => 'ops-tools',
                'tabMergeTitle' => '运维工具',
            ],
        ],
        '/system/data-retention' => [
            'name'      => 'SystemDataRetention',
            'path'      => '/system/data-retention',
            'component' => '/system/data-retention/index',
            'meta'      => ['title' => '数据保留策略', 'tabMergeGroup' => 'ops-tools', 'tabMergeTitle' => '运维工具'],
        ],
        '/system/rate-limit' => [
            'name'      => 'SystemRateLimit',
            'path'      => '/system/rate-limit',
            'component' => '/system/rate-limit/index',
            'meta'      => ['title' => '限流策略', 'tabMergeGroup' => 'ops-tools', 'tabMergeTitle' => '运维工具'],
        ],
        '/system/upgrade' => [
            'name'      => 'SystemUpgrade',
            'path'      => '/system/upgrade',
            'component' => '/system/upgrade/index',
            'meta'      => ['title' => '系统升级', 'tabMergeGroup' => 'ops-tools', 'tabMergeTitle' => '运维工具'],
        ],
        '/system/cron' => [
            'name'      => 'SystemCron',
            'path'      => '/system/cron',
            'component' => '/system/cron/index',
            'meta'      => ['title' => '定时任务', 'tabMergeGroup' => 'ops-tools', 'tabMergeTitle' => '运维工具'],
        ],
        '/system/menu' => [
            'name'      => 'SystemMenu',
            'path'      => '/system/menu',
            'component' => '/system/menu/index',
            'meta'      => self::SYSTEM_SETTINGS_TAB_META + [
                'hideInMenu' => true,
                'hideInTab'  => true,
                'title'      => '系统菜单一览',
            ],
        ],
        '/site/nav' => [
            'name'      => 'SiteNav',
            'path'      => '/site/nav',
            'component' => '/site/nav/index',
        ],
        '/site/ads' => [
            'name'      => 'SiteAds',
            'path'      => '/site/ads',
            'component' => '/site/slide/index',
            'meta'      => self::OPS_INTERACTION_TAB_META + ['title' => '广告管理'],
        ],
        '/site/page' => [
            'name'      => 'SitePage',
            'path'      => '/site/page',
            'component' => '/site/page/index',
        ],
        '/site/stats' => [
            'name'      => 'AccessStats',
            'path'      => '/site/stats',
            'component' => '/site/stats/index',
            'meta'      => self::OPS_INTERACTION_TAB_META + ['title' => '访问统计'],
        ],
        '/site/cockpit' => [
            'name'      => 'SiteCockpit',
            'path'      => '/site/cockpit',
            'component' => '/site/cockpit/index',
            'meta'      => self::OPS_INTERACTION_TAB_META + ['title' => '经营看板'],
        ],
        '/content/tools/bulk-replace' => [
            'name'      => 'ContentBulkReplace',
            'path'      => '/content/tools/bulk-replace',
            'component' => '/content/tools/bulk-replace',
            'meta'      => ['title' => '批量替换', 'tabMergeGroup' => 'ops-tools', 'tabMergeTitle' => '运维工具'],
        ],
        '/content/blocks' => [
            'name'      => 'ContentDocumentBlock',
            'path'      => '/content/blocks',
            'component' => '/content/blocks/index',
            'meta'      => [
                'title'      => '文档块',
                'hideInMenu' => true,
                'hideInTab'  => true,
            ],
        ],
        '/site/form' => [
            'name'      => 'SiteForm',
            'path'      => '/site/form',
            'component' => '/site/form/index',
            'meta'      => self::OPS_INTERACTION_TAB_META + ['title' => '自定表单'],
        ],
        '/site/form/guide' => [
            'name'      => 'SiteFormGuide',
            'path'      => '/site/form/guide',
            'component' => '/weapp/guide/index',
            'meta'      => self::OPS_INTERACTION_TAB_META + ['pivarkKernelModule' => 'form', 'title' => '功能介绍'],
        ],
        '/site/form/usage' => [
            'name'      => 'SiteFormUsage',
            'path'      => '/site/form/usage',
            'component' => '/weapp/usage/index',
            'meta'      => self::OPS_INTERACTION_TAB_META + ['pivarkKernelModule' => 'form', 'title' => '前台调用说明'],
        ],
        '/member/list' => [
            'name'      => 'MemberList',
            'path'      => '/member/list',
            'component' => '/member/list/index',
        ],
        '/member/level' => [
            'name'      => 'MemberLevel',
            'path'      => '/member/level',
            'component' => '/member/level/index',
        ],
        '/member/center/field' => [
            'name'      => 'MemberCenterField',
            'path'      => '/member/center/field',
            'component' => '/member/center/field/index',
        ],
        '/member/center/config' => [
            'name'      => 'MemberCenterConfig',
            'path'      => '/member/center/config',
            'component' => '/member/center/config',
        ],
        '/member/center/points' => [
            'name'      => 'MemberCenterPoints',
            'path'      => '/member/center/points',
            'component' => '/member/center/points/index',
        ],
        '/member/center/balance' => [
            'name'      => 'MemberCenterBalance',
            'path'      => '/member/center/balance',
            'component' => '/member/center/balance/index',
        ],
        '/member/center/consumption' => [
            'name'      => 'MemberCenterConsumption',
            'path'      => '/member/center/consumption',
            'component' => '/member/center/consumption/index',
        ],
        '/member/center/orders' => [
            'name'      => 'MemberCenterPaymentOrders',
            'path'      => '/member/center/orders',
            'component' => '/member/center/orders/index',
        ],
        '/member/center/recharge' => [
            'name'      => 'MemberCenterRecharge',
            'path'      => '/member/center/recharge',
            'component' => '/member/center/recharge/index',
            'meta'      => ['pivarkModule' => 'member_center_recharge'],
        ],
        '/member/center/cancel' => [
            'name'      => 'MemberCenterCancel',
            'path'      => '/member/center/cancel',
            'component' => '/member/center/cancel',
        ],
        '/system/channels' => [
            'name'      => 'SystemChannelsConfig',
            'path'      => '/system/channels',
            'component' => '/system/channels/index',
            'meta'      => self::SYSTEM_SETTINGS_TAB_META + ['title' => '接口与提醒'],
        ],
        '/system/payment' => [
            'name'      => 'SystemPaymentConfig',
            'path'      => '/system/payment',
            'redirect'  => '/system/channels?pane=payment',
            'meta'      => self::SYSTEM_SETTINGS_TAB_META + [
                'title'      => '支付接口',
                'hideInMenu' => true,
                'activePath' => '/system/channels',
            ],
        ],
        '/system/mail' => [
            'name'      => 'SystemMailConfig',
            'path'      => '/system/mail',
            'redirect'  => '/system/channels?pane=mail',
            'meta'      => self::SYSTEM_SETTINGS_TAB_META + [
                'title'      => '邮件接口',
                'hideInMenu' => true,
                'activePath' => '/system/channels',
            ],
        ],
        '/system/sms' => [
            'name'      => 'SystemSmsConfig',
            'path'      => '/system/sms',
            'redirect'  => '/system/channels?pane=sms',
            'meta'      => self::SYSTEM_SETTINGS_TAB_META + [
                'title'      => '短信接口',
                'hideInMenu' => true,
                'activePath' => '/system/channels',
            ],
        ],
        '/system/search' => [
            'name'      => 'SystemSearchConfig',
            'path'      => '/system/search',
            'component' => '/system/search/index',
            'meta'      => self::SYSTEM_SETTINGS_TAB_META + ['title' => '搜索管理'],
        ],
        '/system/search/query-log' => [
            'name'      => 'SystemSearchQueryLog',
            'path'      => '/system/search/query-log',
            'component' => '/system/search/query-log',
            'meta'      => [
                'title'         => '搜索词日志',
                'hideInMenu'    => true,
                'tabMergeGroup' => 'system-settings',
                'tabMergeTitle' => '系统设置',
            ],
        ],
        '/system/captcha' => [
            'name'      => 'SystemCaptchaConfig',
            'path'      => '/system/captcha',
            'component' => '/system/captcha/index',
            'meta'      => self::SYSTEM_SETTINGS_TAB_META + ['title' => '验证码'],
        ],
        '/system/login-notice' => [
            'name'      => 'SystemLoginNoticeConfig',
            'path'      => '/system/login-notice',
            'redirect'  => '/system/channels?pane=notice',
            'meta'      => self::SYSTEM_SETTINGS_TAB_META + [
                'title'      => '提醒设置',
                'hideInMenu' => true,
                'activePath' => '/system/channels',
            ],
        ],
        '/system/miniprogram/wechat' => [
            'name'      => 'SystemMiniprogramWechat',
            'path'      => '/system/miniprogram/wechat',
            'component' => '/system/miniprogram/wechat',
            'meta'      => [
                'title'      => '微信渠道 · 联调与凭据',
                'hideInMenu' => true,
            ],
        ],
        '/system/miniprogram/decor' => [
            'name'      => 'SystemMiniprogramDecor',
            'path'      => '/system/miniprogram/decor',
            'component' => '/system/miniprogram/decor',
            'meta'      => ['title' => '页面装修'],
        ],
        '/plugin/mine' => [
            'name'      => 'PluginMine',
            'path'      => '/plugin/mine',
            'component' => '/plugin/mine/index',
            'meta'      => ['title' => '已安装插件', 'tabMergeGroup' => 'plugin-hub', 'tabMergeTitle' => '插件中心'],
        ],
        '/plugin/cloud' => [
            'name'      => 'PluginCloud',
            'path'      => '/plugin/cloud',
            'component' => '/plugin/cloud/index',
            'meta'      => ['title' => '应用市场', 'tabMergeGroup' => 'plugin-hub', 'tabMergeTitle' => '插件中心'],
        ],
        '/plugin/import' => [
            'name'      => 'PluginImport',
            'path'      => '/plugin/import',
            'component' => '/plugin/workbench/index',
            'meta'      => ['title' => '开发者工作台', 'workbenchTab' => 'import', 'tabMergeGroup' => 'plugin-hub', 'tabMergeTitle' => '插件中心'],
        ],
        '/plugin/scaffold' => [
            'name'      => 'PluginScaffold',
            'path'      => '/plugin/scaffold',
            'component' => '/plugin/workbench/index',
            'meta'      => ['title' => '开发者工作台', 'workbenchTab' => 'scaffold', 'tabMergeGroup' => 'plugin-hub', 'tabMergeTitle' => '插件中心'],
        ],
        '/plugin/workbench' => [
            'name'      => 'PluginWorkbench',
            'path'      => '/plugin/workbench',
            'component' => '/plugin/workbench/index',
            'meta'      => ['title' => '开发者工作台', 'tabMergeGroup' => 'plugin-hub', 'tabMergeTitle' => '插件中心'],
        ],
        '/plugin/purchased' => [
            'name'      => 'PluginPurchased',
            'path'      => '/plugin/purchased',
            'component' => '/plugin/purchased/index',
            'meta'      => ['title' => '已购买插件', 'tabMergeGroup' => 'plugin-hub', 'tabMergeTitle' => '插件中心'],
        ],
        '/plugin/commerce' => [
            'name'               => 'PluginCommerceLegacy',
            'path'               => '/plugin/commerce',
            'redirect'           => '/plugin/mine',
            'redirectCapability' => AdminSpaExplicitRouteRegistry::CAP_PLUGIN_COMMERCE_ADMIN,
            'meta'               => ['title' => '插件订单与入账', 'hideInMenu' => true],
        ],
        '/seo/url' => [
            'name'      => 'SeoUrl',
            'path'      => '/seo/url',
            'component' => '/seo/url/index',
            'meta'      => self::SEO_TAB_META + ['title' => 'URL配置'],
        ],
        '/seo/static' => [
            'name'      => 'SeoStatic',
            'path'      => '/seo/static',
            'component' => '/seo/static/index',
            'meta'      => self::SEO_TAB_META + ['title' => 'HTML生成'],
        ],
        '/seo/sitemap' => [
            'name'      => 'SeoSitemap',
            'path'      => '/seo/sitemap',
            'component' => '/seo/sitemap/index',
            'meta'      => self::SEO_TAB_META + ['title' => 'Sitemap'],
        ],
        '/seo/robots' => [
            'name'      => 'SeoRobots',
            'path'      => '/seo/robots',
            'component' => '/seo/robots/index',
            'meta'      => self::SEO_TAB_META + ['title' => 'Robots'],
        ],
        '/system/ai-config' => [
            'name'      => 'SystemAiConfig',
            'path'      => '/system/ai-config',
            'component' => '/system/ai-config/index',
            'meta'      => self::SYSTEM_SETTINGS_TAB_META + ['title' => 'AI 配置'],
        ],
        '/system/ai-config/guide' => [
            'name'      => 'SystemAiConfigGuide',
            'path'      => '/system/ai-config/guide',
            'component' => '/weapp/guide/index',
            'meta'      => ['pivarkKernelModule' => 'ai_config', 'title' => '功能介绍'],
        ],
        '/system/ai-config/usage' => [
            'name'      => 'SystemAiConfigUsage',
            'path'      => '/system/ai-config/usage',
            'component' => '/weapp/usage/index',
            'meta'      => ['pivarkKernelModule' => 'ai_config', 'title' => '前台调用说明'],
        ],
        '/system/float-contact/guide' => [
            'name'      => 'SystemFloatContactGuide',
            'path'      => '/system/float-contact/guide',
            'component' => '/weapp/guide/index',
            'meta'      => self::OPS_INTERACTION_TAB_META + ['pivarkKernelModule' => 'float_contact', 'title' => '功能介绍'],
        ],
        '/system/float-contact/usage' => [
            'name'      => 'SystemFloatContactUsage',
            'path'      => '/system/float-contact/usage',
            'component' => '/weapp/usage/index',
            'meta'      => self::OPS_INTERACTION_TAB_META + ['pivarkKernelModule' => 'float_contact', 'title' => '前台调用说明'],
        ],
        '/product/params' => [
            'name'      => 'ProductCenterParams',
            'path'      => '/product/params',
            'component' => '/product/params',
            'meta'      => ['title' => '产品参数', 'tabMergeGroup' => 'product-center', 'tabMergeTitle' => '产品中心'],
        ],
        '/product/settings' => [
            'name'      => 'ProductCenterSettings',
            'path'      => '/product/settings',
            'component' => '/product/settings',
            'meta'      => ['title' => '基本设置', 'tabMergeGroup' => 'product-center', 'tabMergeTitle' => '产品中心'],
        ],
        '/site/favorite' => [
            'name'      => 'SiteFavorite',
            'path'      => '/site/favorite',
            'component' => '/site/favorite/index',
            'meta'      => ['title' => '点赞与收藏'],
        ],
        '/system/float-contact' => [
            'name'      => 'SystemFloatContactSettings',
            'path'      => '/system/float-contact',
            'component' => '/system/float-contact/index',
            'meta'      => self::OPS_INTERACTION_TAB_META + ['title' => '悬浮联系'],
        ],
        '/site/form/edit/:id' => [
            'name'      => 'SiteFormEdit',
            'path'      => '/site/form/edit/:id?',
            'component' => '/site/form/edit',
            'meta'      => self::OPS_INTERACTION_TAB_META + [
                'activePath'      => '/site/form',
                'maxNumOfOpenTab' => 1,
                'title'           => '编辑表单',
            ],
        ],
    
    ];

    /** @var array<string, array<string, mixed>> */
    private const MODULES = [
        'member_center_field' => [
            'api'        => 'member-center/fields',
            'delete'     => '/member_center/fieldDelete',
            'createPath' => '/member/center/field/form',
            'editPath'   => '/member/center/field/form',
        ],
        'member_center_recharge' => [
            'api'        => 'member-center/recharge',
            'delete'     => '/member_center/rechargeDelete',
            'sort'       => '/member_center/rechargeSort',
            'createPath' => '/member/center/recharge/form',
            'editPath'   => '/member/center/recharge/form',
        ],
    ];

    /** 表单/编辑类隐式路由：同一路由名只保留一个标签页 */
    private const FORM_TAB_META = [
        'maxNumOfOpenTab' => 1,
    ];

    /** @var array<string, array<string, mixed>> */
    private const FORM_ROUTES = [
        'document' => [
            'create' => [
                'name'      => 'ContentDocumentCreate',
                'path'      => '/content/document/create',
                'component' => '/content/document/form',
                'active'    => '/content/document',
                'title'     => '发布文档',
            ],
            'edit' => [
                'name'      => 'ContentDocumentEdit',
                'path'      => '/content/document/edit/:id',
                'component' => '/content/document/form',
                'active'    => '/content/document',
                'title'     => '编辑文档',
            ],
        ],
        'user' => [
            'create' => [
                'name'      => 'SystemUserCreate',
                'path'      => '/system/user/create',
                'component' => '/system/user/form',
                'active'    => '/system/user',
                'title'     => '新增用户',
            ],
            'edit' => [
                'name'      => 'SystemUserEdit',
                'path'      => '/system/user/edit/:id',
                'component' => '/system/user/form',
                'active'    => '/system/user',
                'title'     => '编辑用户',
            ],
        ],
        'role' => [
            'create' => [
                'name'      => 'SystemRoleCreate',
                'path'      => '/system/role/create',
                'component' => '/system/role/form',
                'active'    => '/system/role',
                'title'     => '新增角色',
            ],
            'edit' => [
                'name'      => 'SystemRoleEdit',
                'path'      => '/system/role/edit/:id',
                'component' => '/system/role/form',
                'active'    => '/system/role',
                'title'     => '编辑角色',
            ],
        ],
        'member' => [
            'create' => [
                'name'      => 'MemberCreate',
                'path'      => '/member/list/create',
                'component' => '/member/list/form',
                'active'    => '/member/list',
                'title'     => '新增会员',
            ],
            'edit' => [
                'name'      => 'MemberEdit',
                'path'      => '/member/list/edit/:id',
                'component' => '/member/list/form',
                'active'    => '/member/list',
                'title'     => '编辑会员',
            ],
        ],
        'member_level' => [
            'create' => [
                'name'      => 'MemberLevelCreate',
                'path'      => '/member/level/create',
                'component' => '/member/level/form',
                'active'    => '/member/level',
                'title'     => '新增会员级别',
            ],
            'edit' => [
                'name'      => 'MemberLevelEdit',
                'path'      => '/member/level/edit/:id',
                'component' => '/member/level/form',
                'active'    => '/member/level',
                'title'     => '编辑会员级别',
            ],
        ],
        'tag' => [
            'create' => [
                'name'      => 'ContentTagCreate',
                'path'      => '/content/tag/create',
                'component' => '/content/tag/form',
                'active'    => '/content/tag',
                'title'     => '新增标签',
            ],
            'edit' => [
                'name'      => 'ContentTagEdit',
                'path'      => '/content/tag/edit/:id',
                'component' => '/content/tag/form',
                'active'    => '/content/tag',
                'title'     => '编辑标签',
            ],
        ],
        'sitepage' => [
            'form' => [
                'name'      => 'SitePageForm',
                'path'      => '/site/page/form/:id?',
                'component' => '/site/page/form',
                'active'    => '/site/page',
                'title'     => '编辑单页',
            ],
        ],
        'siteform' => [
            'form' => [
                'name'      => 'SiteFormEdit',
                'path'      => '/site/form/edit/:id?',
                'component' => '/site/form/edit',
                'active'    => '/site/form',
                'title'     => '编辑表单',
            ],
            'submissions' => [
                'name'      => 'SiteFormSubmissions',
                'path'      => '/site/form/submissions/:id',
                'component' => '/site/form/submissions',
                'active'    => '/site/form',
                'title'     => '提交记录',
            ],
        ],
        'menu' => [
            'create' => [
                'name'      => 'SystemMenuCreate',
                'path'      => '/system/menu/create',
                'component' => '/system/menu/form',
                'active'    => '/system/menu',
                'title'     => '新增菜单',
            ],
            'edit' => [
                'name'      => 'SystemMenuEdit',
                'path'      => '/system/menu/edit/:id',
                'component' => '/system/menu/form',
                'active'    => '/system/menu',
                'title'     => '编辑菜单',
            ],
        ],
        'member_center' => [
            'fieldform' => [
                'name'      => 'MemberCenterFieldForm',
                'path'      => '/member/center/field/form/:id?',
                'component' => '/member/center/field-form',
                'active'    => '/member/center/field',
                'title'     => '会员字段',
            ],
            'rechargeform' => [
                'name'      => 'MemberCenterRechargeForm',
                'path'      => '/member/center/recharge/form/:id?',
                'component' => '/member/center/recharge-form',
                'active'    => '/member/center/recharge',
                'title'     => '充值套餐',
            ],
        ],
    ];

    public static function normalizeHref(string $href): string
    {
        $href = trim($href);
        if (($pos = strpos($href, '?')) !== false) {
            $href = substr($href, 0, $pos);
        }

        return strtolower(rtrim($href, '/'));
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @return array<string, true>
     */
    private static function collectRouteNames(array $routes): array
    {
        $names = [];
        foreach ($routes as $row) {
            $n = (string) ($row['name'] ?? '');
            if ($n !== '') {
                $names[$n] = true;
            }
            $children = $row['children'] ?? null;
            if (is_array($children) && $children !== []) {
                foreach (self::collectRouteNames($children) as $childName => $_) {
                    $names[$childName] = true;
                }
            }
        }

        return $names;
    }

    /**
     * 补全菜单树未带出的显式路由，并兼容旧版菜单 href（camelCase）。
     *
     * @param list<array<string, mixed>> $routes
     * @return list<array<string, mixed>>
     */
    public static function appendMissingExplicitRoutes(array $routes): array
    {
        $names = self::collectRouteNames($routes);

        $extras = [];

        if (!isset($names['SiteSlideLegacy'])) {
            $extras[] = [
                'name'     => 'SiteSlideLegacy',
                'path'     => '/site/slide',
                'redirect' => '/site/ads',
                'meta'     => [
                    'hideInMenu' => true,
                    'hideInTab'  => true,
                    'title'      => '广告管理',
                ],
            ];
        }

        // 旧 /plugin/commerce → 宿主运营账房 SPA（EXPLICIT redirect；不在 PluginNav）
        if (!isset($names['PluginCommerceLegacy'])) {
            $native = self::resolve('/plugin/commerce', '插件订单与入账');
            if (is_array($native)) {
                $meta = is_array($native['meta'] ?? null) ? $native['meta'] : [];
                $meta['hideInMenu'] = true;
                $meta['hideInTab']  = true;
                $native['meta']     = $meta;
                $extras[]           = $native;
                $names['PluginCommerceLegacy'] = true;
            }
        }

        if (!isset($names['ContentDocumentBlock'])) {
            $native = self::resolve('/content/blocks', '文档块');
            if (is_array($native)) {
                $meta = is_array($native['meta'] ?? null) ? $native['meta'] : [];
                $meta['hideInMenu'] = true;
                $meta['hideInTab']  = true;
                $native['meta']     = $meta;
                $extras[]           = $native;
                $names['ContentDocumentBlock'] = true;
            }
        }

        // SQL 控制台：Ops Tab 进入，无侧栏行；须 activePath 才能 mixed-nav 拆侧栏
        if (!isset($names['SystemSqlConsole'])) {
            $native = self::resolve('/system/sql-console', 'SQL 控制台');
            if (is_array($native)) {
                $meta = is_array($native['meta'] ?? null) ? $native['meta'] : [];
                $meta['hideInMenu'] = true;
                $meta['activePath'] = '/system/cron';
                $native['meta'] = $meta;
                $extras[] = $native;
                $names['SystemSqlConsole'] = true;
            }
        }

        // 产品参数：品项 attrs SSOT；路由须注册，但侧栏仅由 DB 菜单 + 产品中心门禁决定
        if (!isset($names['ProductCenterParams'])) {
            $native = self::resolve('/product/params', '产品参数');
            if (is_array($native)) {
                $meta = is_array($native['meta'] ?? null) ? $native['meta'] : [];
                $meta['hideInMenu'] = true;
                if (!app(ProductCenterGateService::class)->allowsAdmin()) {
                    $meta['hideInTab'] = true;
                }
                $native['meta']     = $meta;
                $extras[]           = $native;
                $names['ProductCenterParams'] = true;
            }
        }

        // 系统菜单一览：系统设置 Tab 可达，侧栏无独立行
        if (!isset($names['SystemMenu'])) {
            $native = self::resolve('/system/menu', '系统菜单一览');
            if (is_array($native)) {
                $meta = is_array($native['meta'] ?? null) ? $native['meta'] : [];
                $meta['hideInMenu'] = true;
                $meta['hideInTab']  = true;
                $native['meta']     = $meta;
                $extras[]           = $native;
                $names['SystemMenu'] = true;
            }
        }

        // 搜索词日志：搜索管理页内入口，无独立菜单行
        if (!isset($names['SystemSearchQueryLog'])) {
            $native = self::resolve('/system/search/query-log', '搜索词日志');
            if (is_array($native)) {
                $meta = is_array($native['meta'] ?? null) ? $native['meta'] : [];
                $meta['hideInMenu']  = true;
                $meta['activePath']  = '/system/search';
                $native['meta']      = $meta;
                $extras[]            = $native;
                $names['SystemSearchQueryLog'] = true;
            }
        }

        // 小程序联调页：装修向导内链
        if (!isset($names['SystemMiniprogramWechat'])) {
            $native = self::resolve('/system/miniprogram/wechat', '微信渠道');
            if (is_array($native)) {
                $meta = is_array($native['meta'] ?? null) ? $native['meta'] : [];
                $meta['hideInMenu'] = true;
                $native['meta']     = $meta;
                $extras[]           = $native;
                $names['SystemMiniprogramWechat'] = true;
            }
        }

        // 企业资源库：动态菜单挂在「内容发布」；此处仅注册 SPA 路由（侧栏不重复）
        if (!isset($names['SystemEnterpriseResource'])) {
            $native = self::resolve('/system/enterprise-resource', '企业资源库');
            if (is_array($native)) {
                $meta = is_array($native['meta'] ?? null) ? $native['meta'] : [];
                $meta['hideInMenu'] = true;
                $native['meta']     = $meta;
                $extras[]           = $native;
                $names['SystemEnterpriseResource'] = true;
            }
        }

        // 接口与提醒合并页 + 旧书签重定向（侧栏仅保留菜单树中的「接口与提醒」一行）
        foreach ([
            'SystemChannelsConfig'     => ['/system/channels', '接口与提醒'],
            'SystemPaymentConfig'      => ['/system/payment', '支付接口'],
            'SystemMailConfig'         => ['/system/mail', '邮件接口'],
            'SystemSmsConfig'          => ['/system/sms', '短信接口'],
            'SystemLoginNoticeConfig'  => ['/system/login-notice', '提醒设置'],
        ] as $routeName => [$href, $fallbackTitle]) {
            if (isset($names[$routeName])) {
                continue;
            }
            $native = self::resolve($href, $fallbackTitle);
            if (!is_array($native)) {
                continue;
            }
            $meta = is_array($native['meta'] ?? null) ? $native['meta'] : [];
            if ($routeName !== 'SystemChannelsConfig') {
                $meta['hideInMenu'] = true;
                $meta['activePath'] = '/system/channels';
            }
            $native['meta'] = $meta;
            $extras[] = $native;
            $names[$routeName] = true;
        }

        if (\app\common\service\plugin\manifest\PluginDistributionPolicy::isHostBundleActive()) {
            $restrictedSpaExtras = app(AdminSpaExplicitRouteRegistry::class)->restrictedSpaExtras();
            foreach ($restrictedSpaExtras as $href => $title) {
                $native = self::resolve($href, $title);
                if ($native === null) {
                    continue;
                }
                $name = (string) ($native['name'] ?? '');
                if ($name !== '' && isset($names[$name])) {
                    continue;
                }
                if ($name !== '') {
                    $names[$name] = true;
                }
                $meta = is_array($native['meta'] ?? null) ? $native['meta'] : [];
                // 侧栏真源：AdminMenuRegistry www 运营分组；此处只保证深链路由可达，禁止顶栏裸挂
                $meta['hideInMenu'] = true;
                $native['meta'] = $meta;
                $extras[] = $native;
            }
        }

        // 产品中心 Tab 挂载页（插件 boot 写 meta.tabMergeGroup=product-center）：
        // 侧栏可能无行 / 菜单 href 未 resolve 时，Tab 深链仍须进 Vue Router，否则点 Tab → 404。
        foreach (app(AdminSpaExplicitRouteRegistry::class)->all() as $def) {
            if (!is_array($def)) {
                continue;
            }
            $name = (string) ($def['name'] ?? '');
            if ($name === '' || isset($names[$name])) {
                continue;
            }
            $rawMeta = is_array($def['meta'] ?? null) ? $def['meta'] : [];
            if ((string) ($rawMeta['tabMergeGroup'] ?? '') !== 'product-center') {
                continue;
            }
            $native = self::buildRoute($def, (string) ($rawMeta['title'] ?? ''));
            $meta = is_array($native['meta'] ?? null) ? $native['meta'] : [];
            $meta['hideInMenu'] = true;
            $native['meta'] = $meta;
            $extras[] = $native;
            $names[$name] = true;
        }

        return $extras === [] ? $routes : array_merge($routes, $extras);
    }

    /**
     * 合并 appendMissing + hiddenRoutes 后按 name 去重，避免 Vue Router 同名路由崩溃。
     *
     * @param list<array<string, mixed>> $routes
     * @return list<array<string, mixed>>
     */
    public static function dedupeRoutesByName(array $routes): array
    {
        $seen = [];
        $out  = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $name = (string) ($route['name'] ?? '');
            if ($name !== '' && isset($seen[$name])) {
                continue;
            }
            if ($name !== '') {
                $seen[$name] = true;
            }
            if (!empty($route['children']) && is_array($route['children'])) {
                $route['children'] = self::dedupeRoutesByName($route['children']);
            }
            $out[] = $route;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolve(string $href, string $title = ''): ?array
    {
        $key = self::normalizeHref($href);
        if ($key === '' || in_array($key, self::SKIP, true)) {
            return null;
        }

        if (($def = self::explicitEntry($key)) !== null) {
            return self::buildRoute($def, $title);
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public static function hiddenRoutes(): array
    {
        $routes = [];
        foreach (self::FORM_ROUTES as $forms) {
            foreach ($forms as $form) {
                $routes[] = self::hiddenRoute(
                    (string) $form['name'],
                    (string) $form['path'],
                    (string) $form['component'],
                    (string) $form['active'],
                    (string) $form['title'],
                );
            }
        }

        return array_merge(
            $routes,
            self::pluginTabHiddenRoutes(),
            self::pluginLegacyRedirectRoutes(),
            self::weappAdminHiddenRoutes(),
            self::weappDocTabHiddenRoutes(),
            self::iframeWeappRoutes(),
        );
    }

    /**
     * 已安装插件：guide / usage / changelog 文档 Tab（EXPLICIT 未单独登记时自动补路由）
     *
     * @return list<array<string, mixed>>
     */
    private static function weappDocTabHiddenRoutes(): array
    {
        if (!\app\common\support\InstallGate::isInstalled()) {
            return [];
        }

        $segments = [
            'guide'     => ['功能介绍', '/weapp/guide/index'],
            'usage'     => ['前台调用说明', '/weapp/usage/index'],
            'changelog' => ['升级日志', '/weapp/changelog/index'],
        ];

        $routes = [];
        foreach (app(PluginService::class)->listInstalledIdentifiers() as $rawId) {
            $identifier = app(WeappAdminUiService::class)->safeIdentifier($rawId);
            if ($identifier === '') {
                continue;
            }
            $studly = self::weappRouteStudly($identifier);
            foreach ($segments as $seg => [$title, $component]) {
                $path = '/weapp/' . $identifier . '/' . $seg;
                if (self::hasExplicitPath($path)) {
                    continue;
                }
                $routes[] = self::hiddenRoute(
                    'Weapp' . $studly . ucfirst($seg),
                    $path,
                    $component,
                    self::PLUGIN_HUB_ACTIVE,
                    $title,
                    [
                        'pivarkWeapp' => $identifier,
                        'title'       => $title,
                    ],
                );
            }
        }

        return $routes;
    }

    private static function weappRouteStudly(string $identifier): string
    {
        $parts = preg_split('/[_-]+/', $identifier) ?: [$identifier];
        $out   = '';
        foreach ($parts as $part) {
            $out .= ucfirst(strtolower($part));
        }

        return $out !== '' ? $out : 'Plugin';
    }

    /** 旧 href /plugin/index → /plugin/mine（书签与 Tab 兼容） */
    /** @return list<array<string, mixed>> */
    private static function pluginLegacyRedirectRoutes(): array
    {
        return [[
            'name'     => 'PluginMineLegacy',
            'path'     => '/plugin/index',
            'redirect' => self::PLUGIN_HUB_ACTIVE,
            'meta'     => [
                'title'           => '已安装插件',
                'tabMergeGroup'   => 'plugin-hub',
                'tabMergeTitle'   => '插件中心',
                'activePath'      => self::PLUGIN_HUB_ACTIVE,
                'hideInMenu'      => true,
                'hideInTab'       => true,
            ],
        ]];
    }

    /** 插件是否在 EXPLICIT 中注册了 Core Vue 后台页 */
    public static function hasExplicitWeappAdmin(string $identifier): bool
    {
        $identifier = app(WeappAdminUiService::class)->safeIdentifier($identifier);
        if ($identifier === '') {
            return false;
        }

        return app(AdminSpaExplicitRouteRegistry::class)->hasWeappAdminIndex($identifier);
    }

    /**
     * 后台 href 是否在 EXPLICIT 登记（不触发 iframe 解析；供 WeappAdminUiService::mode 防 resolve 递归）
     */
    public static function isExplicitAdminHref(string $href): bool
    {
        $key = self::normalizeHref($href);
        if ($key === '' || in_array($key, self::SKIP, true)) {
            return false;
        }
        if (self::hasExplicitEntry($key)) {
            return true;
        }

        if (!preg_match('#^/admin/weapp/([^/]+)(?:/([^/?]+))?$#', $key, $wm)) {
            return false;
        }

        $segment    = $wm[1];
        $action     = $wm[2] ?? 'index';
        $candidates = [$segment];
        $row        = app(WeappContext::class)->resolveRouteKey($segment);
        if ($row !== null) {
            $id = app(WeappContext::class)->identifierFromRow($row);
            if ($id !== '' && !in_array($id, $candidates, true)) {
                $candidates[] = $id;
            }
        }
        foreach ($candidates as $slug) {
            foreach (['/admin/' . $slug . '/' . $action, '/admin/weapp/' . $slug . '/' . $action] as $legacyKey) {
                if (self::hasExplicitEntry($legacyKey)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function hasExplicitPath(string $spaPath): bool
    {
        $spaPath = '/' . ltrim(trim($spaPath), '/');
        if (app(AdminSpaExplicitRouteRegistry::class)->hasSpaPath($spaPath)) {
            return true;
        }
        foreach (self::EXPLICIT as $def) {
            if ((string) ($def['path'] ?? '') === $spaPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * 第三方 / 脚手架插件：manifest admin.ui.mode=iframe，后台 UI 在 weapp 包内静态页
     *
     * @return list<array<string, mixed>>
     */
    public static function iframeWeappRoutes(): array
    {
        if (!\app\common\support\InstallGate::isInstalled()) {
            return [];
        }

        $routes = [];
        foreach (app(PluginService::class)->listInstalledIdentifiers() as $identifier) {
            $manifest = app(PluginService::class)->readManifest($identifier);
            if ($manifest === null) {
                continue;
            }
            foreach (app(WeappAdminUiService::class)->spaRouteDefs($identifier, $manifest) as $def) {
                $component = (string) ($def['component'] ?? '/weapp/iframe/index');
                $meta      = is_array($def['meta'] ?? null) ? $def['meta'] : [];
                $routes[]  = self::hiddenRoute(
                    (string) $def['name'],
                    (string) $def['path'],
                    $component,
                    self::PLUGIN_HUB_ACTIVE,
                    (string) ($def['title'] ?? ''),
                    $meta,
                );
            }
        }

        return $routes;
    }

    /**
     * @param list<string> $identifierCandidates
     * @return array<string, mixed>|null
     */
    private static function resolveIframeWeappHome(array $identifierCandidates, string $title): ?array
    {
        foreach ($identifierCandidates as $slug) {
            $identifier = app(WeappAdminUiService::class)->safeIdentifier($slug);
            if ($identifier === '') {
                continue;
            }
            $manifest = app(PluginService::class)->readManifest($identifier);
            if ($manifest === null || app(WeappAdminUiService::class)->mode($manifest, $identifier) !== WeappAdminUiService::MODE_IFRAME) {
                continue;
            }
            $defs = app(WeappAdminUiService::class)->spaRouteDefs($identifier, $manifest);
            if ($defs === []) {
                continue;
            }
            $home = $defs[0];

            return self::buildRoute([
                'name'      => (string) $home['name'],
                'path'      => (string) $home['path'],
                'component' => '/weapp/iframe/index',
                'meta'      => $home['meta'],
            ], $title);
        }

        return null;
    }

    /** 插件中心 Tab（安装/新建等）不在侧栏菜单，需隐式注册 */
    /** @return list<array<string, mixed>> */
    private static function pluginTabHiddenRoutes(): array
    {
        $routes = [];
        foreach (app(PluginNav::class)->items() as $item) {
            if (($item['key'] ?? '') === 'mine' || empty($item['ready'])) {
                continue;
            }
            $href = self::normalizeHref((string) ($item['route'] ?? ''));
            if ($href === '' || ($def = self::explicitEntry($href)) === null) {
                continue;
            }
            $route = self::explicitToHiddenRoute(
                $def,
                self::PLUGIN_HUB_ACTIVE,
                (string) ($item['title'] ?? ''),
            );
            if ($route !== null) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /** weapp 插件后台入口从侧栏隐藏，由插件卡片「管理」进入 */
    /** @return list<array<string, mixed>> */
    private static function weappAdminHiddenRoutes(): array
    {
        $routes = [];
        foreach (self::mergedExplicitRoutes() as $href => $def) {
            if (!app(MenuService::class)->isHiddenPluginAdminMenu($href)) {
                continue;
            }
            $route = self::explicitToHiddenRoute(
                $def,
                self::PLUGIN_HUB_ACTIVE,
                (string) (($def['meta']['title'] ?? '') ?: ''),
            );
            if ($route !== null) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * EXPLICIT 配置 → 隐藏路由（支持 redirect 无 component）
     *
     * @param array<string, mixed> $def
     * @return array<string, mixed>|null
     */
    private static function explicitToHiddenRoute(
        array $def,
        string $activePath,
        string $title = '',
    ): ?array {
        if (isset($def['redirect'])) {
            $route = self::buildRoute($def, $title);
            $route['meta'] = array_merge($route['meta'] ?? [], [
                'activePath' => $activePath,
                'hideInMenu' => true,
            ]);

            return $route;
        }
        $name      = (string) ($def['name'] ?? '');
        $path      = (string) ($def['path'] ?? '');
        $component = (string) ($def['component'] ?? '');
        if ($name === '' || $path === '' || $component === '') {
            return null;
        }

        return self::hiddenRoute(
            $name,
            $path,
            $component,
            $activePath,
            $title,
            is_array($def['meta'] ?? null) ? $def['meta'] : [],
        );
    }

    /**
     * @param array<string, mixed> $extraMeta
     * @return array<string, mixed>
     */
    private static function hiddenRoute(
        string $name,
        string $path,
        string $component,
        string $activePath,
        string $title = '',
        array $extraMeta = [],
    ): array {
        $meta = array_merge(self::FORM_TAB_META, $extraMeta, [
            'activePath' => $activePath,
            'hideInMenu' => true,
        ]);
        if ($title !== '') {
            $meta['title'] = $title;
        }

        return [
            'name'      => $name,
            'path'      => $path,
            'component' => $component,
            'meta'      => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $moduleMeta
     * @return array<string, mixed>
     */
    public static function moduleMeta(string $moduleKey, array $moduleMeta = []): array
    {
        $base = self::MODULES[$moduleKey] ?? [];
        if ($moduleMeta !== []) {
            $base = array_merge($base, $moduleMeta);
        }

        return ['pivarkModule' => $moduleKey] + $base;
    }

    /**
     * @param array<string, mixed> $def
     * @return array<string, mixed>
     */
    private static function buildRoute(array $def, string $title): array
    {
        $meta = $def['meta'] ?? [];
        if ($title !== '') {
            $meta['title'] = $title;
        }
        if (isset($meta['pivarkModule']) && is_string($meta['pivarkModule'])) {
            $meta = array_merge($meta, self::moduleMeta($meta['pivarkModule']));
        }

        if (isset($def['redirect']) || isset($def['redirectCapability'])) {
            $redirect = $def['redirect'] ?? '/plugin/mine';
            $capKey   = trim((string) ($def['redirectCapability'] ?? ''));
            if ($capKey !== '') {
                $capPath = app(AdminSpaExplicitRouteRegistry::class)->capabilityPath($capKey);
                if (is_string($capPath) && $capPath !== '') {
                    $redirect = $capPath;
                }
            }
            // Vue Router 允许 string | { name|path }；禁 (string) 数组触发 Array to string
            if (!is_string($redirect) && !is_array($redirect)) {
                $redirect = (string) $redirect;
            }
            $route = [
                'name'     => (string) ($def['name'] ?? ''),
                'path'     => (string) ($def['path'] ?? ''),
                'redirect' => $redirect,
            ];
            if ($meta !== []) {
                $route['meta'] = $meta;
            }

            return $route;
        }

        $route = [
            'name'      => (string) ($def['name'] ?? ''),
            'path'      => (string) ($def['path'] ?? ''),
            'component' => (string) ($def['component'] ?? ''),
        ];
        if ($meta !== []) {
            $route['meta'] = $meta;
        }

        return $route;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolveFormRoute(string $ctrl, string $action, string $title): ?array
    {
        $forms = self::FORM_ROUTES[$ctrl] ?? null;
        if ($forms === null || !isset($forms[$action])) {
            return null;
        }

        $form = $forms[$action];

        return [
            'name'      => $form['name'],
            'path'      => $form['path'],
            'component' => $form['component'],
            'meta'      => [
                'activePath'      => $form['active'],
                'hideInMenu'      => true,
                'maxNumOfOpenTab' => 1,
                'title'           => $title !== '' ? $title : $form['title'],
            ],
        ];
    }

    private static function slugFromController(string $controller): string
    {
        $slug = preg_replace('/([a-z])([A-Z])/', '$1-$2', $controller) ?? $controller;

        return strtolower(str_replace('_', '-', $slug));
    }

    /**
     * 后台视图名 → /admin 路由段（如 site_nav → siteNav）
     */
    public static function adminRouteFromView(string $viewName): string
    {
        $viewName = trim(str_replace('\\', '/', $viewName), '/');
        $parts    = explode('/', $viewName);
        $ctrl     = $parts[0] ?? 'index';
        $action   = $parts[1] ?? 'index';

        $map = [
            'site_nav'      => 'siteNav',
            'site_page'     => 'sitePage',
            'site_link'     => 'siteLink',
            'site_domain'   => 'siteDomain',
            'site_slide'    => 'siteSlide',
            'tag_group'     => 'tagGroup',
            'member_level'  => 'member_level',
            'member_center' => 'member_center',
        ];
        $routeCtrl = $map[$ctrl] ?? preg_replace_callback(
            '/_([a-z])/',
            static fn (array $m): string => strtoupper($m[1]),
            $ctrl
        );

        if ($routeCtrl === 'member_center' && isset($parts[1])) {
            $sub = $parts[1];
            return match ($sub) {
                'field', 'field_index'     => '/admin/member_center/field',
                'field_form'               => '/admin/member_center/fieldForm',
                'recharge', 'recharge_index' => '/admin/member_center/recharge',
                'recharge_form'            => '/admin/member_center/rechargeForm',
                'config'                   => '/admin/member_center/config',
                'points'                   => '/admin/member_center/points',
                'balance'                  => '/admin/member_center/balance',
                'consumption'              => '/admin/member_center/consumption',
                'orders'                   => '/admin/member_center/orders',
                'cancel'                   => '/admin/member_center/cancel',
                default                    => '/admin/member_center/' . $sub,
            };
        }

        if ($action === 'form') {
            if ($routeCtrl === 'sitePage') {
                return '/admin/sitePage/form';
            }
            return '/admin/' . $routeCtrl . '/create';
        }

        return '/admin/' . $routeCtrl . '/' . $action;
    }

    /**
     * 后台 URL / 视图 → Vue hash 路径（无 # 前缀）
     *
     * @param array<string, mixed> $query
     */
    public static function spaPathFromLegacy(string $href, array $query = [], array $viewVars = []): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        if (!str_starts_with($href, '/admin')) {
            $href = '/admin/' . ltrim($href, '/');
        }

        if (str_contains($href, '/create') || str_contains($href, '/edit') || str_ends_with($href, '/form')) {
            // already action URL
        } elseif (!empty($viewVars['isEdit']) || !empty($viewVars['info']) || !empty($viewVars['document']) || !empty($viewVars['page'])) {
            $href = preg_replace('#/(index|form)$#', '/edit', $href) ?? $href;
            if (!str_ends_with($href, '/edit')) {
                $href = rtrim($href, '/') . '/edit';
            }
        } elseif (preg_match('#/form$#', $viewVars['_view'] ?? '')) {
            // site_page/form
        }

        $route = self::resolve($href);
        if ($route === null) {
            return null;
        }

        $path = (string) $route['path'];
        $id   = (int) ($query['id'] ?? 0);
        if ($id < 1) {
            foreach (['articleId', 'document', 'page'] as $key) {
                $val = $viewVars[$key] ?? null;
                if (is_array($val)) {
                    $id = (int) ($val['id'] ?? 0);
                }
                if ($id > 0) {
                    break;
                }
            }
        }
        if ($id < 1 && isset($viewVars['info'])) {
            $info = $viewVars['info'];
            $id   = is_array($info) ? (int) ($info['id'] ?? 0) : (int) ($info->id ?? 0);
        }

        if (str_contains($path, ':id')) {
            if ($id > 0) {
                $path = (string) preg_replace('#:id\??#', (string) $id, $path);
            } else {
                $path = (string) preg_replace('#/:id\??#', '', $path);
                if (str_ends_with($path, '/form')) {
                    $path .= '/0';
                }
            }
        }

        return $path;
    }

    /** @param array<string, mixed> $viewVars */
    public static function spaPathFromView(string $view, array $viewVars = [], array $query = []): ?string
    {
        $href = self::adminRouteFromView($view);

        if (($viewVars['isEdit'] ?? false) && !str_contains($href, 'edit') && !str_contains($href, 'form')) {
            $href = preg_replace('#/create$#', '/edit', $href) ?? $href;
            if (!str_ends_with($href, '/edit')) {
                $href = preg_replace('#/index$#', '/edit', $href) ?? ($href . '/edit');
            }
        }

        $viewVars['_view'] = $view;

        return self::spaPathFromLegacy($href, $query, $viewVars);
    }

    /** @var array<string, array<string, mixed>>|null SPA path 二级索引 */
    private static ?array $explicitBySpaPath = null;

    /** @return array<string, array<string, mixed>> */
    private static function explicitBySpaPath(): array
    {
        if (self::$explicitBySpaPath !== null) {
            return self::$explicitBySpaPath;
        }
        $index = [];
        foreach (self::mergedExplicitRoutes() as $def) {
            if (!is_array($def)) {
                continue;
            }
            $path = '/' . ltrim(strtolower(trim((string) ($def['path'] ?? ''))), '/');
            if ($path !== '/' && !isset($index[$path])) {
                $index[$path] = $def;
            }
        }
        self::$explicitBySpaPath = $index;

        return self::$explicitBySpaPath;
    }

    /** @return array<string, mixed>|null */
    private static function explicitEntry(string $key): ?array
    {
        if (isset(self::EXPLICIT[$key])) {
            return self::EXPLICIT[$key];
        }
        $registry = app(AdminSpaExplicitRouteRegistry::class)->get($key);
        if ($registry !== null) {
            return $registry;
        }
        $spaKey = '/' . ltrim($key, '/');
        if (isset(self::explicitBySpaPath()[$spaKey])) {
            return self::explicitBySpaPath()[$spaKey];
        }

        return null;
    }

    private static function hasExplicitEntry(string $key): bool
    {
        return self::explicitEntry($key) !== null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function mergedExplicitRoutes(): array
    {
        return array_merge(app(AdminSpaExplicitRouteRegistry::class)->all(), self::EXPLICIT);
    }
    private static function l1KernelModuleId(string $expected): string
    {
        $cfg = config('kernel.l1_modules');
        if (!is_array($cfg)) {
            return '';
        }
        foreach ($cfg as $id) {
            $id = strtolower(trim((string) $id));
            if ($id === strtolower(trim($expected))) {
                return $id;
            }
        }

        return '';
    }
}
