<?php
// 初始化文件 - 定义公共常量
use think\facade\Env;

// 版本信息
if (!defined('PIVARK_VERSION')) {
    define('PIVARK_VERSION', '1.6.5');
}
if (!defined('PIVARK_RELEASE')) {
    define('PIVARK_RELEASE', '20260805');
}
if (!defined('PIVARK_EDITION')) {
    $edition = strtolower(trim((string) Env::get('PIVARK_EDITION', 'community')));
    if (!in_array($edition, ['community', 'platform', 'dev'], true)) {
        $edition = 'community';
    }
    define('PIVARK_EDITION', $edition);
}

// 数据库配置
return [
    // 默认数据库连接配置
    'default'         => 'mysql',
    // 数据库连接配置信息
    'connections'     => [
        'mysql' => [
            // 自定义连接：最外层 commit/rollback 驱动 DbAfterCommit；type 为 FQCN 时须显式 builder
            'type'        => \app\common\db\PivarkMysql::class,
            'builder'     => \think\db\builder\Mysql::class,
            // 服务器地址
            'hostname'    => Env::get('DB_HOST', '127.0.0.1'),
            // 数据库名
            'database'    => Env::get('DB_NAME', 'pivark'),
            // 数据库用户名
            'username'    => Env::get('DB_USER', 'root'),
            // 数据库密码
            'password'    => Env::get('DB_PASS', ''),
            // 数据库连接端口
            'hostport'    => Env::get('DB_PORT', '3306'),
            // 数据库连接参数
            'params'      => [],
            // 数据库编码默认采用utf8
            'charset'     => 'utf8mb4',
            // 数据库表前缀
            'prefix'      => Env::get('DB_PREFIX', 'pv_'),
            // 是否长连接
            'persistent'  => true,
        ],
        // 只读副本（配置 DB_READ_HOST 且与主库不同时生效，见 DbRead）
        'mysql_read' => [
            'type'        => \app\common\db\PivarkMysql::class,
            'builder'     => \think\db\builder\Mysql::class,
            'hostname'    => Env::get('DB_READ_HOST', Env::get('DB_HOST', '127.0.0.1')),
            'database'    => Env::get('DB_NAME', 'pivark'),
            'username'    => Env::get('DB_READ_USER', Env::get('DB_USER', 'root')),
            'password'    => Env::get('DB_READ_PASS', Env::get('DB_PASS', '')),
            'hostport'    => Env::get('DB_READ_PORT', Env::get('DB_PORT', '3306')),
            'params'      => [],
            'charset'     => 'utf8mb4',
            'prefix'      => Env::get('DB_PREFIX', 'pv_'),
            'persistent'  => true,
        ],
        // 达梦数据库（企业版信创）
        'dameng' => [
            'type'        => 'dm',
            'hostname'    => Env::get('DM_HOST', '127.0.0.1'),
            'database'    => Env::get('DM_NAME', 'pivark'),
            'username'    => Env::get('DM_USER', 'SYSDBA'),
            'password'    => Env::get('DM_PASS', ''),
            'hostport'    => Env::get('DM_PORT', '5236'),
            'prefix'      => 'pv_',
        ],
    ],
];