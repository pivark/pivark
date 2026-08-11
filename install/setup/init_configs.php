<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
$__root = dirname(__DIR__, 2);
require_once $__root . '/vendor/autoload.php';
$__migBoot = \app\common\support\ProjectPaths::migrationsBootstrapFile();
if (!is_readable($__migBoot)) {
    throw new \RuntimeException('缺少迁移引导');
}
require_once $__migBoot;
$root = migration_project_root();
$env  = parse_ini_file(migration_env_path($root));
$host = $env["DB_HOST"] ?? "127.0.0.1";
$port = $env["DB_PORT"] ?? "3306";
$db   = $env["DB_NAME"] ?? "pivark";
$user = $env["DB_USER"] ?? "root";
$pass = $env["DB_PASS"] ?? "";
$pfx  = $env["DB_PREFIX"] ?? "pv_";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 配置项（使用 INSERT IGNORE 避免覆盖已修改的配置）
    $pdo->exec("INSERT IGNORE INTO `{$pfx}configs` VALUES
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
        (NULL,'content','doc_default_source','本站原创',NULL,'string',NOW(),NOW()),
        (NULL,'content','doc_author_presets','编辑部\n特约作者',NULL,'text',NOW(),NOW()),
        (NULL,'content','doc_source_presets','本站原创\n网络转载\n合作供稿',NULL,'text',NOW(),NOW()),
        (NULL,'content','file_default_downloads','100|500',NULL,'string',NOW(),NOW()),
        (NULL,'content','content_editor','tiptap',NULL,'string',NOW(),NOW()),
        (NULL,'map','baidu_map_ak','',NULL,'string',NOW(),NOW()),
        (NULL,'editor','editor_remote_local','1',NULL,'string',NOW(),NOW()),
        (NULL,'editor','editor_clear_external','1',NULL,'string',NOW(),NOW()),
        (NULL,'editor','editor_special_chars','0',NULL,'string',NOW(),NOW()),
        (NULL,'external','external_domain_whitelist','baidu.com\naliyun.com\nvideo.qq.com',NULL,'text',NOW(),NOW())");

    echo "Configs initialized OK\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
