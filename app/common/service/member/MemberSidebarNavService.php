<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

/** 会员中心左侧导航：按业务分组，供模板折叠展示 */
final class MemberSidebarNavService
{

    /**
     * @param list<array{identifier:string,label:string,url:string,route:string,icon:string,is_active:int}> $pluginNav
     * @return list<array{id:string,label:string,expanded:int,items:list<array{key:string,label:string,url:string,icon:string,active:int}>}>
     */
    public function groups(string $navActive, array $ctx, array $pluginNav): array
    {
        $groups = [];

        if (!empty($ctx['document_publish_open'])) {
            $groups[] = $this->pack(
                'content',
                '内容与投稿',
                $navActive,
                [
                    [
                        'key'   => 'document_create',
                        'label' => '发布文章',
                        'url'   => (string) ($ctx['document_create_url'] ?? ''),
                        'icon'  => 'bi-pencil-square',
                    ],
                    [
                        'key'   => 'documents',
                        'label' => '我的文章',
                        'url'   => (string) ($ctx['documents_url'] ?? ''),
                        'icon'  => 'bi-file-earmark-text',
                    ],
                ],
            );
        }

        $assetItems = [];
        if (!empty($ctx['points_enabled'])) {
            $pointsLabel = (string) ($ctx['points_label'] ?? '积分');
            $assetItems[] = [
                'key'   => 'points',
                'label' => '我的' . $pointsLabel,
                'url'   => (string) ($ctx['points_url'] ?? ''),
                'icon'  => 'bi-coin',
            ];
        }
        $assetItems[] = [
            'key'   => 'balance',
            'label' => '余额流水',
            'url'   => (string) ($ctx['balance_url'] ?? ''),
            'icon'  => 'bi-wallet2',
        ];
        $assetItems[] = [
            'key'   => 'recharge',
            'label' => '充值中心',
            'url'   => (string) ($ctx['recharge_url'] ?? ''),
            'icon'  => 'bi-credit-card',
        ];
        $groups[] = $this->pack('assets', '资产与充值', $navActive, $assetItems);

        $groups[] = $this->pack(
            'orders',
            '订单与消费',
            $navActive,
            [
                [
                    'key'   => 'purchases',
                    'label' => '我的购买',
                    'url'   => (string) ($ctx['purchases_url'] ?? ''),
                    'icon'  => 'bi-bag-check',
                ],
                [
                    'key'   => 'consumption',
                    'label' => '消费记录',
                    'url'   => (string) ($ctx['consumption_url'] ?? ''),
                    'icon'  => 'bi-receipt',
                ],
            ],
        );

        $groups[] = $this->pack(
            'account',
            '账号设置',
            $navActive,
            [
                [
                    'key'   => 'profile',
                    'label' => '基本资料',
                    'url'   => (string) ($ctx['profile_page_url'] ?? '/member/profile'),
                    'icon'  => 'bi-person-vcard',
                ],
                [
                    'key'   => 'security',
                    'label' => '账号安全',
                    'url'   => (string) ($ctx['security_url'] ?? ''),
                    'icon'  => 'bi-shield-lock',
                ],
            ],
        );

        if ($pluginNav !== []) {
            $pluginItems = [];
            foreach ($pluginNav as $row) {
                $route = trim((string) ($row['route'] ?? ''));
                if ($route === '') {
                    continue;
                }
                $pluginItems[] = [
                    'key'   => $route,
                    'label' => (string) ($row['label'] ?? ''),
                    'url'   => (string) ($row['url'] ?? ''),
                    'icon'  => trim((string) ($row['icon'] ?? '')) !== '' ? (string) $row['icon'] : 'bi-puzzle',
                    'active' => (int) ($row['is_active'] ?? 0),
                ];
            }
            if ($pluginItems !== []) {
                $expanded = 0;
                foreach ($pluginItems as $item) {
                    if (!empty($item['active'])) {
                        $expanded = 1;
                        break;
                    }
                }
                $groupLabel = trim((string) ($ctx['plugin_nav_group_label'] ?? ''));
                if ($groupLabel === '') {
                    $groupLabel = '扩展服务';
                }
                $groups[] = [
                    'id'       => 'extensions',
                    'label'    => $groupLabel,
                    'expanded' => $expanded,
                    'items'    => $pluginItems,
                ];
            }
        }

        return $groups;
    }

    /**
     * @param list<array{key:string,label:string,url:string,icon:string}> $items
     * @return array{id:string,label:string,expanded:int,items:list<array{key:string,label:string,url:string,icon:string,active:int}>}
     */
    private function pack(string $id, string $label, string $navActive, array $items): array
    {
        $keys     = array_column($items, 'key');
        $expanded = in_array($navActive, $keys, true) ? 1 : 0;
        $outItems = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            $outItems[] = [
                'key'    => $key,
                'label'  => (string) ($item['label'] ?? ''),
                'url'    => (string) ($item['url'] ?? ''),
                'icon'   => (string) ($item['icon'] ?? 'bi-circle'),
                'active' => $navActive === $key ? 1 : 0,
            ];
        }

        return [
            'id'       => $id,
            'label'    => $label,
            'expanded' => $expanded,
            'items'    => $outItems,
        ];
    }
}
