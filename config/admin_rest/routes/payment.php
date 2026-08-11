<?php
/**
 * 后台 REST 支付域路由（/api/v1/admin/payment/*）
 */
declare(strict_types=1);

use app\admin\controller\system\Payment;

/**
 * @param array{0:class-string,1:string} $handler
 * @return array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>}
 */
$pay = static function (
    string $method,
    string $path,
    array $handler,
    ?string $permissionController = null,
    ?string $permissionAction = null,
): array {
    $controller = $permissionController ?? 'payment';

    return [
        'method'  => $method,
        'path'    => $path,
        'handler' => $handler,
        'options' => [
            'permission_controller' => $controller,
            'permission_action'     => $permissionAction ?? strtolower((string) $handler[1]),
        ],
    ];
};

return [
    $pay('GET', 'payment', [Payment::class, 'index'], 'payment', 'index'),
    $pay('GET', 'payment/orders', [Payment::class, 'orders'], 'payment', 'orders'),
    $pay('POST', 'payment/config', [Payment::class, 'configSave'], 'payment', 'configsave'),
    $pay('POST', 'payment/import-wechat-certs', [Payment::class, 'importWechatCerts']),
    $pay('POST', 'payment/import-alipay-keys', [Payment::class, 'importAlipayKeys']),
    $pay('POST', 'payment/refund-order', [Payment::class, 'refundOrder']),
    $pay('POST', 'payment/close-order', [Payment::class, 'closeOrder']),
    $pay('POST', 'payment/close-stale-orders', [Payment::class, 'closeStaleOrders']),
];
