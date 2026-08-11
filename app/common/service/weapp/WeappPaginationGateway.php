<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappPaginationGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\infra\PaginationService;

final class WeappPaginationGateway
{

    public function __construct(
        private readonly PaginationService $pagination,
    ) {
    }

    /** @return array<string, mixed> */
    public function paginationBuild(int $page, int $total, int $limit, callable $pageUrl): array
    {
        return $this->pagination->build($page, $total, $limit, $pageUrl);
    }

    /** @param array<string, mixed> $vars */
    public function paginationRenderHtml(array $vars, array $attrs = []): string
    {
        return $this->pagination->renderHtml($vars, $attrs);
    }
}
