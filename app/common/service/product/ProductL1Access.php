<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\plugin\PluginService;

/** 产品中心能力入口（后台 / SPA / 文档 Tab） */
final class ProductL1Access
{
    public const IDENTIFIER = 'product';

    public static function normalize(string $identifier): string
    {
        return strtolower(trim($identifier));
    }

    public static function isKernel(string $identifier): bool
    {
        if (self::normalize($identifier) !== self::IDENTIFIER) {
            return false;
        }
        $plugins = app(PluginService::class);

        return $plugins->isCoreMerged(self::IDENTIFIER) || $plugins->isL1KernelModule(self::IDENTIFIER);
    }

    public static function allowsAdmin(): bool
    {
        return app(ProductCenterGateService::class)->allowsAdmin();
    }

    public static function allowsParams(): bool
    {
        return app(ProductCenterGateService::class)->allowsParams();
    }

    public static function allowsAdminApi(): bool
    {
        return app(ProductCenterGateService::class)->allowsAdminApi();
    }

    public static function allowsFrontBridge(): bool
    {
        return app(ProductCenterGateService::class)->allowsFrontBridge();
    }

    /**
     * 文档编辑器产品 Tab 默认配置
     *
     * @return array<string, mixed>
     */
    public static function documentEditorManifest(): array
    {
        if (!self::allowsAdmin()) {
            return [];
        }

        return [
            'enabled'        => true,
            'slot'           => 'tab',
            'tab_label'      => '产品展示',
            'tab_order'      => 55,
            'allow_override' => ['tab', 'inline', 'hidden'],
        ];
    }
}
