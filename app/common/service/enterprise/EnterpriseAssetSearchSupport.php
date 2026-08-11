<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\enterprise;
use app\common\service\content\ContentSearchService;
use app\common\support\DbTable;
use app\common\support\OpsLog;
use think\db\Query;

/** weapp_tender_assets FULLTEXT 检索（P0） */
final class EnterpriseAssetSearchSupport
{

    private static ?bool $available = null;

    public function useFulltext(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }
        if (!app(EnterpriseAssetService::class)->tableExists()) {
            self::$available = false;

            return false;
        }
        try {
            self::$available = DbTable::indexExists(DbTable::name('weapp_tender_assets'), 'ft_weapp_tender_assets');
        } catch (\Throwable $e) {
            OpsLog::businessWarning('enterprise_asset_fulltext_probe_failed', ['msg' => $e->getMessage()]);
            self::$available = false;
        }

        return self::$available;
    }

    public function applyKeyword(Query $query, string $keyword): void
    {
        $keyword = app(ContentSearchService::class)->normalizeKeyword($keyword);
        if ($keyword === '') {
            return;
        }
        if (!$this->useFulltext()) {
            $query->whereLike('title|summary|keywords', app(ContentSearchService::class)->likePattern($keyword));

            return;
        }
        $terms = preg_split('/\s+/u', $keyword) ?: [];
        $parts = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            $parts[] = '+' . addcslashes($term, '+-<>()~*"');
        }
        if ($parts === []) {
            return;
        }
        $against = implode(' ', $parts);
        $query->whereRaw(
            'MATCH(title, summary, keywords) AGAINST (? IN BOOLEAN MODE)',
            [$against]
        );
    }
}
