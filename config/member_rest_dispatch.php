<?php
/**
 * 会员 REST 路由表 SSOT（/api/v1/member/*）
 *
 * 每项：method, path, handler[class, method], pattern?, handler_params?
 * v1_direct：非 Gateway dispatch 的显式控制器（与 routes 同文件 SSOT，避免 api.php 硬编码）
 */
declare(strict_types=1);

use app\home\controller\Member;

/** @return array{method:string,path:string,handler:array{0:class-string,1:string}} */
$mr = static function (string $method, string $path, string $action): array {
    return [
        'method'  => $method,
        'path'    => $path,
        'handler' => [Member::class, $action],
    ];
};

/** @return array{method:string,path:string,handler:string,pattern?:array<string,string>} */
$md = static function (string $method, string $path, string $handler, array $pattern = []): array {
    $row = [
        'method'  => $method,
        'path'    => $path,
        'handler' => $handler,
    ];
    if ($pattern !== []) {
        $row['pattern'] = $pattern;
    }

    return $row;
};

return [
    'v1_direct' => [
        $md('POST', 'mp-wechat/login', 'MemberMpWechat@login'),
        $md('POST', 'mp-wechat/logout', 'MemberMpWechat@logout'),
        $md('GET', 'me', 'MemberMpWechat@me'),
        $md('GET', 'recharge/packages', 'MemberRecharge@packages'),
        $md('POST', 'recharge/pay', 'MemberRecharge@pay'),
        $md('GET', 'recharge/order/:order_no', 'MemberRecharge@orderStatus', ['order_no' => '[A-Za-z0-9_\-]+']),
        $md('GET', 'plugin-refund/requests', 'MemberPluginRefund@requests'),
        $md('POST', 'plugin-refund/request', 'MemberPluginRefund@submit'),
        $md('GET', 'plugin-refund/status/:order_no', 'MemberPluginRefund@status', ['order_no' => '[A-Za-z0-9_\-]+']),
    ],
    'routes' => [
        $mr('POST', 'login', 'doLogin'),
        $mr('POST', 'register', 'doRegister'),
        $mr('GET', 'check-username', 'checkUsername'),
        $mr('POST', 'signin', 'doSignin'),
        $mr('POST', 'logout', 'doLogout'),
        $mr('POST', 'forgot-password', 'doForgotPassword'),
        $mr('POST', 'forgot-username', 'doForgotUsername'),
        $mr('POST', 'reset-password', 'doResetPassword'),
        $mr('POST', 'enter-as', 'doEnterAs'),
        $mr('POST', 'profile', 'saveProfile'),
        $mr('POST', 'password', 'changePassword'),
        $mr('POST', 'cancel', 'requestCancel'),
        $mr('POST', 'oauth/unbind', 'oauthUnbind'),
        $mr('POST', 'document/save', 'saveDocument'),
        $mr('POST', 'upload/image', 'uploadImage'),
        $mr('POST', 'recharge/balance', 'purchaseRecharge'),
        $mr('POST', 'pay/create', 'payCreate'),
        $mr('GET', 'pay/status', 'payStatus'),
        [
            'method'  => 'POST',
            'path'    => ':memberPath',
            'handler' => [Member::class, 'pluginMemberPagePost'],
            'pattern' => [
                'memberPath' => '[a-z][a-z0-9_-]*(?:/[a-z0-9_-]+)*',
            ],
        ],
    ],
];
