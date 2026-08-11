<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 *
 * 安装前置控制器（PHP 8.1+）：由 install/index.php 薄壳在版本软门通过后加载。
 * 将 SCRIPT_* 归一到根 index.php，并显式写入 PATH_INFO，
 * 避免 DirectoryIndex 进本文件后被路由成 home/Index。
 *
 * PATH_INFO 必须保留 /install/testDb、/install/runStep 等动作。
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$pivarkPreflight = $root . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'preflight.php';
if (is_file($pivarkPreflight)) {
    require_once $pivarkPreflight;
    pivark_preflight_abort_if_unmet($root . DIRECTORY_SEPARATOR);
}

require_once $root . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'install_path_info.php';

$path = pivark_install_resolve_path_info($_SERVER);
$query = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
$rebuilt = $path . (is_string($query) && $query !== '' ? ('?' . $query) : '');

$_SERVER['SCRIPT_FILENAME'] = $root . DIRECTORY_SEPARATOR . 'index.php';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['PATH_INFO']       = $path;
$_SERVER['REQUEST_URI']     = $rebuilt;
unset($_SERVER['REDIRECT_URL'], $_SERVER['REDIRECT_QUERY_STRING'], $_SERVER['ORIG_PATH_INFO']);

chdir($root);
require $root . DIRECTORY_SEPARATOR . 'index.php';
