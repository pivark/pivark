<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use app\common\model\User;
use think\Model;

/**
 * 将 with() 预加载的 belongsTo 字段合并进扁平数组，兼容既有 Service 格式化器。
 */
final class ModelRelationLoad
{
    /**
     * @param array<string, mixed>|object $row
     * @param list<string>|array<string, string> $fieldMap 关联字段名，或 outKey => relField
     * @return array<string, mixed>
     */
    public static function mergeBelongsTo(array|object $row, string $relation, array $fieldMap): array
    {
        $data = is_array($row) ? $row : self::rowToArray($row);
        $rel  = self::relationPayload($row, $relation);
        if ($rel === []) {
            return $data;
        }
        foreach ($fieldMap as $relField => $outKey) {
            if (is_int($relField)) {
                $outKey   = (string) $outKey;
                $relField = $outKey;
            }
            if (array_key_exists($relField, $rel)) {
                $data[$outKey] = $rel[$relField];
            }
        }
        unset($data[$relation]);

        return $data;
    }

    /**
     * @param iterable<mixed> $models
     * @param list<string>|array<string, string> $fieldMap
     * @return list<array<string, mixed>>
     */
    public static function mapBelongsTo(iterable $models, string $relation, array $fieldMap): array
    {
        $out = [];
        foreach ($models as $model) {
            $out[] = self::mergeBelongsTo($model, $relation, $fieldMap);
        }

        return $out;
    }

    /**
     * 批量加载用户 id/username/nickname，供列表 enrichment 使用。
     *
     * @param list<int>|array<int, int> $userIds
     * @return array<int, array{id:int, username:string, nickname:string}>
     */
    public static function indexUsersBasicByIds(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }

        $users = [];
        foreach (User::whereIn('id', $ids)->field('id,username,nickname')->select()->toArray() as $user) {
            if (!is_array($user)) {
                continue;
            }
            $id = (int) ($user['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $users[$id] = [
                'id'       => $id,
                'username' => (string) ($user['username'] ?? ''),
                'nickname' => (string) ($user['nickname'] ?? ''),
            ];
        }

        return $users;
    }

    /**
     * @param array<string, mixed>|object $row
     * @return array<string, mixed>
     */
    private static function relationPayload(array|object $row, string $relation): array
    {
        $rel = null;
        if (is_object($row) && isset($row->$relation)) {
            $rel = $row->$relation;
        } elseif (is_array($row) && isset($row[$relation])) {
            $rel = $row[$relation];
        }
        if ($rel === null || $rel === [] || $rel === '') {
            return [];
        }
        if (is_array($rel)) {
            return $rel;
        }
        if (is_object($rel) && method_exists($rel, 'toArray')) {
            return $rel->toArray();
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowToArray(object $row): array
    {
        if ($row instanceof Model) {
            return $row->toArray();
        }
        if (method_exists($row, 'toArray')) {
            /** @var array<string, mixed> $data */
            $data = $row->toArray();

            return $data;
        }

        return [];
    }
}
