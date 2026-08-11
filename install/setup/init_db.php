<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 核心表初始化（含完整中文 COMMENT，与数据字典一致）
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
$cfg  = migration_resolve_db_env($root);
$host = $cfg['host'];
$port = $cfg['port'];
$db   = $cfg['db'];
$user = $cfg['user'];
$pass = $cfg['pass'];
$pfx  = $cfg['pfx'];

try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` DEFAULT CHARACTER SET utf8mb4");
    $pdo->exec("USE `{$db}`");
    echo "Database OK\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}users` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `username` varchar(50) NOT NULL COMMENT '用户名（唯一）',
        `password` varchar(255) NOT NULL COMMENT '密码（bcrypt）',
        `totp_secret` varchar(64) NOT NULL DEFAULT '' COMMENT 'TOTP secret(base32)',
        `totp_enabled` tinyint unsigned NOT NULL DEFAULT 0 COMMENT 'TOTP enabled',
        `email` varchar(100) DEFAULT NULL COMMENT '邮箱',
        `mobile` varchar(20) DEFAULT NULL COMMENT '手机号',
        `nickname` varchar(100) DEFAULT NULL COMMENT '昵称',
        `avatar` varchar(255) DEFAULT NULL COMMENT '头像URL',
        `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态：0禁用 1正常',
        `must_change_password` tinyint NOT NULL DEFAULT 0 COMMENT '1=登录后须改密',
        `security_level` tinyint NOT NULL DEFAULT 1 COMMENT '密级：1公开~5绝密',
        `last_login_ip` varchar(45) DEFAULT NULL COMMENT '最后登录IP',
        `last_login_time` datetime DEFAULT NULL COMMENT '最后登录时间',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}roles` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `name` varchar(100) NOT NULL COMMENT '角色名称',
        `code` varchar(50) NOT NULL COMMENT '角色编码（唯一）',
        `description` text COMMENT '角色描述',
        `is_system` tinyint NOT NULL DEFAULT 0 COMMENT '是否系统预置（不可删除）',
        `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态：0禁用 1正常',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色表'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}permissions` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `name` varchar(100) NOT NULL COMMENT '权限名称',
        `code` varchar(100) NOT NULL COMMENT '权限标识（如 admin.user.create）',
        `parent_id` int unsigned DEFAULT NULL COMMENT '父级权限ID',
        `module` varchar(50) NOT NULL DEFAULT 'admin' COMMENT '所属模块（admin/api/plugin）',
        `icon` varchar(50) DEFAULT NULL COMMENT '图标',
        `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
        `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态：0禁用 1正常',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='权限表'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}role_permissions` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `role_id` int unsigned NOT NULL COMMENT '角色ID',
        `permission_id` int unsigned NOT NULL COMMENT '权限ID',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_role_perm` (`role_id`,`permission_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色权限关联表'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}user_roles` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `user_id` int unsigned NOT NULL COMMENT '用户ID',
        `role_id` int unsigned NOT NULL COMMENT '角色ID',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_user_role` (`user_id`,`role_id`),
        KEY `idx_ur_user` (`user_id`),
        KEY `idx_ur_role` (`role_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户角色关联表'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}menus` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `title` varchar(100) NOT NULL COMMENT '菜单名称',
        `permission_code` varchar(100) DEFAULT NULL COMMENT '关联权限标识',
        `parent_id` int unsigned DEFAULT NULL COMMENT '父级菜单ID',
        `icon` varchar(50) DEFAULT NULL COMMENT '图标',
        `route` varchar(255) DEFAULT NULL COMMENT '路由路径',
        `params` text COMMENT '路由参数（JSON）',
        `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
        `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态：0禁用 1正常',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='菜单表'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}configs` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `group` varchar(50) NOT NULL DEFAULT 'system' COMMENT '配置分组',
        `key` varchar(100) NOT NULL COMMENT '配置键名',
        `value` text COMMENT '配置值',
        `description` varchar(255) DEFAULT NULL COMMENT '配置说明',
        `type` varchar(20) NOT NULL DEFAULT 'string' COMMENT '值类型（string/json/number/boolean）',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_key` (`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}config_secrets` (
        `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `key` varchar(100) NOT NULL COMMENT 'configs 键名',
        `value` text NOT NULL COMMENT 'AppCipher 加密值',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_key` (`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统凭据（加密存储）'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$pfx}logs` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
        `user_id` int unsigned DEFAULT NULL COMMENT '操作用户ID',
        `username` varchar(50) DEFAULT NULL COMMENT '操作用户名',
        `type` varchar(50) NOT NULL COMMENT '日志类型（login/operate/security）',
        `action` varchar(100) NOT NULL COMMENT '操作行为',
        `module` varchar(50) DEFAULT NULL COMMENT '操作模块',
        `request_method` varchar(10) DEFAULT NULL COMMENT '请求方法',
        `request_url` varchar(255) DEFAULT NULL COMMENT '请求URL',
        `request_params` text COMMENT '请求参数（JSON）',
        `ip` varchar(45) DEFAULT NULL COMMENT '操作IP',
        `user_agent` varchar(500) DEFAULT NULL COMMENT 'UA信息',
        `result` tinyint NOT NULL DEFAULT 1 COMMENT '操作结果：0失败 1成功',
        `duration` int DEFAULT NULL COMMENT '执行耗时(ms)',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志表'");

    echo "Tables OK\n";

    $installWizard = \in_array(
        \strtolower(\trim((string) \getenv('PIVARK_INSTALL_WIZARD'))),
        ['1', 'true', 'yes'],
        true
    );
    if (!$installWizard) {
        $hash = \password_hash('admin', PASSWORD_BCRYPT);
        $pdo->exec("INSERT IGNORE INTO `{$pfx}users`
        (`id`,`username`,`password`,`email`,`mobile`,`nickname`,`avatar`,`status`,`must_change_password`,`security_level`,`last_login_ip`,`last_login_time`,`created_at`,`updated_at`)
        VALUES (1,'admin','{$hash}','','','超级管理员','',1,1,1,NULL,NULL,NOW(),NOW())");
        echo "Admin OK (default: admin / admin — change password before production!)\n";
    } else {
        echo "Admin user deferred to install wizard\n";
    }

    $pdo->exec("INSERT IGNORE INTO `{$pfx}roles` VALUES (1,'超级管理员','super_admin','',1,1,NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO `{$pfx}roles` VALUES (2,'系统管理员','system_admin','',1,1,NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO `{$pfx}roles` VALUES (3,'普通管理员','admin','',0,1,NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO `{$pfx}roles` VALUES (4,'前台会员','member','前台注册会员默认角色',1,1,NOW(),NOW())");
    echo "Roles OK\n";

    // schema.sql 基线路径会 noop 迁移，会员等级种子须在此补齐
    try {
        $lvlCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$pfx}member_levels`")->fetchColumn();
        if ($lvlCount < 1) {
            $pdo->exec("INSERT IGNORE INTO `{$pfx}member_levels` (`id`,`name`,`rank`,`is_default`,`status`,`created_at`,`updated_at`) VALUES
                (1,'注册会员',10,1,1,NOW(),NOW()),
                (2,'VIP会员',20,0,1,NOW(),NOW()),
                (3,'黄金会员',30,0,1,NOW(),NOW())");
            echo "Member levels OK\n";
        }
    } catch (Throwable $e) {
        echo "Member levels skip: " . $e->getMessage() . "\n";
    }

    if (!$installWizard) {
        $pdo->exec("INSERT IGNORE INTO `{$pfx}user_roles` (`user_id`,`role_id`,`created_at`)
        SELECT u.id, 1, NOW() FROM `{$pfx}users` u WHERE u.username = 'admin' LIMIT 1");
        echo "User roles OK\n";
    }

    $pdo->exec("INSERT IGNORE INTO `{$pfx}permissions` VALUES
        (1,'控制台','admin.dashboard',NULL,'admin',NULL,1,1,NOW()),
        (2,'用户管理','admin.user',NULL,'admin',NULL,10,1,NOW()),
        (3,'用户列表','admin.user.list',NULL,'admin',NULL,11,1,NOW()),
        (4,'新增用户','admin.user.create',NULL,'admin',NULL,12,1,NOW()),
        (5,'编辑用户','admin.user.edit',NULL,'admin',NULL,13,1,NOW()),
        (6,'删除用户','admin.user.delete',NULL,'admin',NULL,14,1,NOW()),
        (7,'角色管理','admin.role',NULL,'admin',NULL,20,1,NOW()),
        (8,'角色列表','admin.role.list',NULL,'admin',NULL,21,1,NOW()),
        (9,'新增角色','admin.role.create',NULL,'admin',NULL,22,1,NOW()),
        (10,'编辑角色','admin.role.edit',NULL,'admin',NULL,23,1,NOW()),
        (11,'删除角色','admin.role.delete',NULL,'admin',NULL,24,1,NOW()),
        (12,'系统配置','admin.config',NULL,'admin',NULL,30,1,NOW()),
        (13,'菜单管理','admin.menu',NULL,'admin',NULL,40,1,NOW()),
        (14,'菜单列表','admin.menu.list',NULL,'admin',NULL,41,1,NOW()),
        (15,'新增菜单','admin.menu.create',NULL,'admin',NULL,42,1,NOW()),
        (16,'编辑菜单','admin.menu.edit',NULL,'admin',NULL,43,1,NOW()),
        (17,'删除菜单','admin.menu.delete',NULL,'admin',NULL,44,1,NOW())");
    echo "Permissions OK\n";

    $ids = $pdo->query("SELECT id FROM `{$pfx}permissions`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->exec(
            "INSERT IGNORE INTO `{$pfx}role_permissions` (`role_id`,`permission_id`,`created_at`) VALUES (1," . (int) $id . ',NOW())'
        );
    }
    foreach ($ids as $id) {
        if (!in_array($id, [6, 11, 17], true)) {
            $pdo->exec(
                "INSERT IGNORE INTO `{$pfx}role_permissions` (`role_id`,`permission_id`,`created_at`) VALUES (2," . (int) $id . ',NOW())'
            );
        }
    }
    echo "Role-Perm OK\n";

    // 后台菜单不在此灌种：由 InstallDatabaseService::applyAdminMenuSsot（admin_menu_ssot.php）写入 SPA 路由
    echo "Menus skip (SPA SSOT)\n";

    // 广告位默认行：schema 只建表不灌种；漏种会导致 site_slides 有数据但前台 listPublic 因 slot 无效返回空
    $pdo->exec(
        "INSERT INTO `{$pfx}site_ad_slots` (`code`,`name`,`remark`,`default_creative_type`,`sort`,`status`) VALUES
        ('home_carousel','首页轮播','首页顶部轮播区','carousel',1,1),
        ('home_hero','首页主图（单图）','首页单图 Banner','single_image',2,1),
        ('sidebar','侧栏条幅','列表/详情侧栏','single_image',3,1),
        ('list_top','列表页顶栏','频道列表顶部','single_image',4,1),
        ('footer_strip','页脚通栏','全站页脚通栏','single_image',5,1),
        ('popup','弹窗/浮层','营销弹窗或浮层','single_image',6,1)
        ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `remark`=VALUES(`remark`),
          `default_creative_type`=VALUES(`default_creative_type`), `sort`=VALUES(`sort`), `status`=VALUES(`status`)"
    );
    echo "Ad slots OK\n";

    $pdo->exec("INSERT IGNORE INTO `{$pfx}configs` VALUES
        (NULL,'system','site_status','1',NULL,'string',NOW(),NOW()),
        (NULL,'system','site_name','元舟 PivArk',NULL,'string',NOW(),NOW()),
        (NULL,'system','site_logo','',NULL,'string',NOW(),NOW()),
        (NULL,'system','site_logo_hero','',NULL,'string',NOW(),NOW()),
        (NULL,'system','site_url','https://pivark.com',NULL,'string',NOW(),NOW()),
        (NULL,'system','site_copyright','Copyright (c) PivArk',NULL,'string',NOW(),NOW()),
        (NULL,'system','site_icp','',NULL,'string',NOW(),NOW()),
        (NULL,'system','site_police','',NULL,'string',NOW(),NOW()),
        (NULL,'system','site_title','元舟 PivArk',NULL,'string',NOW(),NOW()),
        (NULL,'seo','site_keywords','PivArk,元舟,企业数字中枢,开源建站,私有化部署,weapp插件,主题模板,小程序',NULL,'string',NOW(),NOW()),
        (NULL,'seo','site_description','元舟 PivArk 是开源可商用、支持私有化部署的企业数字中枢：整站门户、weapp 插件、主题模板与小程序交付，Community 免费版与 Enterprise 商业授权。',NULL,'string',NOW(),NOW()),
        (NULL,'system','site_third_code','',NULL,'text',NOW(),NOW()),
        (NULL,'system','site_mode','dev',NULL,'string',NOW(),NOW()),
        (NULL,'system','site_theme','default',NULL,'string',NOW(),NOW()),
        (NULL,'system','member_theme','default',NULL,'string',NOW(),NOW()),
        (NULL,'system','captcha_on','1',NULL,'string',NOW(),NOW()),
        (NULL,'upload','upload_image_format','jpg|gif|png|bmp|jpeg|ico',NULL,'string',NOW(),NOW()),
        (NULL,'upload','upload_software_format','zip|gz|rar|doc|docx|xls|ppt|wps|pdf|txt',NULL,'string',NOW(),NOW()),
        (NULL,'upload','upload_video_format','swf|mpg|mp3|rm|rmvb|wmv|wma|wav|mid|mov|mp|mp4',NULL,'string',NOW(),NOW()),
        (NULL,'upload','upload_max_size','2',NULL,'string',NOW(),NOW()),
        (NULL,'upload','upload_name_rule','random',NULL,'string',NOW(),NOW()),
        (NULL,'upload','upload_dir_rule','ymd',NULL,'string',NOW(),NOW()),
        (NULL,'upload','upload_wap_adapt','1',NULL,'string',NOW(),NOW()),
        (NULL,'upload','upload_add_title','1',NULL,'string',NOW(),NOW()),
        (NULL,'upload','upload_add_alt','1',NULL,'string',NOW(),NOW()),
        (NULL,'upload','upload_alt_replace','1',NULL,'string',NOW(),NOW()),
        (NULL,'content','doc_default_hits','500|1000',NULL,'string',NOW(),NOW()),
        (NULL,'content','file_default_downloads','100|500',NULL,'string',NOW(),NOW()),
        (NULL,'content','content_editor','tiptap',NULL,'string',NOW(),NOW()),
        (NULL,'map','baidu_map_ak','',NULL,'string',NOW(),NOW()),
        (NULL,'editor','editor_remote_local','1',NULL,'string',NOW(),NOW()),
        (NULL,'editor','editor_clear_external','1',NULL,'string',NOW(),NOW()),
        (NULL,'editor','editor_special_chars','0',NULL,'string',NOW(),NOW()),
        (NULL,'external','external_domain_whitelist','baidu.com\naliyun.com\nvideo.qq.com',NULL,'text',NOW(),NOW())");
    echo "Configs OK\n";

    echo "\n=== 初始化完成 ===\n";
} catch (PDOException $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
