<?php
/**
 * 插件市场目录（Community：标装 comment + 官方云 catalog，客户零配置）
 *
 * 对外 Community 客户站：未配 env 时预置 public/static/market/ + 兜底授权平台 pivark.cn。
 * 官方平台站可通过 PIVARK_PLUGIN_MARKET_URL 或 PIVARK_LICENSE_PLATFORM_URL 指向经营目录。
 *
 * 拉货优先级（有 license platform 时）：
 *   1) PIVARK_PLUGIN_MARKET_URL 显式覆盖
 *   2) {platform}{host Feed path}  （品项实时；路径由宿主注入，禁写死 identifier）
 *   3) {platform}/static/market/catalog.json                   （静态投影/CDN）
 *   4) official_catalog_urls（仅外网客户站）
 */
return [
    /**
     * 官方插件云 catalog（仅外网零配置客户站；内部 lane 不请求）。
     * 协议相对 // ：跟授权平台实际 http/https，禁止写死 https://。
     * 暂不走 plugins.* 子域，直接授权平台 pivark.cn（www 兜底）。
     *
     * @var list<string>
     */
    'official_catalog_urls' => [
        '//pivark.cn/static/market/catalog.json',
        '//www.pivark.cn/static/market/catalog.json',
    ],

    /** 官方资产根（协议相对 //；内部 lane 用 b / 本站，见 usesInternalMarketLane） */
    'official_asset_hosts' => [
        '//pivark.cn',
        '//www.pivark.cn',
    ],

    /**
     * 平台绝对基址白名单（拍板：根域 pivark.com/cn；仅 apex + 登记一级子域；禁任意多级）
     * PIVARK_LICENSE_PLATFORM_URL / Feed / catalog 主机必须命中，否则拒绝拉取。
     */
    'platform_allowed_root_domains' => ['pivark.com', 'pivark.cn'],
    'platform_allowed_subdomains' => [
        'www',
        'b',
        'api',
        'plugins',
        'dev',
        'test',
        'demo1',
    ],

    /** 显式覆盖整段目录 URL（少用）；逛市场主路径为 browse */
    'remote_catalog_url' => trim((string) env('PIVARK_PLUGIN_MARKET_URL', '')),
    /**
     * 相对 license platform 的品项 SSOT Feed 路径（装包/索引）。
     * 默认空：宿主 inject plugin_market_feed_path；客户站用 env 覆盖。
     * browse/updates 默认由 feed_path 将 /feed 换成 /browse|/updates。
     */
    'remote_feed_path'   => trim((string) env('PIVARK_PLUGIN_MARKET_FEED_PATH', '')),
    'remote_browse_path' => trim((string) env('PIVARK_PLUGIN_MARKET_BROWSE_PATH', '')),
    'remote_updates_path'=> trim((string) env('PIVARK_PLUGIN_MARKET_UPDATES_PATH', '')),
    'local_catalog_path' => env('PIVARK_PLUGIN_MARKET_LOCAL', ''),
    'remote_cache_ttl'   => (int) env('PIVARK_PLUGIN_MARKET_CACHE_TTL', 3600),

    /** Enterprise 对象存储占位（R30 · 4.2） */
    'storage_driver'     => env('PIVARK_PLUGIN_STORAGE_DRIVER', 'local'),
    'storage_bucket'     => env('PIVARK_PLUGIN_STORAGE_BUCKET', 'pivark-plugins-stub'),
    'storage_endpoint'   => env('PIVARK_PLUGIN_STORAGE_ENDPOINT', ''),
    'storage_access_key' => env('PIVARK_PLUGIN_STORAGE_ACCESS_KEY', ''),
    'storage_secret_key' => env('PIVARK_PLUGIN_STORAGE_SECRET_KEY', ''),
    'storage_region'     => env('PIVARK_PLUGIN_STORAGE_REGION', 'us-east-1'),
    'require_signed_download' => filter_var(env('PIVARK_PLUGIN_REQUIRE_SIGNED_DOWNLOAD', false), FILTER_VALIDATE_BOOLEAN),
    'signed_url_ttl'     => (int) env('PIVARK_PLUGIN_SIGNED_URL_TTL', 900),
    'storage_sign_secret'=> env('PIVARK_PLUGIN_STORAGE_SIGN_SECRET', 'pivark-market-stub'),

    /** 应用市场「类型」筛选项顺序（kind 键） */
    'kind_order' => [
        'miniprogram',
        'application',
        'document-addon',
        'platform',
    ],

    'kind_labels' => [
        'miniprogram'    => '小程序',
        'application'    => '独立应用',
        'document-addon' => '文档扩展',
        'platform'       => '平台能力',
    ],

    /**
     * 规划中的小程序 SKU（仅市场展示，未入库时可由远程 catalog 覆盖）
     * platform: wechat|douyin|alipay|baidu · edition: content|shop|…
     */
    'miniprogram_roadmap' => [
        [
            'identifier'  => 'mp-wechat',
            'name'        => '微信 · 内容版小程序',
            'status'      => 'available',
        ],
        [
            'identifier'  => 'mp-wechat-shop',
            'name'        => '微信 · 商城版小程序',
            'status'      => 'available',
            'platform'    => 'wechat',
            'edition'     => 'shop',
        ],
        [
            'identifier'  => 'mp-douyin',
            'name'        => '抖音小程序',
            'status'      => 'planned',
            'platform'    => 'douyin',
        ],
        [
            'identifier'  => 'mp-alipay',
            'name'        => '支付宝小程序',
            'status'      => 'planned',
            'platform'    => 'alipay',
        ],
        [
            'identifier'  => 'mp-baidu',
            'name'        => '百度小程序',
            'status'      => 'planned',
            'platform'    => 'baidu',
        ],
    ],

    /**
     * 市场「推荐」位 SSOT：品项 `flags.market_featured`（产品中心勾选）→ 保存后投影到 catalog.json `featured`。
     * 官方插件限时免费（除 comment 标装外）：市场展示 0 元 + 安装 grant N 天 trial
     * period_days 与 PIVARK_PLUGIN_TRIAL_DAYS / plugin_commercial.default_trial_days 对齐
     */
    'official_trial' => [
        'model'       => 'subscription',
        'price'       => 0,
        'period_days' => max(1, (int) env('PIVARK_PLUGIN_TRIAL_DAYS', 90)),
    ],

    /**
     * 已废弃：官方插件定价 SSOT 为品项 Item → public/static/market/catalog.json；
     * SKU 结构见各插件 plugin.json commercial.skus；定价 SSOT 为品项 Item。
     */
    'commercial_overrides' => [],

    /**
     * 已从仓库与市场下架的 dev/QA 标识符（全站测、catalog merge、远程 catalog 均不得再展示/写回）
     *
     * @var list<string>
     */
    'catalog_retired_identifiers' => [
        'hello_demo',
        'pl_dep_consumer',
        'ai_ppt',
        'b2b2c',
    ],

    /** catalog.json HMAC 密钥（空则回退 plugin.commercial.license_secret；均空则跳过验签） */
    'catalog_sign_secret' => trim((string) env('PIVARK_PLUGIN_CATALOG_SIGN_SECRET', '')),

    /** 配置了签名密钥时，远程 catalog 验签失败则拒绝加载 */
    'verify_catalog_signature' => filter_var(env('PIVARK_PLUGIN_VERIFY_CATALOG_SIGNATURE', '1'), FILTER_VALIDATE_BOOLEAN),

    /** 上传 zip 大小上限（字节） */
    'max_package_bytes' => max(1048576, (int) env('PIVARK_PLUGIN_MAX_PACKAGE_BYTES', 20971520)),

    /** Cron 检测到更新后是否自动拉包升级（默认仅检测，后台可一键应用） */
    'auto_update_apply' => filter_var(env('PIVARK_PLUGIN_AUTO_UPDATE_APPLY', '0'), FILTER_VALIDATE_BOOLEAN),

    /** 升级前表级 JSON 快照单表最大行数（超限标记 truncated） */
    'upgrade_table_snapshot_max_rows' => max(1000, (int) env('PIVARK_PLUGIN_UPGRADE_SNAPSHOT_MAX_ROWS', 10000)),

    /** 升级前优先 mysqldump 插件业务表（失败回退 JSON 快照） */
    'upgrade_sql_snapshot_enabled' => filter_var(env('PIVARK_PLUGIN_UPGRADE_SQL_SNAPSHOT', '1'), FILTER_VALIDATE_BOOLEAN),
];
