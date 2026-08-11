<?php
/**
 * 元舟 PivArk — Web 入口薄壳（须 PHP 5.0+ 可解析）
 *
 * 先跑 PHP 版本软门；通过后再进入 bootstrap/web_entry.php（ThinkPHP / PHP 8.1+）。
 * 对方哪怕是 PHP 5，也能看到「当前版本过低 + 如何改配置」。
 */
$__pivarkRoot = dirname(__FILE__);
$__pivarkGate = $__pivarkRoot . '/install/bootstrap/php_version_gate.php';
if (is_file($__pivarkGate)) {
    require_once $__pivarkGate;
}
require $__pivarkRoot . '/bootstrap/web_entry.php';
