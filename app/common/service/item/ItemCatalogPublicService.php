<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\model\Item;
use app\common\model\ProductParamDef;
use app\common\model\ProductParamGroup;
use app\common\service\catalog\CatalogQueryService;
use app\common\service\product\ProductCenterGateService;
use app\common\support\DbTable;
use app\common\support\ItemAttrKeyGuard;
use app\common\support\QueryLimit;

/** 前台品项目录：列表 + 筛选元数据（单次请求） */
final class ItemCatalogPublicService
{

    public function __construct(
        private readonly CatalogQueryService $catalogQuery,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function catalog(array $params = []): array
    {
        $result = $this->catalogQuery->catalog('items', $params);
        unset($result['domain']);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function filterOptions(array $params): array
    {
        if (ProductCenterGateService::requirePublicApi() !== null) {
            return [];
        }

        return $this->kernelFilterOptions($params);
    }

    /**
     * 读 product_param_defs + 品项 attrs 生成筛选项。
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function kernelFilterOptions(array $params): array
    {
        if (!DbTable::modelExists(ProductParamDef::class)) {
            return [];
        }

        $groupMap = $this->paramGroupLabelMap();
        $defs     = ProductParamDef::where('filterable', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if ($defs === []) {
            return [];
        }

        $facet        = app(ItemFilterFacetService::class);
        $attrDistinct = app(ItemAttrValueService::class);
        $out          = [];

        foreach ($defs as $row) {
            $key = (string) ($row['param_key'] ?? '');
            if ($key === '' || !ItemAttrKeyGuard::isSafe($key)) {
                continue;
            }

            $gid     = (int) ($row['group_id'] ?? 0);
            $options = json_decode((string) ($row['options_json'] ?? '[]'), true);
            if (!is_array($options)) {
                $options = [];
            }
            if ($options === []) {
                if ($attrDistinct->tableExists()) {
                    $options = $attrDistinct->distinctValues($key);
                }
                if ($options === []) {
                    $options = Item::where('status', ItemService::STATUS_ACTIVE)
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(attrs, '$.\"{$key}\"')) IS NOT NULL")
                        ->limit(200)
                        ->column("JSON_UNQUOTE(JSON_EXTRACT(attrs, '$.\"{$key}\"'))");
                    $options = array_values(array_unique(array_filter(array_map('strval', $options ?: []))));
                }
            }

            $counts     = $facet->countsForParam($key, $params);
            $hasContext = $facet->normalizeFilterParams($params) !== []
                || trim((string) ($params['tag'] ?? '')) !== '';
            $optionRows = [];
            foreach ($options as $opt) {
                if (is_array($opt)) {
                    $val = trim((string) ($opt['value'] ?? $opt['id'] ?? ''));
                    $lab = trim((string) ($opt['label'] ?? $opt['name'] ?? ''));
                    if ($val === '' && $lab !== '') {
                        $val = $lab;
                    }
                    if ($lab === '') {
                        $lab = $val;
                    }
                } else {
                    $val = trim((string) $opt);
                    $lab = $val;
                }
                if ($val === '') {
                    continue;
                }
                $cnt = (int) ($counts[$val] ?? 0);
                $optionRows[] = [
                    'value'    => $val,
                    'label'    => $lab,
                    'count'    => $cnt,
                    'disabled' => $hasContext && $cnt < 1,
                ];
            }

            $out[] = [
                'param_key'   => $key,
                'label'       => (string) ($row['label'] ?? $key),
                'group_id'    => $gid,
                'group_key'   => (string) ($groupMap[$gid]['group_key'] ?? ''),
                'group_label' => (string) ($groupMap[$gid]['label'] ?? ''),
                'options'     => $optionRows,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{group_key:string,label:string}>
     */
    private function paramGroupLabelMap(): array
    {
        if (!DbTable::modelExists(ProductParamGroup::class)) {
            return [];
        }

        $map = [];
        foreach (ProductParamGroup::order('sort', 'asc')
            ->limit(QueryLimit::PRODUCT_PARAM_GROUPS)
            ->select()
            ->toArray() as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $map[$id] = [
                'group_key' => (string) ($row['group_key'] ?? ''),
                'label'     => (string) ($row['label'] ?? ''),
            ];
        }

        return $map;
    }
}
