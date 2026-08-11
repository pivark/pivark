<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment;

use app\common\support\ServiceResult;

use app\common\service\member\MemberRechargeService;
use app\common\service\plugin\commerce\PluginCommerceService;

/** 支付履约 scene → handler 注册表（插件 boot 可扩展，内核场景 bootstrap 注册） */
final class PaymentFulfillmentRegistry
{

    /** @var array<string, callable(array<string,mixed>): ServiceResult> */
    private static array $handlers = [];

    /** @var array<string, array{label:string, host_only:bool}> */
    private static array $sceneMeta = [];

    private static bool $coreBootstrapped = false;

    public function reset(): void
    {
        self::$handlers = [];
        self::$sceneMeta = [];
        self::$coreBootstrapped = false;
    }

    /**
     * @param callable(array<string,mixed>): ServiceResult $handler
     */
    public function register(string $scene, callable $handler, string $label = '', bool $hostOnly = false): void
    {
        $scene = trim($scene);
        if ($scene === '') {
            return;
        }
        self::$handlers[$scene] = $handler;
        if ($label !== '') {
            self::$sceneMeta[$scene] = ['label' => $label, 'host_only' => $hostOnly];
        }
    }

    public function sceneLabel(string $scene): ?string
    {
        $this->ensureCoreBootstrapped();
        $scene = trim($scene);

        return self::$sceneMeta[$scene]['label'] ?? null;
    }

    /** @return list<string> */
    public function hostOnlySceneKeys(): array
    {
        $this->ensureCoreBootstrapped();
        $out = [];
        foreach (self::$sceneMeta as $scene => $meta) {
            if ($meta['host_only']) {
                $out[] = $scene;
            }
        }

        return $out;
    }

    /**
     * @return list<array{value:string, label:string}>
     */
    public function hostOnlySceneFilterOptions(): array
    {
        $out = [];
        foreach ($this->hostOnlySceneKeys() as $scene) {
            $label = $this->sceneLabel($scene);
            if ($label !== null && $label !== '') {
                $out[] = ['value' => $scene, 'label' => $label];
            }
        }

        return $out;
    }

    public function has(string $scene): bool
    {
        $this->ensureCoreBootstrapped();

        return isset(self::$handlers[trim($scene)]);
    }

    /**
     * @param array<string, mixed> $order
     * @return ServiceResult
     */
    public function dispatch(array $order): ServiceResult
    {
        $this->ensureCoreBootstrapped();
        $scene = trim((string) ($order['scene'] ?? ''));
        if ($scene === '' || !isset(self::$handlers[$scene])) {
            return ServiceResult::fail('未知支付场景：' . $scene);
        }

        return (self::$handlers[$scene])($order);
    }

    /**
     * @return list<array{value:string, label:string}>
     */
    public function pluginSceneFilterOptions(bool $excludeHostOnly = true): array
    {
        $this->ensureCoreBootstrapped();
        $coreScenes = [
            PaymentOrderService::SCENE_RECHARGE,
            PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT,
        ];
        $out = [];
        foreach (self::$sceneMeta as $scene => $meta) {
            if (in_array($scene, $coreScenes, true)) {
                continue;
            }
            if ($excludeHostOnly && !empty($meta['host_only'])) {
                continue;
            }
            $label = trim((string) ($meta['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $out[] = ['value' => $scene, 'label' => $label];
        }

        return $out;
    }

    public function ensureCoreBootstrapped(): void
    {
        if (self::$coreBootstrapped) {
            return;
        }
        self::$coreBootstrapped = true;
        $this->register(
            PaymentOrderService::SCENE_RECHARGE,
            fn (array $order): ServiceResult => app(MemberRechargeService::class)->fulfillOrder($order),
            '会员充值',
        );
        $this->register(
            PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT,
            static fn (array $order): ServiceResult => app(PluginCommerceService::class)->fulfillEntitlement($order),
            '插件授权',
        );
    }
}
