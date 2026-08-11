<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** catalog / item EXISTS 子查询表别名（避免散落魔法字符串） */
final class CatalogExistsSubqueryAlias
{
    public const EAV = 'pv_e';

    public const ITEM_TAG = 'pv_it';

    private function __construct()
    {
    }
}
