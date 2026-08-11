<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * Class app\common\model\CatalogFacetStat
 *
 * @property int $id 主键
 * @property int $row_count 命中行数
 * @property string $attr_value 参数值
 * @property string $domain 业务域 items|erp_inventory_ledger|…
 * @property string $param_key 筛选参数键
 * @property string $scope_key 范围键 tag slug / 仓库 ID 等
 * @property string $updated_at 更新时间
 */
class CatalogFacetStat extends Model
{
    protected $name = 'catalog_facet_stats';

        protected $type = [
        'id' => 'integer',
        'row_count' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
