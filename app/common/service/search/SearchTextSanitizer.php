<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

/** 写入 search_text / 展示摘要时的脱敏 */
final class SearchTextSanitizer
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
    ) {
    }

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'contact', 'phone', 'mobile', 'tel', 'email', 'wechat', 'wx', 'qq', 'id_card',
    ];

    public function shouldOmitFieldKey(string $key): bool
    {
        $key = strtolower(trim($key));

        return in_array($key, self::SENSITIVE_KEYS, true)
            || str_contains($key, 'phone')
            || str_contains($key, 'mobile')
            || str_contains($key, 'email')
            || str_contains($key, 'contact');
    }

    public function maskLine(string $line): string
    {
        $line = trim($line);
        if ($line === '') {
            return '';
        }
        $out = preg_replace(
            '/\b1[3-9]\d{9}\b/u',
            '[电话已省略]',
            $line
        ) ?? $line;
        $out = preg_replace(
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/u',
            '[邮箱已省略]',
            $out
        ) ?? $out;
        foreach ($this->searchConfig->sensitiveChars() as $ch) {
            if ($ch !== '') {
                $out = str_replace($ch, '', $out);
            }
        }

        return $out;
    }

    public function omitPlaceholder(): string
    {
        return '[联系方式已省略]';
    }
}
