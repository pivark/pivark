<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\support\ServiceResult;

use app\common\service\weapp\WeappItemGateway;
use app\common\model\Item;
use app\common\model\ItemAttrValue;

/** product_param_defs 与品项 attrs 校验、归一化 */
final class ProductParamValidator
{
    /**
     * @param array<string, mixed> $attrs
     * @return ServiceResult
     */
    public static function normalizeAttrs(array $attrs): ServiceResult
    {
        if (!ProductCenterGateService::entitled()) {
            return ServiceResult::ok(['attrs' => $attrs], '');
        }
        $defs = ProductService::listParamDefs();
        if ($defs === []) {
            return ServiceResult::ok(['attrs' => $attrs], '');
        }

        $allowedKeys = [];
        foreach ($defs as $def) {
            $key = (string) ($def['param_key'] ?? '');
            if ($key !== '') {
                $allowedKeys[$key] = $def;
            }
        }

        $out = [];
        foreach ($attrs as $rawKey => $rawVal) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $rawKey)) ?? '';
            if ($key === '' || !isset($allowedKeys[$key])) {
                continue;
            }
            $val = trim((string) $rawVal);
            if ($val === '') {
                continue;
            }
            $def       = $allowedKeys[$key];
            $inputType = (string) ($def['input_type'] ?? 'text');
            $options = is_array($def['options'] ?? null) ? $def['options'] : [];
            $optionValues = ProductService::paramOptionValues($options);
            if ($inputType === 'select') {
                if ($optionValues !== [] && !in_array($val, $optionValues, true)) {
                    $label = (string) ($def['label'] ?? $key);

                    return ServiceResult::fail("{$label} 取值不在可选范围内：{$val}");
                }
            } elseif ($inputType === 'multi_select') {
                $parts = preg_split('/[,，]/u', $val) ?: [];
                $norm = [];
                foreach ($parts as $part) {
                    $p = trim((string) $part);
                    if ($p === '') {
                        continue;
                    }
                    if ($optionValues !== [] && !in_array($p, $optionValues, true)) {
                        $label = (string) ($def['label'] ?? $key);

                        return ServiceResult::fail("{$label} 取值不在可选范围内：{$p}");
                    }
                    $norm[] = $p;
                }
                if ($norm === []) {
                    continue;
                }
                $val = implode(',', $norm);
            }
            $out[$key] = mb_substr($val, 0, 500);
        }

        return ServiceResult::ok(['attrs' => $out], '');
    }

    public static function paramKeyInUse(string $paramKey): bool
    {
        $paramKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($paramKey))) ?? '';
        if ($paramKey === '') {
            return false;
        }
        if (app(WeappItemGateway::class)->itemAttrTableExists()) {
            $n = (int) ItemAttrValue::where('param_key', $paramKey)
                ->limit(1)
                ->count();
            if ($n > 0) {
                return true;
            }
        }
        $jsonPath = '$."' . $paramKey . '"';
        try {
            return Item::whereRaw(
                'JSON_UNQUOTE(JSON_EXTRACT(`attrs`, ?)) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(`attrs`, ?)) <> ?',
                [$jsonPath, $jsonPath, '']
            )->limit(1)->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
