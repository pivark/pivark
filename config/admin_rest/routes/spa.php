<?php
/**
 * 后台 REST spa 残余路由（/api/v1/admin/* · intentional Spa 域）
 *
 * 保留：session/dashboard/TOTP/meta/* · CRUD 读路径已迁域 REST（SPA-001 Phase3）
 * handler 均在 Spa 控制器；权限键与 config/admin/permission.php 对齐。
 */
declare(strict_types=1);

use app\admin\controller\Spa;

/**
 * @return array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>,pattern?:array<string,string>,handler_params?:list<string>}
 */
$sp = static function (
    string $method,
    string $path,
    string $action,
    ?string $permAction = null,
    array $extra = [],
): array {
    return array_merge([
        'method'  => $method,
        'path'    => $path,
        'handler' => [Spa::class, $action],
        'options' => [
            'permission_controller' => 'spa',
            'permission_action'     => $permAction ?? strtolower($action),
        ],
    ], $extra);
};

return [
    $sp('GET', 'session/resolve-href', 'resolveHref', 'resolvehref'),
    $sp('GET', 'dashboard', 'dashboard', 'dashboard'),
    $sp('POST', 'dashboard/prefs', 'saveDashboardPrefs', 'savedashboardprefs'),
    $sp('GET', 'account/totp', 'totpStatus', 'totpstatus'),
    $sp('POST', 'account/totp/setup', 'totpSetup', 'totpsetup'),
    $sp('POST', 'account/totp/enable', 'totpEnable', 'totpenable'),
    $sp('POST', 'account/totp/disable', 'totpDisable', 'totpdisable'),
    $sp('GET', 'session/login-notices', 'loginNotices', 'loginnotices'),
    $sp('GET', 'meta/import-intents', 'importIntentCatalog', 'importintentcatalog'),
    $sp('GET', 'meta/document-tag-picker', 'documentTagPicker', 'documenttagpicker'),
];
