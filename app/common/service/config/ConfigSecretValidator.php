<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\config;

/** 凭据键值形态校验（纯逻辑，可单测；ConfigSecretService 委托） */
final class ConfigSecretValidator
{

    public function looksLikeSecret(string $key, string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (str_contains($key, 'private_key') && str_contains($value, 'BEGIN')) {
            return true;
        }
        if (str_contains($key, '_api_key') || str_contains($key, 'secret')) {
            return strlen($value) >= 8;
        }

        return strlen($value) >= 16;
    }

    public function isLegacyPlaintextValue(string $value, string $placeholder): bool
    {
        $value = trim($value);

        return $value !== '' && $value !== $placeholder;
    }
}
