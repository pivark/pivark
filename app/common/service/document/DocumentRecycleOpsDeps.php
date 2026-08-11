<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document;

use app\common\service\audit\AuditLogService;
use app\common\service\event\EventBusService;
use app\common\service\media\MediaAssetRefService;
use app\common\service\search\SearchIndexService;
use app\common\service\site\SiteModeService;
use app\common\service\static\StaticHtmlDispatch;
use app\common\service\tag\TagService;

/** DocumentRecycleService 内核副作用依赖包（冗余审计 §2） */
final class DocumentRecycleOpsDeps
{
    public function __construct(
        public readonly AuditLogService $auditLogService,
        public readonly SiteModeService $siteModeService,
        public readonly SearchIndexService $searchIndexService,
        public readonly StaticHtmlDispatch $staticHtmlDispatch,
        public readonly EventBusService $eventBusService,
        public readonly MediaAssetRefService $mediaAssetRefService,
        public readonly TagService $tagService,
    ) {
    }
}
