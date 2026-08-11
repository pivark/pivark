<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2025 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | 应用配置
// +----------------------------------------------------------------------
return [
    // 应用名称
    'app_name'         => '元舟 PivArk',
    // 应用地址
    'app_host'         => '',
    // 应用调试模式
    'app_debug'        => false,
    // 应用Trace
    'app_trace'        => false,
    // 应用模式状态
    'app_status'       => '',
    // 是否支持多模块
    'app_multi_module' => true,
    // 入口自动绑定模块
    'auto_bind_module' => false,
    // 注册的根命名空间
    'root_namespace'   => [],
    // 默认输出类型
    'default_return_type' => 'html',
    // 默认AJAX 数据返回格式,json jsonp
    'default_ajax_return' => 'json',
    // 默认控制器层名称
    'default_controller_layer' => 'controller',
    // 默认验证器层名称
    'default_validate_layer' => 'validate',
    // 默认模型层名称
    'default_model_layer' => 'model',
    // 默认服务层名称
    'default_service_layer' => 'service',
    // 默认模块名
    'default_module'     => 'home',
    // 默认控制器名
    'default_controller' => 'Index',
    // 默认操作名
    'default_action'     => 'index',
    // 默认空控制器
    'empty_controller'   => 'Error',
    // 操作方法后缀
    'action_suffix'      => '',
    // 自动搜索控制器
    'controller_auto_search' => true,
    // PHP date() / strtotime 默认时区（与业务库 datetime 无 TZ 字段一致）
    'default_timezone'       => 'Asia/Shanghai',
];