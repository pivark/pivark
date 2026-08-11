<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

/** 品项报价/可售 overlay 桥接插件 SSOT（L1 只读 identifier，不写死插件 ID） */
final class PluginOfferBridgeRegistry
{
    private static ?string $identifier = null;

    private static ?string $autoloadClass = null;

    /** @var list<string> 历史支付 scene（与 identifier 不同、仍须筛/展示时使用；由插件 boot 登记） */
    private static array $legacyPaymentScenes = [];

    private static string $adminOrderSceneLabel = '';

    public function reset(): void
    {
        self::$identifier           = null;
        self::$autoloadClass        = null;
        self::$legacyPaymentScenes  = [];
        self::$adminOrderSceneLabel = '';
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || self::$identifier !== $identifier) {
            return;
        }
        self::$identifier           = null;
        self::$autoloadClass        = null;
        self::$legacyPaymentScenes  = [];
        self::$adminOrderSceneLabel = '';
    }

    /**
     * @param list<string> $legacyPaymentScenes
     */
    public function register(
        string $identifier,
        ?string $autoloadClass = null,
        array $legacyPaymentScenes = [],
        string $adminOrderSceneLabel = '',
    ): void {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        self::$identifier = $identifier;
        $autoloadClass = trim((string) $autoloadClass);
        self::$autoloadClass = $autoloadClass !== '' ? $autoloadClass : null;
        self::$legacyPaymentScenes = [];
        foreach ($legacyPaymentScenes as $scene) {
            $scene = strtolower(trim((string) $scene));
            if ($scene !== '' && $scene !== $identifier) {
                self::$legacyPaymentScenes[] = $scene;
            }
        }
        self::$adminOrderSceneLabel = trim($adminOrderSceneLabel);
    }

    public function identifier(): ?string
    {
        return self::$identifier;
    }

    public function autoloadClass(): ?string
    {
        return self::$autoloadClass;
    }

    /** 后台支付订单 · 报价桥 scene 展示名（插件可覆盖；内核默认「报价订单」） */
    public function adminOrderSceneLabel(): string
    {
        return self::$adminOrderSceneLabel !== '' ? self::$adminOrderSceneLabel : '报价订单';
    }

    /**
     * 后台 scene 筛选项取值（当前 identifier + 插件登记的历史 scene，去重）
     *
     * @return list<string>
     */
    public function paymentSceneFilterValues(): array
    {
        $id = $this->identifier();
        if ($id === null || $id === '') {
            return [];
        }
        $values = [$id];
        foreach (self::$legacyPaymentScenes as $legacy) {
            $values[] = $legacy;
        }

        return array_values(array_unique($values));
    }

    public function matchesPaymentScene(string $scene): bool
    {
        $scene = trim($scene);
        if ($scene === '') {
            return false;
        }

        return in_array($scene, $this->paymentSceneFilterValues(), true);
    }
}
