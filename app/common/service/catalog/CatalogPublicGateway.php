<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\catalog;


/**
 * Catalog 跨域查询可注入门面（Phase 2 DI 试点：v1 Catalog API）。
 */
final class CatalogPublicGateway
{

    /** @return list<array<string, mixed>> */
    public function manifest(): array
    {
        return app(CatalogQueryRegistry::class)->manifest();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function query(string $domain, array $params = []): array
    {
        return app(CatalogQueryService::class)->catalog($domain, $params);
    }
}
