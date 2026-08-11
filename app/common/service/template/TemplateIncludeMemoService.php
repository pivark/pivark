<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;


/** {pv:include} 请求内幂等缓存：同参重复 include 不重复 parse */
final class TemplateIncludeMemoService
{

    /** @var array<string, string> */
    private static array $memo = [];

    public function reset(): void
    {
        self::$memo = [];
    }

    public function remember(string $key, callable $loader): string
    {
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        return self::$memo[$key] = $loader();
    }

    /**
     * @param array<string, mixed> $attrs  include 属性（含 file）
     * @param array<string, mixed> $pageVars 当前解析上下文变量
     */
    public function buildKey(string $theme, string $path, array $attrs, array $pageVars): string
    {
        $attrSlice = [];
        foreach ($attrs as $name => $value) {
            if ($name === 'file') {
                continue;
            }
            $attrSlice[$name] = $value;
        }
        ksort($attrSlice);

        $ctx = [
            'theme'  => $theme,
            'path'   => str_replace('\\', '/', $path),
            'member' => TemplateEngineState::$memberTemplateRender ? 1 : 0,
            'attrs'  => $attrSlice,
            'vars'   => app(TemplateParseCacheService::class)->pageVarsFingerprint($pageVars),
        ];

        return 'inc:' . hash('sha256', json_encode($ctx, JSON_UNESCAPED_UNICODE));
    }
}
