<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\config;

use app\common\support\ServiceResult;

final class ConfigValueSchemaService
{

    /**
     * @param array<string, mixed> $data
     */
    public function validatePatch(array $data): ?ServiceResult
    {
        $schema = config('pivark.config_value_schema');
        if (!is_array($schema)) {
            return null;
        }
        foreach ($data as $key => $value) {
            if (!is_string($key) || !isset($schema[$key]) || !is_array($schema[$key])) {
                continue;
            }
            $rule = $schema[$key];
            $type = (string) ($rule['type'] ?? 'string');
            $err  = match ($type) {
                'bool' => $this->validateBool($key, $value),
                'int' => $this->validateInt($key, $value, $rule),
                'enum' => $this->validateEnum($key, $value, $rule),
                default => null,
            };
            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }

    private function validateBool(string $key, mixed $value): ?ServiceResult
    {
        if (is_bool($value)) {
            return null;
        }
        if (is_scalar($value) && in_array(strtolower(trim((string) $value)), ['0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            return null;
        }

        return ServiceResult::fail('配置项「' . $key . '」必须为开关值 0/1');
    }

    /** @param array<string, mixed> $rule */
    private function validateInt(string $key, mixed $value, array $rule): ?ServiceResult
    {
        if (!is_numeric($value)) {
            return ServiceResult::fail('配置项「' . $key . '」必须为整数');
        }
        $int = (int) $value;
        if (isset($rule['min']) && $int < (int) $rule['min']) {
            return ServiceResult::fail('配置项「' . $key . '」不能小于 ' . (int) $rule['min']);
        }
        if (isset($rule['max']) && $int > (int) $rule['max']) {
            return ServiceResult::fail('配置项「' . $key . '」不能大于 ' . (int) $rule['max']);
        }

        return null;
    }

    /** @param array<string, mixed> $rule */
    private function validateEnum(string $key, mixed $value, array $rule): ?ServiceResult
    {
        $allowed = $rule['values'] ?? [];
        if (!is_array($allowed) || $allowed === []) {
            return null;
        }
        $str = is_scalar($value) ? trim((string) $value) : '';
        if (!in_array($str, array_map('strval', $allowed), true)) {
            return ServiceResult::fail('配置项「' . $key . '」取值不在允许范围内');
        }

        return null;
    }
}
