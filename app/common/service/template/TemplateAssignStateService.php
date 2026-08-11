<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

/** `{pv:assign}` 请求内模板赋值（`beginRequest` 清空） */
class TemplateAssignStateService
{

    /** @var array<string, scalar> */
    private static array $vars = [];

    public function reset(): void
    {
        self::$vars = [];
    }

    /**
     * @param scalar $value
     */
    public function set(string $name, mixed $value): void
    {
        $name = trim($name);
        if ($name === '' || !preg_match('/^[a-zA-Z_][\w]*$/', $name)) {
            return;
        }
        if (is_array($value) || $value === null) {
            return;
        }
        self::$vars[$name] = is_bool($value) ? ($value ? '1' : '0') : $value;
    }

    /**
     * @return array<string, scalar>
     */
    public function all(): array
    {
        return self::$vars;
    }
}
