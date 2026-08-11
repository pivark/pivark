<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\support\ItemAttrKeyGuard;
use app\common\model\Item;

/**
 * 品项 keyword 检索（含 product attrs 参数列）。
 *
 * 仅由内核 {@see \app\common\service\search\ItemListSearchService} 在
 * Meili/Elastic 不可用且允许 SQL 降级时调用；搜索引擎路径走 items 索引（attrs 已编入 search_text）。
 */
final class ProductItemSearchService
{
    /**
     * @param \think\db\Query<Item>|mixed $query
     */
    public static function applyKeywordFilter($query, string $keyword): void
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return;
        }
        if (!ProductCenterGateService::entitled()) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('code', '%' . $keyword . '%')
                    ->whereOr('name', 'like', '%' . $keyword . '%')
                    ->whereOr('slug', 'like', '%' . $keyword . '%');
            });

            return;
        }
        $defs = ProductService::listParamDefs();
        $query->where(function ($q) use ($keyword, $defs) {
            $q->whereLike('code', '%' . $keyword . '%')
                ->whereOr('name', 'like', '%' . $keyword . '%')
                ->whereOr('slug', 'like', '%' . $keyword . '%');
            foreach ($defs as $def) {
                $key = (string) ($def['param_key'] ?? '');
                if ($key === '' || !ItemAttrKeyGuard::isSafe($key)) {
                    continue;
                }
                $q->whereOrRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(`attrs`, '$.\"{$key}\"')) LIKE ?",
                    ['%' . $keyword . '%'],
                );
            }
        });
    }
}
