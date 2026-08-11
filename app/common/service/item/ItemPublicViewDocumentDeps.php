<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\service\document\DocumentFormatService;
use app\common\service\document\satellite\DocumentPluginAvailabilityService;
use app\common\service\document\satellite\DocumentQrService;
use app\common\service\document\DocumentPublicService;

/** ItemPublicViewService 主文档/插件区块依赖包 */
final class ItemPublicViewDocumentDeps
{
    public function __construct(
        public readonly DocumentFormatService $documentFormatService,
        public readonly DocumentQrService $documentQrService,
        public readonly DocumentPluginAvailabilityService $documentPluginAvailabilityService,
        public readonly DocumentPublicService $documentService,
    ) {
    }
}
