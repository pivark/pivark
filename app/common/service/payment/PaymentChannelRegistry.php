<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment;

use app\common\service\payment\gateway\AlipayGateway;
use app\common\service\payment\gateway\PaymentGatewayInterface;
use app\common\service\payment\gateway\WechatPayGateway;
use app\common\support\AppService;

/**
 * 支付通道注册表（官方内置 + 插件扩展 SSOT）
 *
 * 插件在 boot 中通过 WeappPaymentGateway::paymentChannelRegister() 注册；
 * 须实现 PaymentGatewayInterface，业务下单仍走 PaymentOrderService。
 *
 * @phpstan-type PaymentChannelDef array{
 *   id: string,
 *   title: string,
 *   label: string,
 *   label_long?: string,
 *   kind: 'online'|'balance',
 *   builtin?: bool,
 *   owner?: string,
 *   sort?: int,
 *   gateway_class?: class-string<PaymentGatewayInterface>,
 *   open_config_key?: string,
 *   supports_demo?: bool,
 *   scene_button_classes?: array<string, string>,
 *   ready?: callable(PaymentConfigService): bool,
 *   gateway_config?: callable(PaymentConfigService): array<string, string>,
 *   notify_success_body?: callable(): string,
 * }
 */
final class PaymentChannelRegistry
{

    /** @var array<string, PaymentChannelDef> */
    private static array $channels = [];

    private static bool $coreBootstrapped = false;

    public function reset(): void
    {
        self::$channels       = [];
        self::$coreBootstrapped = false;
    }

    /**
     * 注册扩展通道（禁止覆盖官方 builtin id）
     *
     * @param PaymentChannelDef $definition
     */
    public function register(array $definition): void
    {
        $id = strtolower(trim((string) $definition['id']));
        if ($id === '' || !preg_match('/^[a-z][a-z0-9_]{0,47}$/', $id)) {
            return;
        }
        if (($definition['builtin'] ?? false) || (self::$channels[$id]['builtin'] ?? false)) {
            return;
        }
        $definition['id']      = $id;
        $definition['kind']    = $definition['kind'] === 'balance' ? 'balance' : 'online';
        $definition['builtin'] = false;
        $definition['owner']   = trim((string) ($definition['owner'] ?? ''));
        $definition['sort']    = (int) ($definition['sort'] ?? 100);
        if ($definition['kind'] === 'online' && empty($definition['gateway_class'])) {
            return;
        }
        self::$channels[$id] = $definition;
    }

    public function has(string $id): bool
    {
        $this->ensureCoreBootstrapped();

        return isset(self::$channels[strtolower(trim($id))]);
    }

    /** @return PaymentChannelDef|null */
    public function get(string $id): ?array
    {
        $this->ensureCoreBootstrapped();
        $id = strtolower(trim($id));

        return self::$channels[$id] ?? null;
    }

