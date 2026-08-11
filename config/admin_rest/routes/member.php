<?php
/**
 * 后台 REST 会员域路由（/api/v1/admin/members|member-levels|member-center/*|member-publish/*）
 */
declare(strict_types=1);

use app\admin\controller\member\Member;
use app\admin\controller\member\MemberCenter;
use app\admin\controller\member\MemberLevel;
use app\admin\controller\member\MemberPublish;

/**
 * @param array{0:class-string,1:string} $handler
 * @return array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>}
 */
$mr = static function (
    string $method,
    string $path,
    array $handler,
    ?string $permissionController = null,
    ?string $permissionAction = null,
): array {
    $controller = $permissionController ?? strtolower(
        preg_replace('/^.*\\\\/', '', $handler[0]) ?: 'gateway',
    );

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
    // members
    $mr('GET', 'members', [Member::class, 'index'], 'member', 'index'),
    $mr('POST', 'members', [Member::class, 'save'], 'member', 'save'),
    $mr('POST', 'members/delete', [Member::class, 'delete']),
    $mr('POST', 'members/batch-delete', [Member::class, 'batchDelete']),
    $mr('POST', 'members/batch-adjust', [Member::class, 'batchAdjust']),
    $mr('GET', 'members/export', [Member::class, 'export']),
    $mr('GET', 'members/detail', [Member::class, 'detail'], 'member', 'detail'),

    // member-levels
    $mr('GET', 'member-levels', [MemberLevel::class, 'index'], 'member_level', 'index'),
    $mr('POST', 'member-levels', [MemberLevel::class, 'save'], 'member_level', 'save'),
    $mr('POST', 'member-levels/delete', [MemberLevel::class, 'delete']),
    $mr('GET', 'member-levels/detail', [MemberLevel::class, 'detail'], 'memberlevel', 'detail'),

    // member-center
    $mr('GET', 'member-center/fields', [MemberCenter::class, 'field'], 'member_center', 'field'),
    $mr('GET', 'member-center/fields/form', [MemberCenter::class, 'fieldForm']),
    $mr('POST', 'member-center/fields', [MemberCenter::class, 'fieldSave'], 'member_center', 'fieldsave'),
    $mr('POST', 'member-center/fields/delete', [MemberCenter::class, 'fieldDelete']),
    $mr('GET', 'member-center/config', [MemberCenter::class, 'config'], 'member_center', 'config'),
    $mr('POST', 'member-center/config', [MemberCenter::class, 'configSave'], 'member_center', 'configsave'),
    $mr('GET', 'member-center/points', [MemberCenter::class, 'points'], 'member_center', 'points'),
    $mr('POST', 'member-center/points/config', [MemberCenter::class, 'pointsConfigSave']),
    $mr('POST', 'member-center/points/adjust', [MemberCenter::class, 'pointsAdjust']),
    $mr('GET', 'member-center/balance', [MemberCenter::class, 'balance'], 'member_center', 'balance'),
    $mr('POST', 'member-center/balance/adjust', [MemberCenter::class, 'balanceAdjust']),
    $mr('GET', 'member-center/consumption', [MemberCenter::class, 'consumption'], 'member_center', 'consumption'),
    $mr('GET', 'member-center/orders', [MemberCenter::class, 'orders'], 'member_center', 'orders'),
    $mr('GET', 'member-center/orders/detail', [MemberCenter::class, 'orderDetail']),
    $mr('GET', 'member-center/recharge', [MemberCenter::class, 'recharge'], 'member_center', 'recharge'),
    $mr('GET', 'member-center/recharge/form', [MemberCenter::class, 'rechargeForm']),
    $mr('POST', 'member-center/recharge', [MemberCenter::class, 'rechargeSave']),
    $mr('POST', 'member-center/recharge/delete', [MemberCenter::class, 'rechargeDelete']),
    $mr('POST', 'member-center/recharge/sort', [MemberCenter::class, 'rechargeSort']),
    $mr('GET', 'member-center/cancel', [MemberCenter::class, 'cancel'], 'member_center', 'cancel'),
    $mr('POST', 'member-center/cancel/handle', [MemberCenter::class, 'cancelHandle']),

    // member-publish（后台内嵌投稿）
    $mr('GET', 'member-publish/bootstrap', [MemberPublish::class, 'bootstrap'], 'member_publish', 'bootstrap'),
    $mr('GET', 'member-publish/document-form-meta', [MemberPublish::class, 'documentFormMeta']),
];
