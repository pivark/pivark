<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document;

use app\common\service\content\ContentSearchService;
use app\common\service\search\SearchConfigService;
use app\common\service\search\SearchFulltextSupport;
use app\common\service\search\SearchMemberContext;

/** DocumentPublicService 搜索/权限过滤依赖包（冗余审计 §2） */
final class DocumentPublicSearchDeps
{
    public function __construct(
        public readonly SearchMemberContext $memberContext,
        public readonly SearchFulltextSupport $fulltext,
        public readonly ContentSearchService $contentSearch,
        public readonly SearchConfigService $searchConfig,
    ) {
    }
}
