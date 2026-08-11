<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document;

use app\common\support\AppTime;
use app\common\support\DbTable;
use app\common\model\DocumentAttrFlag;

use think\db\Query;
use think\facade\Db;

/** 文档属性标记索引（P0：替代 FIND_IN_SET(attr_flags)） */
final class DocumentAttrFlagIndexService
{

    public function __construct(
        private readonly DocumentAttrHelper $attrs,
    ) {
    }

    public function tableExists(): bool
    {
        return DbTable::modelExists(DocumentAttrFlag::class);
    }

    public function sync(int $documentId, string $flagsCsv): void
    {
        if ($documentId < 1 || !$this->tableExists()) {
            return;
        }
        DocumentAttrFlag::where('document_id', $documentId)->delete();
        $flags = $this->attrs->normalizeAttrFlags($flagsCsv);
        if ($flags === '') {
            return;
        }
        $now = AppTime::now();
        foreach (array_filter(array_map('trim', explode(',', $flags))) as $flag) {
            if (!in_array($flag, DocumentAttrHelper::ATTR_FLAGS, true)) {
                continue;
            }
            DocumentAttrFlag::insert([
                'document_id' => $documentId,
                'flag'        => $flag,
                'created_at'  => $now,
            ]);
        }
    }

    public function applyFlagFilter(Query $query, string $flag): void
    {
        $flag = trim($flag);
        if ($flag === '' || !in_array($flag, DocumentAttrHelper::ATTR_FLAGS, true)) {
            return;
        }
        if ($this->tableExists()) {
            $ids = DocumentAttrFlag::where('flag', $flag)->column('document_id');
            $ids = array_values(array_unique(array_map('intval', $ids ?: [])));
            $query->whereIn('id', $ids !== [] ? $ids : [0]);

            return;
        }
        $query->whereRaw('FIND_IN_SET(?, attr_flags)', [$flag]);
    }

    /**
     * 排除带指定属性的文档（noattr / noflag）。
     */
    public function applyExcludeFlagFilter(Query $query, string $flag): void
    {
        $flag = trim($flag);
        if ($flag === '' || !in_array($flag, DocumentAttrHelper::ATTR_FLAGS, true)) {
            return;
        }
        if ($this->tableExists()) {
            $ids = DocumentAttrFlag::where('flag', $flag)->column('document_id');
            $ids = array_values(array_unique(array_map('intval', $ids ?: [])));
            if ($ids !== []) {
                $query->whereNotIn('id', $ids);
            }

            return;
        }
        $query->whereRaw('(attr_flags IS NULL OR attr_flags = \'\' OR NOT FIND_IN_SET(?, attr_flags))', [$flag]);
    }

    /**
     * @param list<string> $flags OR 关系：含任一属性
     */
    public function applyAnyFlagsFilter(Query $query, array $flags): void
    {
        $flags = array_values(array_filter($flags, static fn (string $f): bool => in_array($f, DocumentAttrHelper::ATTR_FLAGS, true)));
        if ($flags === []) {
            return;
        }
        if ($this->tableExists()) {
            $ids = DocumentAttrFlag::whereIn('flag', $flags)->column('document_id');
            $ids = array_values(array_unique(array_map('intval', $ids ?: [])));
            $query->whereIn('id', $ids !== [] ? $ids : [0]);

            return;
        }
        $query->where(function (Query $q) use ($flags): void {
            $first = true;
            foreach ($flags as $attr) {
                if ($first) {
                    $q->whereRaw('FIND_IN_SET(?, attr_flags)', [$attr]);
                    $first = false;
                } else {
                    $q->whereOrRaw('FIND_IN_SET(?, attr_flags)', [$attr]);
                }
            }
        });
    }
}
