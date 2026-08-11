<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

/** 后台「会员中心」模块内二级导航 */
class MemberCenterNav
{

    /**
     * @return list<array{key:string,title:string,route:string,ready:bool,hint?:string}>
     */
    public function items(): array
    {
        return [
            ['key' => 'list', 'title' => '会员列表', 'route' => '/admin/member/index', 'ready' => true],
            ['key' => 'level', 'title' => '会员级别', 'route' => '/admin/member_level/index', 'ready' => true],
            ['key' => 'field', 'title' => '会员字段', 'route' => '/admin/member_center/field', 'ready' => true],
            ['key' => 'config', 'title' => '功能配置', 'route' => '/admin/member_center/config', 'ready' => true],
            ['key' => 'points', 'title' => '积分管理', 'route' => '/admin/member_center/points', 'ready' => true],
            ['key' => 'recharge', 'title' => '充值套餐', 'route' => '/admin/member_center/recharge', 'ready' => true],
            ['key' => 'cancel', 'title' => '会员注销', 'route' => '/admin/member_center/cancel', 'ready' => true],
        ];
    }

    public function titleForKey(string $key): string
    {
        foreach ($this->items() as $item) {
            if ($item['key'] === $key) {
                return (string) $item['title'];
            }
        }

        return '会员中心';
    }
}
