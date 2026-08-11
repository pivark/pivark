<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\weapp;

use app\common\service\config\ConfigService;
use app\common\service\infra\FrontCacheInvalidator;

final class WeappPluginSaveSupport
{
    public const SCOPE_META      = 'meta';
    public const SCOPE_DOCUMENTS = 'documents';
    public const SCOPE_TAGS      = 'tags';
    public const SCOPE_ALL       = 'all';

    /**
     * 插件 ConfigService::saveAdmin 成功写入 configs 后调用
     */
    public function afterConfigSaved(string $scope = self::SCOPE_META): void
    {
        app(ConfigService::class)->forgetRequestCache();

        match ($scope) {
            self::SCOPE_DOCUMENTS => app(FrontCacheInvalidator::class)->invalidateDocuments(),
            self::SCOPE_TAGS      => app(FrontCacheInvalidator::class)->invalidateTags(),
            self::SCOPE_ALL       => app(FrontCacheInvalidator::class)->invalidateAll(false),
            default               => app(FrontCacheInvalidator::class)->invalidateMeta(),
        };
    }
}
