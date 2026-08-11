<?php
/**
 * 元舟 PivArk — /install 入口薄壳（须 PHP 5.0+ 可解析）
 *
 * 先检查 PHP 环境并提示改配置；版本达标后再进入现代安装前置控制器。
 */
$__pivarkRoot = dirname(dirname(__FILE__));
$__pivarkGate = $__pivarkRoot . '/install/bootstrap/php_version_gate.php';
if (is_file($__pivarkGate)) {
    require_once $__pivarkGate;
}
require dirname(__FILE__) . '/bootstrap/install_front_controller.php';