    /**
     * @return list<string> 已注册通道 id（按 sort）
     *
     * @param 'online'|'balance'|'all' $kind
     */
    public function ids(string $kind = 'online'): array
    {
        $this->ensureCoreBootstrapped();
        $rows = [];
        foreach (self::$channels as $id => $def) {
            $rowKind = (string) $def['kind'];
            if ($kind === 'online' && $rowKind !== 'online') {
                continue;
            }
            if ($kind === 'balance' && $rowKind !== 'balance') {
                continue;
            }
            $rows[] = ['id' => $id, 'sort' => (int) ($def['sort'] ?? 100)];
        }
        usort($rows, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort'] ?: strcmp($a['id'], $b['id']));

        return array_column($rows, 'id');
    }

    public function isOnline(string $id): bool
    {
        $def = $this->get($id);

        return is_array($def) && $def['kind'] === 'online';
    }

    public function openConfigKey(string $id): string
    {
        $def = $this->get($id);
        if (!is_array($def)) {
            return 'payment_channel_' . strtolower(trim($id)) . '_open';
        }
        if (!empty($def['open_config_key'])) {
            return (string) $def['open_config_key'];
        }

        return 'payment_channel_' . (string) $def['id'] . '_open';
    }

    public function supportsDemo(string $id): bool
    {
        $def = $this->get($id);

        return is_array($def) && ($def['supports_demo'] ?? false);
    }

    public function title(string $id): string
    {
        $def = $this->get($id);

        if (!is_array($def)) {
            return $id;
        }
        $title = (string) $def['title'];

        return $title !== '' ? $title : $id;
    }

    public function buttonLabel(string $id, bool $long = false): string
    {
        $def = $this->get($id);
        if (!is_array($def)) {
            return $id;
        }
        if ($long) {
            $label = (string) ($def['label_long'] ?? $def['label']);

            return $label !== '' ? $label : (string) ($def['title'] !== '' ? $def['title'] : $id);
        }
        $label = (string) $def['label'];

        return $label !== '' ? $label : (string) ($def['title'] !== '' ? $def['title'] : $id);
    }

    public function buttonClass(string $id, string $scene): string
    {
        $def = $this->get($id);
        if (is_array($def) && !empty($def['scene_button_classes'][$scene])) {
            return (string) $def['scene_button_classes'][$scene];
        }

        return match ($scene) {
            'member_recharge'   => 'btn btn-sm btn-outline-secondary pv-recharge-pay',
            'member_custom'     => 'btn btn-outline-secondary btn-sm pv-recharge-custom-pay',
            'portal_activate'   => 'pv-btn pv-btn--ghost pv-btn--block pv-activate-pay',
            'portal_upgrade'    => 'pv-btn pv-btn--ghost pv-upgrade-pay',
            'portal_deposit'    => 'pv-btn pv-btn--ghost pv-shop-deposit-pay',
            default             => 'pv-btn pv-btn--ghost',
        };
    }

    /**
     * @param list<string> $allowedChannelIds
     * @return list<array{channel:string,label:string,class:string}>
     */
    public function frontPayChannelOptionsFor(array $allowedChannelIds, string $scene): array
    {
        $useLong = $scene === 'portal_activate';
        $out     = [];
        foreach ($this->ids('online') as $id) {
            if (!in_array($id, $allowedChannelIds, true)) {
                continue;
            }
            $out[] = [
                'channel' => $id,
                'label'   => $this->buttonLabel($id, $useLong),
                'class'   => $this->buttonClass($id, $scene),
            ];
        }

        return $out;
    }

    public function gateway(string $id): ?PaymentGatewayInterface
    {
        $def = $this->get($id);
        if (!is_array($def) || empty($def['gateway_class'])) {
            return null;
        }
        $class = (string) $def['gateway_class'];
        if (!is_subclass_of($class, PaymentGatewayInterface::class)) {
            return null;
        }

        /** @var PaymentGatewayInterface $gateway */
        $gateway = AppService::make($class);

        return $gateway;
    }

    public function channelReady(string $id, PaymentConfigService $config): bool
    {
        $def = $this->get($id);
        if (!is_array($def)) {
            return false;
        }
        $ready = $def['ready'] ?? null;
        if (is_callable($ready)) {
            return (bool) $ready($config);
        }

        return false;
    }

    /** @return array<string, string> */
    public function gatewayConfig(string $id, PaymentConfigService $config): array
    {
        $def = $this->get($id);
        if (!is_array($def)) {
            return ['channel' => $id];
        }
        $resolver = $def['gateway_config'] ?? null;
        if (is_callable($resolver)) {
            $resolved = $resolver($config);
            return array_merge(['channel' => $id, 'pay_mode' => $config->payMode()], $resolved);
        }

        return $config->legacyGatewayConfigPayload($id);
    }

    public function notifySuccessBody(string $id): string
    {
        $def = $this->get($id);
        if (is_array($def) && is_callable($def['notify_success_body'] ?? null)) {
            return (string) ($def['notify_success_body'])();
        }
        if ($id === PaymentConfigService::CHANNEL_WECHAT) {
            $json = json_encode(['code' => 'SUCCESS', 'message' => '成功'], JSON_UNESCAPED_UNICODE);

            return $json === false ? '{"code":"SUCCESS","message":"成功"}' : $json;
        }

        return 'success';
    }

    /** @return list<PaymentChannelDef> 扩展通道（非 builtin） */
    public function extensionDefinitions(): array
    {
        $this->ensureCoreBootstrapped();
        $out = [];
        foreach ($this->ids('all') as $id) {
            $def = self::$channels[$id] ?? null;
            if (is_array($def) && empty($def['builtin'])) {
                $out[] = $def;
            }
        }

        return $out;
    }

    public function ensureCoreBootstrapped(): void
    {
        if (self::$coreBootstrapped) {
            return;
        }
        self::$coreBootstrapped = true;

        $sceneBuiltin = [
            'member_recharge' => [
                PaymentConfigService::CHANNEL_WECHAT => 'btn btn-sm pv-btn-pay-wechat pv-recharge-pay',
                PaymentConfigService::CHANNEL_ALIPAY => 'btn btn-sm pv-btn-pay-alipay pv-recharge-pay',
            ],
            'member_custom' => [
                PaymentConfigService::CHANNEL_WECHAT => 'btn btn-outline-success btn-sm pv-recharge-custom-pay',
                PaymentConfigService::CHANNEL_ALIPAY => 'btn btn-outline-primary btn-sm pv-recharge-custom-pay',
            ],
            'portal_activate' => [
                PaymentConfigService::CHANNEL_WECHAT => 'pv-btn pv-btn--block pv-activate-pay',
                PaymentConfigService::CHANNEL_ALIPAY => 'pv-btn pv-btn--block pv-activate-pay',
            ],
            'portal_upgrade' => [
                PaymentConfigService::CHANNEL_ALIPAY => 'pv-btn pv-btn--primary pv-upgrade-pay',
                PaymentConfigService::CHANNEL_WECHAT => 'pv-btn pv-btn--ghost pv-upgrade-pay',
            ],
            'portal_deposit' => [
                PaymentConfigService::CHANNEL_ALIPAY => 'pv-btn pv-btn--primary pv-shop-deposit-pay',
                PaymentConfigService::CHANNEL_WECHAT => 'pv-btn pv-btn--ghost pv-shop-deposit-pay',
            ],
        ];

        self::$channels[PaymentConfigService::CHANNEL_WECHAT] = [
            'id'                   => PaymentConfigService::CHANNEL_WECHAT,
            'title'                => '微信支付',
            'label'                => '微信',
            'label_long'           => '微信支付',
            'kind'                 => 'online',
            'builtin'              => true,
            'owner'                => 'core',
            'sort'                 => 10,
            'gateway_class'        => WechatPayGateway::class,
            'open_config_key'      => 'payment_wechat_open',
            'supports_demo'        => true,
            'scene_button_classes' => [
                'member_recharge' => $sceneBuiltin['member_recharge'][PaymentConfigService::CHANNEL_WECHAT],
                'member_custom'   => $sceneBuiltin['member_custom'][PaymentConfigService::CHANNEL_WECHAT],
                'portal_activate' => $sceneBuiltin['portal_activate'][PaymentConfigService::CHANNEL_WECHAT],
                'portal_upgrade'  => $sceneBuiltin['portal_upgrade'][PaymentConfigService::CHANNEL_WECHAT],
                'portal_deposit'  => $sceneBuiltin['portal_deposit'][PaymentConfigService::CHANNEL_WECHAT],
            ],
            'ready'                => static fn (PaymentConfigService $cfg): bool => $cfg->channelReadyBuiltin(
                PaymentConfigService::CHANNEL_WECHAT
            ),
        ];

        self::$channels[PaymentConfigService::CHANNEL_ALIPAY] = [
            'id'                   => PaymentConfigService::CHANNEL_ALIPAY,
            'title'                => '支付宝支付',
            'label'                => '支付宝',
            'label_long'           => '支付宝支付',
            'kind'                 => 'online',
            'builtin'              => true,
            'owner'                => 'core',
            'sort'                 => 20,
            'gateway_class'        => AlipayGateway::class,
            'open_config_key'      => 'payment_alipay_open',
            'supports_demo'        => true,
            'scene_button_classes' => [
                'member_recharge' => $sceneBuiltin['member_recharge'][PaymentConfigService::CHANNEL_ALIPAY],
                'member_custom'   => $sceneBuiltin['member_custom'][PaymentConfigService::CHANNEL_ALIPAY],
                'portal_activate' => $sceneBuiltin['portal_activate'][PaymentConfigService::CHANNEL_ALIPAY],
                'portal_upgrade'  => $sceneBuiltin['portal_upgrade'][PaymentConfigService::CHANNEL_ALIPAY],
                'portal_deposit'  => $sceneBuiltin['portal_deposit'][PaymentConfigService::CHANNEL_ALIPAY],
            ],
            'ready'                => static fn (PaymentConfigService $cfg): bool => $cfg->channelReadyBuiltin(
                PaymentConfigService::CHANNEL_ALIPAY
            ),
        ];

        self::$channels[PaymentConfigService::CHANNEL_BALANCE] = [
            'id'              => PaymentConfigService::CHANNEL_BALANCE,
            'title'           => '余额支付',
            'label'           => '余额',
            'label_long'      => '余额支付',
            'kind'            => 'balance',
            'builtin'         => true,
            'owner'           => 'core',
            'sort'            => 90,
            'open_config_key' => 'payment_balance_open',
            'supports_demo'   => false,
        ];
    }
}
