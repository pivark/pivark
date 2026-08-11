<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\contract\PluginApiCallableTrait;
use app\common\contract\PluginApiInterface;

/** PluginApiRegistry 适配器：实现在 ProductPublicApiService */
final class ProductPublicApi implements PluginApiInterface
{
    use PluginApiCallableTrait;

    public function pluginIdentifier(): string
    {
        return 'product';
    }

    public function isActive(): bool
    {
        return ProductCenterGateService::publicSurfaceOpen();
    }

    /**
     * @param array<string, mixed> $row 品项公开读行
     * @return array<string, mixed>
     */
    public function enrichItemRead(array $row): array
    {
        return ProductPublicApiService::enrichItemRead($row);
    }
}
