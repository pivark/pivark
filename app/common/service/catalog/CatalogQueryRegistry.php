<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\catalog;

use app\common\support\catalog\CatalogQueryHandlerInterface;

/** 业务域 Catalog 处理器注册表 */
final class CatalogQueryRegistry
{

    /** @var array<string, CatalogQueryHandlerInterface> */
    private static array $handlers = [];

    private static bool $booted = false;

    public function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        $this->register(new Handlers\ItemCatalogQueryHandler());
        $this->register(new Handlers\ErpInventoryLedgerCatalogHandler());
        $this->register(new Handlers\MesWorkOrderCatalogHandler());
        $this->register(new Handlers\OaApprovalCatalogHandler());
        $this->register(new Handlers\OfferCatalogHandler());
    }

    public function register(CatalogQueryHandlerInterface $handler): void
    {
        self::$handlers[$handler->domain()] = $handler;
    }

    public function get(string $domain): ?CatalogQueryHandlerInterface
    {
        $this->boot();
        $domain = strtolower(trim($domain));

        return self::$handlers[$domain] ?? null;
    }

    /** @return list<string> */
    public function domains(): array
    {
        $this->boot();

        return array_keys(self::$handlers);
    }

    /** @return list<array{domain:string,available:bool}> */
    public function manifest(): array
    {
        $this->boot();
        $out = [];
        foreach (self::$handlers as $domain => $handler) {
            $out[] = [
                'domain'    => $domain,
                'available' => $handler->isAvailable(),
            ];
        }

        return $out;
    }
}
