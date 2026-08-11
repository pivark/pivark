<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use think\facade\Request;
use think\Model;

/**
 * 后台列表批量：ID 规范化、按主键批量删除等。
 * @see docs/03-开发/后台列表Composable参考.md#e2-adminbatchsupport
 */
final class AdminBatchSupport
{
    /**
     * @param list<int|string> $ids
     * @return list<int>
     */
    public static function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * 从 POST 解析批量 id（ids[] / ids=1,2,3 / id=1）。
     * 须优先 ids/a，避免 ThinkPHP 将多值压成单字符串后只删第一条。
     */
    public static function parsePostIds(string $key = 'ids'): array
    {
        $raw = Request::post($key . '/a', Request::post($key, Request::post('id', '')));

        return self::normalizeIds(ParseIds::fromMixed($raw));
    }

    /**
     * 从 POST 解析批量字符串（如 media paths[]）。
     *
     * @return list<string>
     */
    public static function parsePostStringList(string $key = 'paths'): array
    {
        $raw = Request::post($key . '/a', Request::post($key, ''));
        if (is_array($raw)) {
            return array_values(array_filter(
                array_map(static fn ($v): string => trim((string) $v), $raw),
                static fn (string $v): bool => $v !== '',
            ));
        }

        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }

        return array_values(array_filter(
            preg_split('/[\r\n]+/', $text) ?: [],
            static fn (string $v): bool => trim($v) !== '',
        ));
    }

    /**
     * 按 id 批量删除（单表、主键 id）。
     *
     * @param class-string<Model> $modelClass
     * @param list<int|string>    $ids
     * @param callable|null       $after 删除成功后回调（清缓存等）
     * @return ServiceResult
     */
    public static function deleteByIds(
        string $modelClass,
        array $ids,
        ?callable $after = null,
        string $emptyMsg = '请选择记录',
    ): ServiceResult {
        $ids = self::normalizeIds($ids);
        if ($ids === []) {
            return ServiceResult::fail($emptyMsg);
        }

        /** @var Model $modelClass */
        $count = (int) $modelClass::whereIn('id', $ids)->delete();
        if ($count < 1) {
            return ServiceResult::fail('未删除任何记录');
        }

        if ($after !== null) {
            $after();
        }

        return ServiceResult::ok(['count' => $count], '已删除 ' . $count . ' 条');
    }
}
