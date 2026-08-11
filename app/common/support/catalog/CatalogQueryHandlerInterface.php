<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support\catalog;

/** 业务域 Catalog 查询处理器（items / erp_inventory_ledger / shop_offers …） */
interface CatalogQueryHandlerInterface
{
    /** 域标识，如 items、erp_inventory_ledger */
    public function domain(): string;

    /** 是否已安装并可查询 */
    public function isAvailable(): bool;

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int,next_cursor?:string,cursor?:string}
     */
    public function list(array $params): array;

    /**
     * @param array<string, mixed> $context 当前 URL 查询
     * @return list<array<string, mixed>>
     */
    public function filterOptions(array $context = []): array;
}
