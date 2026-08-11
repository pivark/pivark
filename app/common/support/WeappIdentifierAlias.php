<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/**
 * 插件 identifier 遗留别名 → plugin.json identifier（装完后后台/权限仍会用）
 */
final class WeappIdentifierAlias
{
    /** 安装向导遗留 id（download）与 plugin.json identifier（doc_bundle）对齐 */
    public static function normalize(string $pluginId): string
    {
        $pluginId = strtolower(trim($pluginId));

        return match ($pluginId) {
            'download' => 'doc_bundle',
            'video'    => 'doc_vod',
            'gallery'  => 'doc_gallery',
            'comment'  => 'doc_comment',
            'ask'      => 'doc_ask',
            'talent'   => 'doc_talent',
            'thumb'    => 'doc_thumb',
            default    => $pluginId,
        };
    }
}
