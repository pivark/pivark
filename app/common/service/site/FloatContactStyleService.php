<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\model\FloatContactItem;

/** 悬浮联系前台样式预设 */
final class FloatContactStyleService
{

    public const STYLE_SIDEBAR = 'sidebar';
    public const STYLE_ORBS    = 'orbs';
    public const STYLE_RAIL    = 'rail';
    public const STYLE_DOCK    = 'dock';
    public const STYLE_STACK   = 'stack';
    public const STYLE_FAB     = 'fab';
    public const STYLE_EDGE    = 'edge';
    public const STYLE_CARD    = 'card';

    /** @return array<string, array{id:string,title:string,desc:string,hint:string}> */
    public function options(): array
    {
        return [
            self::STYLE_SIDEBAR => [
                'id'    => self::STYLE_SIDEBAR,
                'title' => '侧栏抽屉',
                'desc'  => '右侧竖条展开列表面板，适合条目较多、需展示副标题的场景。',
                'hint'  => '默认',
            ],
            self::STYLE_ORBS => [
                'id'    => self::STYLE_ORBS,
                'title' => '彩色圆球',
                'desc'  => 'QQ / 微信 / VIP 等圆形按钮纵向排列，悬停显示说明，微信可弹出二维码卡片。',
                'hint'  => '电商常用',
            ],
            self::STYLE_RAIL => [
                'id'    => self::STYLE_RAIL,
                'title' => '图标轨',
                'desc'  => '窄条图标按钮，悬停向左滑出标签文字，界面更克制。',
                'hint'  => '简约',
            ],
            self::STYLE_DOCK => [
                'id'    => self::STYLE_DOCK,
                'title' => '底栏胶囊',
                'desc'  => '右下角横向胶囊条，图标 + 短文案，适合 2～4 个入口。',
                'hint'  => '紧凑',
            ],
            self::STYLE_STACK => [
                'id'    => self::STYLE_STACK,
                'title' => '堆叠卡片',
                'desc'  => '右侧纵向卡片，图标与名称始终可见，适合 3～6 项清晰展示。',
                'hint'  => '清晰',
            ],
            self::STYLE_FAB => [
                'id'    => self::STYLE_FAB,
                'title' => '悬浮球菜单',
                'desc'  => '右侧常显方块竖条：图标在上、文案在下；悬停条目显示电话/二维码。',
                'hint'  => '清晰',
            ],
            self::STYLE_EDGE => [
                'id'    => self::STYLE_EDGE,
                'title' => '侧栏圆钮',
                'desc'  => '右侧彩色圆形按钮纵向排列，点击弹出遮罩面板（电话 / 二维码 / 外链），类似常见门户侧栏联系。',
                'hint'  => '弹层',
            ],
            self::STYLE_CARD => [
                'id'    => self::STYLE_CARD,
                'title' => '卡片浮层',
                'desc'  => '常显白色圆角卡片列表，无需点击展开，信息密度高。',
                'hint'  => '信息',
            ],
        ];
    }

    public function normalize(string $style): string
    {
        $style = strtolower(trim($style));

        return isset($this->options()[$style]) ? $style : self::STYLE_SIDEBAR;
    }
}
