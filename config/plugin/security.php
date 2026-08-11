<?php
/**
 * 插件安全策略（客户站 + 开发者审包共用规则 SSOT）
 */
$appEnv = strtolower(trim((string) env('APP_ENV', '')));
$isProd = in_array($appEnv, ['production', 'prod'], true);

return [
    // 静态审计 block 级问题时拒绝 installUpload
    'audit_block_install' => filter_var(env('PIVARK_PLUGIN_AUDIT_BLOCK', '1'), FILTER_VALIDATE_BOOLEAN),

    // 跳过所有插件 boot（排障）；也可写 data/runtime/plugin_safe_mode.lock
    'safe_mode' => filter_var(env('PIVARK_PLUGIN_SAFE_MODE', '0'), FILTER_VALIDATE_BOOLEAN),

    // 紧急 URL token（留空=禁用）；任意页加 ?_pv_emergency={token} 写入 Safe Mode 锁
    'emergency_bypass_token' => trim((string) env('PIVARK_PLUGIN_EMERGENCY_TOKEN', '')),

    // boot 抛错时自动 disable（防止每请求白屏）
    'auto_disable_on_boot_fail' => filter_var(env('PIVARK_PLUGIN_AUTO_DISABLE_BOOT_FAIL', '1'), FILTER_VALIDATE_BOOLEAN),

    // 市场 acquire 后是否自动 enable（第三方 developer _catalog 建议 false）
    'third_party_auto_enable' => filter_var(env('PIVARK_PLUGIN_THIRD_PARTY_AUTO_ENABLE', '0'), FILTER_VALIDATE_BOOLEAN),

    // zip 内 PHP 单文件大小上限（字节）
    'max_php_file_bytes' => (int) env('PIVARK_PLUGIN_MAX_PHP_BYTES', 524288),

    // zip 内文件总数上限
    'max_zip_entries' => (int) env('PIVARK_PLUGIN_MAX_ZIP_ENTRIES', 500),

    // catalog 声明 package_sha256 时，远程下载安装须校验一致
    'verify_catalog_sha256' => filter_var(env('PIVARK_PLUGIN_VERIFY_CATALOG_SHA256', '1'), FILTER_VALIDATE_BOOLEAN),

    // 市场开发者插件审核通过后默认构建 encrypted_commercial 再发布（需 PIVARK_ENCODE_KEY）
    'developer_default_encoded' => filter_var(env('PIVARK_PLUGIN_DEV_DEFAULT_ENCODED', '1'), FILTER_VALIDATE_BOOLEAN),

    // 为 true 且无 encode key 时拒绝发布开发者包（platform 宿主）
    'developer_encoded_required' => filter_var(env('PIVARK_PLUGIN_DEV_ENCODED_REQUIRED', '0'), FILTER_VALIDATE_BOOLEAN),

    // platform 紧急下架后同步 catalog.json security.blocklist
    'sync_blocklist_to_catalog' => filter_var(env('PIVARK_PLUGIN_SYNC_BLOCKLIST_CATALOG', '1'), FILTER_VALIDATE_BOOLEAN),

    // 客户站：catalog 远程 blocklist 命中已安装插件时自动 disable
    'auto_disable_blocked_installed' => filter_var(env('PIVARK_PLUGIN_AUTO_DISABLE_BLOCKED', '1'), FILTER_VALIDATE_BOOLEAN),

    // 后台非插件路径跳过全量 plugin boot（登录/首页/内容等）；前台与 weapp/api 仍按需 boot
    'lazy_boot_admin' => filter_var(env('PIVARK_PLUGIN_LAZY_BOOT_ADMIN', '1'), FILTER_VALIDATE_BOOLEAN),

    // bootstrap 前执行远程 blocklist（稳定站建议 0，改由 cron + 插件中心手动同步）
    'enforce_blocklist_on_bootstrap' => filter_var(env('PIVARK_PLUGIN_ENFORCE_BLOCKLIST_BOOT', '0'), FILTER_VALIDATE_BOOLEAN),

    // enforce_blocklist_on_bootstrap=1 时检查最小间隔（秒）；0=每 boot 检查但不 invalidate 远程缓存
    'blocklist_bootstrap_ttl' => (int) env('PIVARK_PLUGIN_BLOCKLIST_BOOT_TTL', 300),

    // cron 定时拉取远程 blocklist 并停用已装插件（建议 interval 60min）
    'cron_sync_blocklist' => filter_var(env('PIVARK_PLUGIN_CRON_SYNC_BLOCKLIST', '1'), FILTER_VALIDATE_BOOLEAN),

    // cron 定时刷新已授权插件 capability 快照（建议 interval 360min）
    'cron_refresh_capability_snapshot' => filter_var(env('PIVARK_PLUGIN_CRON_REFRESH_CAPABILITY_SNAPSHOT', '1'), FILTER_VALIDATE_BOOLEAN),

    // 本地上传 zip（生产默认关，仅市场安装）
    'local_upload_enabled' => filter_var(env('PIVARK_PLUGIN_LOCAL_UPLOAD', $isProd ? '0' : '1'), FILTER_VALIDATE_BOOLEAN),

    // 本地上传仅 super_admin
    'local_upload_super_admin_only' => filter_var(env('PIVARK_PLUGIN_LOCAL_UPLOAD_SUPER_ONLY', '0'), FILTER_VALIDATE_BOOLEAN),

    // 插件 PHP 直接 use app\common\service\（非 Gateway）→ block 级拒绝（生产默认 enforce）
    'gateway_direct_service_block' => filter_var(
        env('PIVARK_PLUGIN_GATEWAY_ENFORCE', $appEnv === 'production' ? '1' : '0'),
        FILTER_VALIDATE_BOOLEAN
    ),

    /** @var list<string> 除 Weapp*Gateway 外允许的内核 import（官方遗留桥接） */
    'gateway_allowed_imports' => [
        'app\\common\\service\\front\\FrontAssetRegistry',
        'app\\common\\service\\payment\\PaymentOrderService',
        'app\\common\\service\\payment\\PaymentConfigService',
        'app\\common\\service\\item\\ItemService',
        'app\\common\\service\\config\\ConfigService',
        'app\\common\\service\\auth\\SocialAuthService',
    ],

    /** @var list<string> 第三方 Plugin.php boot 额外 allowlist（SSOT 见 PluginGatewayAuditService::BOOT_ALLOWED_FQCN） */
    'gateway_boot_allowed_imports' => [],

    // 启用插件后立即 boot 自检（失败自动 disable）；sandbox_boot_on_enable=1 时由沙箱替代
    'health_check_on_enable' => filter_var(env('PIVARK_PLUGIN_HEALTH_CHECK_ENABLE', '1'), FILTER_VALIDATE_BOOLEAN),

    // 启用前沙箱试 boot（失败拒绝启用，不写 enabled=1）
    'sandbox_boot_on_enable' => filter_var(env('PIVARK_PLUGIN_SANDBOX_BOOT_ON_ENABLE', '1'), FILTER_VALIDATE_BOOLEAN),

    // 单次请求内 ≥50% 已启用插件 boot 失败时自动写入 Safe Mode 锁
    'auto_safe_mode_on_mass_boot_fail' => filter_var(env('PIVARK_PLUGIN_AUTO_SAFE_MODE_MASS_FAIL', '1'), FILTER_VALIDATE_BOOLEAN),


    // enable 前校验 plugin.json dependencies
    'capability_enforce_dependencies_on_enable' => filter_var(env('PIVARK_PLUGIN_CAPABILITY_ENFORCE_DEPS', '1'), FILTER_VALIDATE_BOOLEAN),

    // enable 前：capability_slots 重叠时一律要求确认（官方与第三方规则相同）
    'capability_slot_conflict_warn_on_enable' => filter_var(env('PIVARK_PLUGIN_SLOT_CONFLICT_WARN', '1'), FILTER_VALIDATE_BOOLEAN),

    // uninstall 前校验反向依赖（已装插件 manifest dependencies / plugin_needs 仍引用目标）
    'capability_enforce_dependents_on_uninstall' => filter_var(env('PIVARK_PLUGIN_CAPABILITY_ENFORCE_UNINSTALL_DEPS', '1'), FILTER_VALIDATE_BOOLEAN),

    // 运行时：存在计量 action 但 manifest 未声明 features 时拒绝扣次
    'capability_enforce_features_runtime' => filter_var(env('PIVARK_PLUGIN_CAPABILITY_ENFORCE_FEATURES', $isProd ? '1' : '0'), FILTER_VALIDATE_BOOLEAN),


    // 覆盖安装前 zip 归档到 data/runtime/plugin_backups/
    'install_backup_zip' => filter_var(env('PIVARK_PLUGIN_INSTALL_BACKUP_ZIP', '1'), FILTER_VALIDATE_BOOLEAN),
    'install_backup_retention' => (int) env('PIVARK_PLUGIN_INSTALL_BACKUP_RETENTION', 5),

    // manifest 能力与计量 action 不一致时 block 安装/审包
    'capability_manifest_audit_block' => filter_var(env('PIVARK_PLUGIN_CAPABILITY_AUDIT_BLOCK', $isProd ? '1' : '0'), FILTER_VALIDATE_BOOLEAN),

    // 第三方插件经 Weapp*Gateway 调用时校验 manifest gateway_permissions（官方插件不校验）
    'gateway_permission_enforce' => filter_var(
        env('PIVARK_PLUGIN_GATEWAY_PERMISSION_ENFORCE', $isProd ? '1' : '0'),
        FILTER_VALIDATE_BOOLEAN
    ),

    // 第三方经 Gateway 访问非自有 DB 表时拒绝（官方插件不校验）
    'data_access_guard_enforce' => filter_var(
        env('PIVARK_PLUGIN_DATA_GUARD_ENFORCE', $isProd ? '1' : '0'),
        FILTER_VALIDATE_BOOLEAN
    ),

    // 第三方经 Gateway 写非白名单路径时拒绝（官方插件不校验）
    'file_access_guard_enforce' => filter_var(
        env('PIVARK_PLUGIN_FILE_GUARD_ENFORCE', $isProd ? '1' : '0'),
        FILTER_VALIDATE_BOOLEAN
    ),

    // enable 时校验 manifest min_gateway_version ≤ 当前 Gateway 代际
    'gateway_version_enforce' => filter_var(
        env('PIVARK_PLUGIN_GATEWAY_VERSION_ENFORCE', '1'),
        FILTER_VALIDATE_BOOLEAN
    ),

    // 生产环境将 base64_decode/gzinflate 等可疑模式从 WARN 升级为 BLOCK
    'audit_warn_obfuscation_block' => filter_var(
        env('PIVARK_PLUGIN_AUDIT_WARN_OBFUSCATION_BLOCK', $isProd ? '1' : '0'),
        FILTER_VALIDATE_BOOLEAN
    ),

    // blocklist.json HMAC（空则回退 catalog_sign_secret / license_secret）
    'blocklist_sign_secret' => trim((string) env('PIVARK_PLUGIN_BLOCKLIST_SIGN_SECRET', '')),

    // 生产环境 uninstall 回调抛错时阻断卸载（防恶意破坏）
    'uninstall_callback_block_on_fail' => filter_var(
        env('PIVARK_PLUGIN_UNINSTALL_CALLBACK_BLOCK', $isProd ? '1' : '0'),
        FILTER_VALIDATE_BOOLEAN
    ),

    // 卸载回调在隔离沙箱中执行（finally 回滚 Registry）
    'uninstall_callback_sandbox' => filter_var(env('PIVARK_PLUGIN_UNINSTALL_CALLBACK_SANDBOX', '1'), FILTER_VALIDATE_BOOLEAN),

    // 非 declarative 插件 uninstall 回调在子进程执行（需 uninstall_callback_sandbox=1）
    'uninstall_callback_subprocess' => filter_var(
        env('PIVARK_PLUGIN_UNINSTALL_CALLBACK_SUBPROCESS', $isProd ? '1' : '0'),
        FILTER_VALIDATE_BOOLEAN
    ),

    // blocklist severity=uninstall 时是否自动卸载已装插件（默认关，需运营显式开启）
    'blocklist_auto_uninstall' => filter_var(env('PIVARK_PLUGIN_BLOCKLIST_AUTO_UNINSTALL', '0'), FILTER_VALIDATE_BOOLEAN),

    // blocklist 自动卸载时的 purge 档位（register|config|data|full）
    'blocklist_uninstall_purge' => trim((string) env('PIVARK_PLUGIN_BLOCKLIST_UNINSTALL_PURGE', 'register')),
];
