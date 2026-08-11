<?php
/**
 * PivArk 业务配置（非 ThinkPHP 核心项）
 */
return [
    // 系统运行模式键名（存于 configs.site_mode）
    'site_mode_key' => 'site_mode',
    'site_mode_dev' => 'dev',
    'site_mode_ops' => 'mode',

    /** 版本代号（PIVARK_EDITION）→ 对外中文名；技术键 community 不变 */
    'edition_display' => [
        'community' => '开源版',
        'platform'  => '平台版',
        'dev'       => '开发版',
    ],

    // 运营模式：前台整页 HTML 缓存秒数（0=关闭页面缓存，仅清模板编译缓存目录）
    'page_cache_ttl' => 300,

    // 运营模式：页缓存过期后的宽限秒数（仍返回旧页并在请求结束后再生，0=关闭 SWR）
    'page_cache_stale_ttl' => 30,

    // dev：页缓存 SWR 宽限（0=关闭；需 dev_page_cache_ttl>0）
    'dev_page_cache_stale_ttl' => 0,

    // dev 下前台整页 HTML 缓存秒数（任意模板；0=关闭；内容变更时 clearPageCache 失效）
    'dev_page_cache_ttl' => 120,

    /** 插件 manifest api_version 须与此一致（Weapp*Gateway 契约代际） */
    'plugin_core_api_version' => '1.0',

    // @deprecated 请用 dev_page_cache_ttl（保留兼容）
    'dev_home_page_cache_ttl' => 120,

    // 前台标签块 HTML 缓存秒数（tagdocuments 等，dev/ops 均生效）
    'block_cache_ttl' => 120,

    // 前台元数据 SQL 结果缓存（config/导航/标签树等，跨请求）
    'meta_sql_cache_ttl' => 86400,

    // 热点读路径短缓存（标签列表等；走 cache_driver file/redis）
    'hot_data_cache_ttl' => 300,

    // 模板 parseTags 结果缓存（按模板 mtime + 页面变量指纹；变更后 clearPageCache）
    'tpl_parse_cache_ttl' => 86400,

    // 模板静态编译缓存（去注释/别名归一 + 分词摘要；按文件 mtime + fc_gen；0=关闭）
    'tpl_compile_cache_ttl' => 604800,

    // 缓存失效后 shutdown 预热页缓存（不阻塞当前响应）
    'page_cache_warm_enabled' => true,
    'page_cache_warm_templates' => ['home'],

    // 前台 listPublic 物化缓存秒数（仅首页 offset=0、无 keyword/attr 等）
    'doc_list_materialized_ttl' => 86400,

    // 品项目录 listPublic 短缓存（筛选/分页指纹；0=关闭）
    'item_list_cache_ttl' => 90,

    // 跨域 Catalog list 短缓存（items 域走 item_list_cache_ttl；ERP/MES/Shop 等用此项；0=关闭）
    'catalog_list_cache_ttl' => 90,

    // 品项 Facet 上下文计数短缓存（秒；0=关闭）
    'item_facet_cache_ttl' => 300,

    // 品项目录关键词驱动：sql | meili | elastic（全文为 MySQL 时固定 sql；全文 Meili 时默认同 Meili，可改 sql）
    'item_catalog_search_driver' => 'sql',

    // 静态 HTML 输出目录（相对项目根，默认 public/）
    'static_html_root' => 'public',

    // 后台 Vue 入口（History 路由前缀，勿带 index/index 或 hash）
    'admin_home_url' => '/admin',

    // 后台 CSRF Token 有效期（秒），校验成功后轮换
    'csrf_ttl_seconds' => 7200,

    // 前台表单 CSRF 有效期（秒）
    'front_csrf_ttl_seconds' => 1800,

    // 会员登录 redirect 允许的额外域名（默认已含 site_url 与当前 HTTP_HOST）
    'allowed_redirect_hosts' => [],

    // 前台 /search 按 IP 限流（与 api.rate_limit 同结构，默认略严）
    'search_rate_limit' => [
        'enabled'        => true,
        'max_requests'   => 60,
        'window_seconds' => 60,
    ],

    // Meili/Elastic 不可用回退 SQL 时的降级窗口（秒）；窗口内无 FULLTEXT 则拒搜，防 LIKE 全表扫
    'search_meili_fallback_cooldown' => 120,

    'config_save_allowed_keys' => [
        'site_status', 'site_name', 'site_logo', 'site_logo_hero', 'site_url', 'site_force_https', 'site_copyright', 'site_icp', 'site_police',
        'admin_clipboard_guide_enabled',
        'site_title', 'site_keywords', 'site_description', 'site_third_code', 'site_mode', 'site_theme', 'member_theme',
        'member_center_open', 'product_open',
        'site_url_mode', 'site_url_suffix', 'site_url_channel_rule', 'site_url_tag_page_rule', 'site_url_document_rule',
        'seo_static_subdir',
        'seo_static_publish_home', 'seo_static_publish_channel', 'seo_static_publish_adjacent',
        'seo_static_edit_home', 'seo_static_edit_channel', 'seo_static_edit_adjacent',
        'static_oss_enabled', 'static_oss_driver', 'static_oss_endpoint', 'static_oss_bucket',
        'static_oss_access_key', 'static_oss_secret_key', 'static_oss_prefix', 'static_oss_mirror_dir',
        'static_oss_webhook_url', 'static_oss_webhook_secret',
        'static_cdn_enabled', 'static_cdn_driver', 'static_cdn_public_base',
        'static_cdn_webhook_url', 'static_cdn_webhook_secret',
        'static_cdn_warmup_enabled', 'static_cdn_warmup_webhook_url', 'static_cdn_warmup_webhook_secret',
        'static_cdn_warmup_timeout',
        'upload_remote_enabled', 'upload_remote_driver', 'upload_remote_webhook_url', 'upload_remote_webhook_secret',
        'site_list_page_style',
        'seo_title_separator', 'seo_tag_title_rule', 'seo_document_title_rule',
        'seo_sitemap_enabled', 'seo_robots_txt',
        'seo_sitemap_type_xml', 'seo_sitemap_type_txt', 'seo_sitemap_type_html', 'seo_sitemap_type_ai', 'seo_sitemap_type_llms',
        'seo_sitemap_auto_update', 'seo_sitemap_filter_hidden_tag', 'seo_sitemap_filter_external',
        'seo_sitemap_freq_home', 'seo_sitemap_freq_list', 'seo_sitemap_freq_content',
        'seo_sitemap_priority_home', 'seo_sitemap_priority_list', 'seo_sitemap_priority_content',
        'seo_sitemap_limit_document', 'seo_sitemap_limit_tag',
        'seo_baidu_push_token',
        'seo_robots_preset',
        'captcha_on',
        'captcha_charset_pool',
        'captcha_font_size',
        'captcha_use_curve',
        'captcha_use_noise',
        'captcha_length',
        'captcha_scene_admin_on',
        'captcha_scene_home_on',
        'captcha_scene_register_on',
        'captcha_scene_reset_password_on',
        'captcha_scene_contact_on',
        'captcha_scene_dealer_on',
        'captcha_scene_oa_on',
        'watermark_on',
        'watermark_type',
        'watermark_text',
        'watermark_image',
        'watermark_min_w',
        'watermark_min_h',
        'watermark_opacity',
        'watermark_quality',
        'watermark_pos',
        'search_mode',
        'search_token_match',
        'search_rate_max',
        'search_rate_window',
        'search_lock_seconds',
        'search_blocked_tag_ids',
        'search_sensitive_chars',
        'search_ai_answer_on',
        'search_ai_doc_limit',
        'search_smart_on',
        'search_smart_fallback_on',
        'search_smart_synonyms_json',
        'search_smart_examples_json',
        'search_smart_parse_debug_on',
        'search_smart_vector_on',
        'search_smart_cache_ttl',
        'search_smart_session_on',
        'search_smart_relax_mode',
        'search_smart_param_logic',
        'search_smart_weight_keyword',
        'search_smart_weight_vector',
        'search_smart_weight_product',
        'search_smart_lang',
        'search_smart_embed_on',
        'search_meili_items_index',
        'search_meili_timeout',
        'event_bus_async',
        'ai_embedding_model',
        'payment_open',
        'payment_wechat_open',
        'payment_alipay_open',
        'payment_balance_open',
        'payment_pay_mode',
        'payment_alipay_app_id',
        'payment_alipay_private_key',
        'payment_alipay_public_key',
        'payment_wechat_app_id',
        'payment_wechat_mch_id',
        'payment_wechat_api_v3_key',
        'payment_wechat_serial_no',
        'payment_wechat_private_key',
        'upload_image_format', 'upload_software_format', 'upload_video_format', 'upload_max_size',
        'upload_name_rule', 'upload_dir_rule', 'media_url_mode',
        'upload_wap_adapt', 'upload_add_title', 'upload_add_alt',
        'upload_alt_replace',
        'doc_default_hits', 'file_default_downloads', 'content_editor', 'baidu_map_ak',
        'editor_remote_local', 'editor_clear_external', 'editor_special_chars',
        'editor_tag_autolink',
        'editor_content_pagination',
        'editor_content_pagination_mode',
        'editor_content_pagination_chars',
        'document_qr_enabled', 'document_qr_size',
        'gzip_enabled',
        'front_minify_html_on', 'front_minify_inline_css_on', 'front_minify_inline_js_on',
        'ip_access_mode', 'ip_allowlist', 'ip_blocklist',
        'backup_retention_days',
        'ops_retention_audit_logs_days',
        'ops_retention_stats_hits_days',
        'ops_retention_search_query_log_days',
        'ops_retention_search_index_dead_days',
        'ops_retention_domain_event_log_days',
        'ops_retention_payment_notify_log_days',
        'ops_retention_payment_stale_order_days',
        'ops_retention_media_orphan_days',
        'ops_retention_inquiry_days',
        'cache_driver',
        'redis_cache_host',
        'redis_cache_port',
        'redis_cache_password',
        'redis_cache_select',
        'redis_cache_prefix',
        'stats_heatmap_enabled',
        'cc_protection_enabled',
        'cc_max_requests_per_minute',
        'rate_limit_policy_overrides',
        'admin_entry_alias',
        'favorite_open', 'favorite_guest',
        'site_phone', 'site_email', 'site_address',
        'site_wechat_qr', 'site_wechat_mp_qr',
        'site_map_enabled', 'site_map_lat', 'site_map_lng', 'site_map_embed_url', 'site_map_note',
        'external_domain_whitelist',
    ],

    // 自定义变量 cv_{name}_* 禁止使用的 name 前缀
    'config_custom_var_reserved_prefixes' => [
        'site_', 'db_', 'admin_', 'upload_', 'editor_', 'captcha_', 'config_', 'app_',
    ],

    // weapp 官方插件允许的 package vendor（publisher_type=official 时校验）
    'plugin_official_vendors' => ['pivark'],

    /**
     * 安装向导「官方内容增强包」可选项（空则读 plugin.json pivark_policy）
     * SSOT：install\service\InstallEnhancementPackService::catalogForWizard()
     */
    'plugin_install_enhancement_pack' => [],

    /**
     * 安装向导默认勾选的插件（与 enhancement_pack.default=true 对齐；可被 .env 覆盖）
     * 也可用 .env：PIVARK_PLUGIN_INSTALL_DEFAULTS=comment,doc_bundle
     */
    'plugin_install_defaults' => array_values(array_filter(array_map(
        static fn (string $id): string => trim($id),
        explode(',', (string) env('PIVARK_PLUGIN_INSTALL_DEFAULTS', ''))
    ))),

    /** 禁止卸载的插件（逗号分隔 identifier；官方免费插件默认可卸载） */
    'plugin_uninstall_blocked' => array_values(array_filter(array_map(
        static fn (string $id): string => trim($id),
        explode(',', (string) env('PIVARK_PLUGIN_UNINSTALL_BLOCKED', ''))
    ))),

    /**
     * 是否禁止通过「插件市场」zip 覆盖已存在的官方 pivark 插件目录
     * 开源/交付版建议 false，便于从市场安装官方包；整站镜像可设 true
     */
    'plugin_block_official_upload_replace' => filter_var(
        env('PIVARK_BLOCK_OFFICIAL_UPLOAD', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    /** 禁止市场 zip 覆盖目录的官方插件（须 plugin_block_official_upload_replace=true） */
    'plugin_upload_replace_protected' => array_values(array_filter(array_map(
        static fn (string $id): string => trim($id),
        explode(',', (string) env('PIVARK_PLUGIN_UPLOAD_REPLACE_PROTECTED', ''))
    ))),

    /** 已并入内核、不经 plugins 表注册的 identifier */
    'core_merged_identifiers' => [
        'member', 'seo', 'stats', 'ad', 'form', 'float_contact', 'ai_config', 'product',
    ],

    /** 授权 package 镜像同步试点（EntitlementCommandService） */
    'entitlement_package_mirror_identifiers' => array_values(array_filter(array_map(
        static fn (string $id): string => trim($id),
        explode(',', (string) env('PIVARK_ENTITLEMENT_MIRROR_IDS', ''))
    ))),

    /** weapp schema 台阶 CLI 试点 */
    'weapp_schema_pilot_identifiers' => array_values(array_filter(array_map(
        static fn (string $id): string => trim($id),
        explode(',', (string) env('PIVARK_WEAPP_SCHEMA_PILOT', ''))
    ))),

    /** 核心版本清单 URL（客户站后台对比）；空则读 public/static/release/updates.json */
    'update_check_url'       => trim((string) env('PIVARK_UPDATE_CHECK_URL', '')),
    /** 未授权站点提示用的开源发行页（默认 Gitee Releases） */
    'opensource_repo_url'    => trim((string) env('PIVARK_OPENSOURCE_REPO_URL', 'https://gitee.com/pivark/pivark/releases')),
    /** GitHub 镜像仓（浏览 https://github.com/pivark/pivark ；clone …/pivark.git） */
    'github_repo_url'        => trim((string) env('PIVARK_GITHUB_REPO_URL', 'https://github.com/pivark/pivark')),
    'update_check_local'     => trim((string) env('PIVARK_UPDATE_CHECK_LOCAL', '')),
    'update_check_cache_ttl' => (int) env('PIVARK_UPDATE_CHECK_CACHE_TTL', 3600),

    /** 发行版：community | platform | dev（常量优先，否则读 env；勿写死 community） */
    'edition' => defined('PIVARK_EDITION')
        ? (string) PIVARK_EDITION
        : (string) env('PIVARK_EDITION', 'community'),

    /** platform/dev + PIVARK_LICENSE_PLATFORM=1：本实例作为授权运营平台 */
    'license_platform_enabled' => filter_var(
        env('PIVARK_LICENSE_PLATFORM', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    /**
     * 客户站联网激活/心跳/同步基址。
     * Community 未配 env 时默认授权平台（客户无需改 .env；运维可用 env 覆盖，如联调指 b）。
     * 授权平台宿主本身留空（不远程指自己）。
     */
    'license_platform_url' => (static function (): string {
        $fromEnv = trim((string) env('PIVARK_LICENSE_PLATFORM_URL', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        if (filter_var(env('PIVARK_LICENSE_PLATFORM', false), FILTER_VALIDATE_BOOLEAN)) {
            return '';
        }
        $edition = defined('PIVARK_EDITION')
            ? (string) PIVARK_EDITION
            : (string) env('PIVARK_EDITION', 'community');
        if (strtolower($edition) === 'community') {
            return 'https://pivark.cn';
        }

        return '';
    })(),

    /** 文档/覆盖用：Community 默认授权平台基址（与上式 community 回落一致） */
    'license_platform_url_default' => 'https://pivark.cn',

    /**
     * 授权远程 HMAC 共享密钥（客户站与授权平台须相同）。
     * 空 = 不启用签名（仅 HTTPS）；配置后请求/响应双向验签。
     */
    'license_hmac_secret' => trim((string) env('PIVARK_LICENSE_HMAC_SECRET', '')),

    /** 客户站调用授权平台 HTTP 超时（秒） */
    'license_remote_timeout' => max(2, (int) env('PIVARK_LICENSE_REMOTE_TIMEOUT', 8)),

    /** 客户站后台心跳上报间隔（秒） */
    'license_heartbeat_interval' => (int) env('PIVARK_LICENSE_HEARTBEAT_INTERVAL', 21600),

    /** 授权平台中心路径（客户站「管理授权」跳转） */
    'license_portal_path' => trim((string) env('PIVARK_LICENSE_PORTAL_PATH', '/portal/licenses')),

    /** 客户站自动同步授权平台间隔（秒） */
    'license_sync_interval' => (int) env('PIVARK_LICENSE_SYNC_INTERVAL', 300),

    /** configs 分组镜像（JSON 存 config_group_*；flat key 仍为读写 SSOT） */
    'config_groups' => [
        'site_meta' => ['site_name', 'site_title', 'site_keywords', 'site_description', 'site_logo', 'site_logo_hero', 'site_copyright', 'site_icp', 'site_police'],
        'site_url'  => ['site_url', 'site_url_mode', 'site_url_suffix', 'site_url_channel_rule', 'site_url_tag_page_rule', 'site_url_document_rule', 'site_force_https'],
        'runtime'   => ['site_mode', 'site_theme', 'member_theme', 'gzip_enabled', 'front_minify_html_on', 'front_minify_inline_css_on', 'front_minify_inline_js_on', 'cache_driver'],
        'search'    => ['search_mode', 'search_smart_on', 'search_smart_fallback_on', 'search_ai_answer_on'],
    ],

    /** 后台 configs 保存类型校验（凭据走 config_secrets） */
    'config_value_schema' => [
        'site_mode'              => ['type' => 'enum', 'values' => ['dev', 'mode']],
        'site_url_mode'          => ['type' => 'enum', 'values' => ['dynamic', 'rewrite', 'static']],
        'media_url_mode'         => ['type' => 'enum', 'values' => ['relative', 'absolute']],
        'site_url_channel_rule'  => ['type' => 'enum', 'values' => ['flat', 'dir_index']],
        'site_url_tag_page_rule' => ['type' => 'enum', 'values' => ['query', 'path', 'list']],
        'site_url_document_rule' => ['type' => 'enum', 'values' => ['under_documents', 'root', 'tag_dir']],
        'cache_driver'           => ['type' => 'enum', 'values' => ['file', 'redis']],
        'site_force_https'       => ['type' => 'bool'],
        'search_smart_on'        => ['type' => 'bool'],
        'search_smart_fallback_on' => ['type' => 'bool'],
        'search_ai_answer_on'    => ['type' => 'bool'],
        'captcha_on'             => ['type' => 'bool'],
        'watermark_on'           => ['type' => 'bool'],
        'gzip_enabled'               => ['type' => 'bool'],
        'front_minify_html_on'       => ['type' => 'bool'],
        'front_minify_inline_css_on' => ['type' => 'bool'],
        'front_minify_inline_js_on'  => ['type' => 'bool'],
        'search_rate_max'            => ['type' => 'int', 'min' => 1, 'max' => 10000],
        'search_rate_window'     => ['type' => 'int', 'min' => 1, 'max' => 86400],
    ],
];
