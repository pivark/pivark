<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\static;
use app\common\support\ServiceResult;

/** 后台静态 HTML 生成（DI 入口） */
final class StaticHtmlAdminGateway
{

    public function __construct(
        private readonly StaticHtmlService $staticHtml,
    ) {
    }

    public function enabled(): bool
    {
        return $this->staticHtml->enabled();
    }

    /**
     * @return array{written:int,skipped:int,deleted:int,errors:list<string>}
     */
    public function rebuildAll(bool $purgeFirst = true): array
    {
        return $this->staticHtml->rebuildAll($purgeFirst);
    }

    /**
     * @return ServiceResult
     */
    public function generateAdmin(string $scope, int $id = 0): ServiceResult
    {
        return $this->staticHtml->generateAdmin($scope, $id);
    }

    /**
     * @param array{written?:int,skipped?:int,deleted?:int,errors?:list<string>}|null $stats
     */
    public function purgeAll(?array &$stats = null): void
    {
        $this->staticHtml->purgeAll($stats);
    }
}
