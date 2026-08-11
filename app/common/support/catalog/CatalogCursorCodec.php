<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support\catalog;

/** 列表游标编解码（sort + id 复合排序） */
final class CatalogCursorCodec
{
    /**
     * @return array{0:int,1:int}|null [sort, id]
     */
    public static function decode(string $cursor): ?array
    {
        $cursor = trim($cursor);
        if ($cursor === '') {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return [(int) ($data['s'] ?? 0), (int) ($data['i'] ?? 0)];
    }

    public static function encode(int $sort, int $id): string
    {
        if ($id < 1) {
            return '';
        }
        $json = json_encode(['s' => $sort, 'i' => $id], JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '';
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param \think\db\Query|\think\Model $query
     */
    public static function applyComposite($query, string $cursor, string $sortCol = 'sort', string $idCol = 'id'): void
    {
        $decoded = self::decode($cursor);
        if ($decoded === null) {
            return;
        }
        [$sort, $id] = $decoded;
        $query->where(function ($q) use ($sort, $id, $sortCol, $idCol) {
            $q->where($sortCol, '>', $sort)
                ->whereOr(function ($q2) use ($sort, $id, $sortCol, $idCol) {
                    $q2->where($sortCol, $sort)->where($idCol, '<', $id);
                });
        });
    }
}
