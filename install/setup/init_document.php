<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 文档/标签模块表初始化（含完整中文 COMMENT）
 */
declare(strict_types=1);

$__root = dirname(__DIR__, 2);
require_once $__root . '/vendor/autoload.php';
$__migBoot = \app\common\support\ProjectPaths::migrationsBootstrapFile();
if (!is_readable($__migBoot)) {
    throw new \RuntimeException('缺少迁移引导');
}
require_once $__migBoot;
$root = migration_project_root();
$env  = parse_ini_file(migration_env_path($root));
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$db   = $env['DB_NAME'] ?? 'pivark';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';
$pfx  = $env['DB_PREFIX'] ?? 'pv_';

try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("USE `{$db}`");
    echo "Database OK\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}documents` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `title` varchar(200) NOT NULL COMMENT '文档标题',
        `subtitle` varchar(200) NOT NULL DEFAULT '' COMMENT '副标题',
        `summary` varchar(500) DEFAULT NULL COMMENT '摘要（列表/API用）',
        `source` varchar(100) NOT NULL DEFAULT '' COMMENT '来源',
        `attr_flags` varchar(120) NOT NULL DEFAULT '' COMMENT '文档属性标记',
        `external_url` varchar(500) NOT NULL DEFAULT '' COMMENT '外链地址（attr external 时跳转）',
        `external_open_new_tab` tinyint NOT NULL DEFAULT 0 COMMENT '外链打开方式：0当前窗口 1新窗口',
        `read_perm` tinyint NOT NULL DEFAULT 0 COMMENT '阅读权限：0开放 1受限（登录或等级）',
        `read_level_id` int unsigned NOT NULL DEFAULT 0 COMMENT '最低会员等级ID，0=仅登录',
        `tpl_name` varchar(100) NOT NULL DEFAULT '' COMMENT '文档模板文件名',
        `html_name` varchar(120) NOT NULL DEFAULT '' COMMENT '自定义 URL 段；空则 /documents/{id}',
        `url_path` varchar(100) NOT NULL DEFAULT '' COMMENT '前台自定义路径，留空走 /documents',
        `content` longtext COMMENT 'PC 正文（富文本 HTML 或 Markdown 源码）',
        `content_mobile` longtext COMMENT '手机端正文',
        `litpic` varchar(255) DEFAULT '' COMMENT '缩略图',
        `seo_title` varchar(200) DEFAULT '' COMMENT 'SEO标题',
        `seo_keywords` varchar(255) DEFAULT '' COMMENT 'SEO关键词',
        `seo_description` varchar(500) DEFAULT '' COMMENT 'SEO描述',
        `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态：0草稿 1发布',
        `author_id` int unsigned DEFAULT 0 COMMENT '作者ID',
        `nav_id` int unsigned NOT NULL DEFAULT 0 COMMENT '真分类 site_nav.id，0=未归类',
        `author_name` varchar(50) NOT NULL DEFAULT '' COMMENT '作者署名',
        `click` int unsigned DEFAULT 0 COMMENT '点击数',
        `published_at` datetime DEFAULT NULL COMMENT '发布时间',
        `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间，NULL=未删',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`),
        KEY `idx_title` (`title`),
        KEY `idx_status` (`status`),
        KEY `idx_nav_id` (`nav_id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文档表'");
    echo "Documents table OK\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}tags` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `name` varchar(100) NOT NULL COMMENT '标签名称',
        `slug` varchar(100) NOT NULL DEFAULT '' COMMENT 'URL/API标识（唯一）',
        `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态：0禁用 1启用',
        `use_count` int unsigned DEFAULT 0 COMMENT '使用次数',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_name` (`name`),
        UNIQUE KEY `uk_slug` (`slug`),
        KEY `idx_status` (`status`),
        KEY `idx_use_count` (`use_count`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='标签表'");
    echo "Tags table OK\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}document_tags` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `document_id` int unsigned NOT NULL COMMENT '文档ID',
        `tag_id` int unsigned NOT NULL COMMENT '标签ID',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_document_tag` (`document_id`, `tag_id`),
        KEY `idx_document_id` (`document_id`),
        KEY `idx_tag_id` (`tag_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文档标签关联表'");
    echo "Document_tags table OK\n";

    // 文档相关菜单由 admin_menu_ssot 统一灌入（禁止再插 /admin/document/* 旧路径）
    echo "Menus skip (SPA SSOT)\n";

    $pdo->exec("INSERT IGNORE INTO `{$pfx}permissions` VALUES
        (20,'文档管理','admin.document',NULL,'admin','fa fa-file-text',5,1,NOW()),
        (21,'文档列表','admin.document.list',20,'admin',NULL,1,1,NOW()),
        (22,'发布文档','admin.document.create',20,'admin',NULL,2,1,NOW()),
        (23,'编辑文档','admin.document.edit',20,'admin',NULL,3,1,NOW()),
        (24,'删除文档','admin.document.delete',20,'admin',NULL,4,1,NOW())");
    echo "Permissions OK\n";

    $rolePermCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$pfx}role_permissions` WHERE role_id=1")->fetchColumn();
    if ($rolePermCount > 0) {
        $pdo->exec("INSERT IGNORE INTO `{$pfx}role_permissions` (role_id, permission_id, created_at) VALUES
            (1, 20, NOW()), (1, 21, NOW()), (1, 22, NOW()), (1, 23, NOW()), (1, 24, NOW())");
    }
    echo "Role permissions OK\n";

    echo "\n=== 文档管理模块初始化完成 ===\n";
} catch (PDOException $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
