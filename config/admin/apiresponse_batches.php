<?php
/**
 * app/admin ApiResponse 统一 — 分批清单（wave 自动化 SSOT）
 */
declare(strict_types=1);

return [
    'batch0_foundation' => [
        'label' => 'Base + middleware',
        'files' => [
            'app/admin/controller/Base.php',
            'app/admin/middleware/AuthCheck.php',
            'app/admin/middleware/CsrfCheck.php',
            'app/admin/middleware/IdempotencyCheck.php',
        ],
    ],
    'batch1_auth_small' => [
        'label' => 'Login / Index / Weapp / Menu / Role / User',
        'files' => [
            'app/admin/controller/Login.php',
            'app/admin/controller/Index.php',
            'app/admin/controller/Weapp.php',
            'app/admin/controller/system/Menu.php',
            'app/admin/controller/system/Role.php',
            'app/admin/controller/system/User.php',
            'app/admin/controller/system/CaptchaConfig.php',
            'app/admin/controller/site/Cockpit.php',
        ],
    ],
    'batch2_config_ops' => [
        'label' => 'Config / Log / Backup / Cron / Upgrade / Search*',
        'files' => [
            'app/admin/controller/system/Config.php',
            'app/admin/controller/system/Log.php',
            'app/admin/controller/system/Backup.php',
            'app/admin/controller/system/Cron.php',
            'app/admin/controller/system/Upgrade.php',
            'app/admin/controller/system/SearchConfig.php',
            'app/admin/controller/system/SearchIndex.php',
            'app/admin/controller/system/SearchQueryLog.php',
            'app/admin/controller/system/Payment.php',
            'app/admin/controller/system/WatermarkConfig.php',
            'app/admin/controller/system/MiniprogramConfig.php',
            'app/admin/controller/system/MiniprogramPage.php',
        ],
    ],
    'batch3_site' => [
        'label' => 'site/*',
        'files' => [
            'app/admin/controller/site/SiteAdSlot.php',
            'app/admin/controller/site/SiteDomain.php',
            'app/admin/controller/site/SiteForm.php',
            'app/admin/controller/site/SiteLink.php',
            'app/admin/controller/site/SiteNav.php',
            'app/admin/controller/site/SitePage.php',
            'app/admin/controller/site/SiteSlide.php',
            'app/admin/controller/site/FloatContact.php',
            'app/admin/controller/site/Favorite.php',
        ],
    ],
    'batch4_content' => [
        'label' => 'content/* + seo',
        'files' => [
            'app/admin/controller/content/Document.php',
            'app/admin/controller/content/Item.php',
            'app/admin/controller/content/Tag.php',
            'app/admin/controller/content/TagGroup.php',
            'app/admin/controller/seo/Seo.php',
        ],
    ],
    'batch5_media_ai' => [
        'label' => 'Media / Upload / AiConfig',
        'files' => [
            'app/admin/controller/system/Media.php',
            'app/admin/controller/system/Upload.php',
            'app/admin/controller/system/AiConfig.php',
        ],
    ],
    'batch6_member_plugin' => [
        'label' => 'member/* + plugin/AccessStats',
        'files' => [
            'app/admin/controller/member/Member.php',
            'app/admin/controller/member/MemberCenter.php',
            'app/admin/controller/member/MemberLevel.php',
            'app/admin/controller/member/MemberPublish.php',
            'app/admin/controller/plugin/AccessStats.php',
        ],
    ],
    'batch7_heavy' => [
        'label' => 'Plugin.php + Spa.php',
        'files' => [
            'app/admin/controller/plugin/Plugin.php',
            'app/admin/controller/Spa.php',
        ],
    ],
];
