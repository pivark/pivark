<?php

/**
 * Community 发行 / 安装向导插件清单（空键由 PluginManifestPolicyDiscovery 自发现）
 * @see install\support\InstallEditionRules / ReleaseMigrationRules / CoreUpdatePathRules / CommunityPackRules
 */
return [
    'bundled_plain_weapp' => [],
    'install_required_weapp' => [],
    'enhancement_pack' => [],
    'non_bundled_migration_prefixes' => [],
    'forbidden_optional_tables' => [],
    /**
     * 已退役内核/旧插件表：不得出现在 install/setup/schema.sql，emit 时跳过。
     * （ai_ppt 已下架；download/gallery/video/ask/talent/comment 旧壳 → 现用 weapp_doc_*）
     */
    'retired_schema_tables' => [
        'ai_ppt_decks',
        'ai_ppt_exports',
        'ai_ppt_share_hits',
        'ai_ppt_snapshots',
        'ai_ppt_theme_prefs',
        'ai_ppt_usage_log',
        'ai_document_process',
        'document_items',
        'document_product_refs',
        'site_inquiries',
        'weapp_ask_items',
        'weapp_comment_levels',
        'weapp_comment_likes',
        'weapp_doc_bundle_files',
        'weapp_doc_vod_assets',
        'weapp_download_bundles',
        'weapp_download_files',
        'weapp_download_items',
        'weapp_download_logs',
        'weapp_download_purchases',
        'weapp_gallery_groups',
        'weapp_gallery_items',
        'weapp_talent_items',
        'weapp_video_episode_play_daily',
        'weapp_video_episodes',
        'weapp_video_grants',
        'weapp_video_items',
        'weapp_video_parse_log',
        'weapp_video_play_daily',
        'weapp_video_series',
        'weapp_video_sources',
        'weapp_video_watch_progress',
        'weapp_favorite_actions',
        'weapp_favorite_stats',
        'weapp_float_contact_items',
        'weapp_pay_orders',
        'weapp_pay_notify_logs',
        'weapp_shop_merchants',
        'platform_invoice_requests',
        'product_catalog_lines',
    ],
];
