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

use app\common\service\config\ConfigSecretService;
use app\common\service\config\ConfigService;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\site\SiteModeService;
use app\common\support\AppCipher;
use app\common\support\ConfigSensitiveKeys;
use app\common\support\OpsLog;

/** 在线支付全局配置（configs.payment_*），内核内置，不依赖插件授权 */
class PaymentConfigService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public const MODE_DEMO = 'demo';
    public const MODE_LIVE = 'live';

    public const CHANNEL_ALIPAY = 'alipay';
    public const CHANNEL_WECHAT = 'wechat';
    public const CHANNEL_BALANCE = 'balance';

    /** @return list<string> */
    public function keys(): array
    {
        $keys = [
            'payment_open',
            'payment_wechat_open',
            'payment_alipay_open',
            'payment_balance_open',
            'payment_pay_mode',
            'payment_alipay_app_id',
            'payment_alipay_private_key',
            'payment_alipay_public_key',
            'payment_wechat_app_id',
            'payment_wechat_mch_id',
            'payment_wechat_api_v3_key',
            'payment_wechat_serial_no',
            'payment_wechat_private_key',
        ];
        foreach (app(PaymentChannelRegistry::class)->extensionDefinitions() as $def) {
            $openKey = app(PaymentChannelRegistry::class)->openConfigKey((string) $def['id']);
            if ($openKey !== '' && !in_array($openKey, $keys, true)) {
                $keys[] = $openKey;
            }
        }

        return $keys;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            $out[$key] = (string) $this->configService->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    /** 后台表单：凭据字段不回显明文
     * @return array<string, string>
     */
    public function allForAdmin(): array
    {
        $all = $this->all();
        foreach ($this->paymentOpenFlagKeys() as $openKey) {
            $all[$openKey] = $this->readStoredOpenFlag($openKey);
        }
        foreach (app(PaymentChannelRegistry::class)->extensionDefinitions() as $def) {
            $openKey = app(PaymentChannelRegistry::class)->openConfigKey((string) $def['id']);
            if ($openKey !== '') {
                $all[$openKey] = $this->readStoredOpenFlag($openKey);
            }
        }
        foreach (self::adminSecretKeys() as $key) {
            if (trim($all[$key] ?? '') !== '') {
                $all[$key] = '';
            }
        }

        return $all;
    }

    /** 后台展示：凭据是否已写入（不回显明文）
     * @return array<string, mixed>
     */
    public function secretStatusForAdmin(): array
    {
        $all = $this->all();

        return [
            'payment_alipay_private_key'  => trim((string) ($all['payment_alipay_private_key'] ?? '')) !== '' ? 1 : 0,
            'payment_alipay_public_key'   => trim((string) ($all['payment_alipay_public_key'] ?? '')) !== '' ? 1 : 0,
            'payment_wechat_api_v3_key'   => trim((string) ($all['payment_wechat_api_v3_key'] ?? '')) !== '' ? 1 : 0,
            'payment_wechat_private_key'  => trim((string) ($all['payment_wechat_private_key'] ?? '')) !== '' ? 1 : 0,
        ];
    }

    /** 后台配置清单：逐项 ok/hint，便于用户确认是否配齐
     * @return array{alipay: array{items: list<array{id: string, label: string, ok: int, hint: string}>, ready: int, done: int, total: int, summary: string}, wechat: array{items: list<array{id: string, label: string, ok: int, hint: string}>, ready: int, done: int, total: int, summary: string}}
     */
    public function channelSetupForAdmin(): array
    {
        $all = $this->all();

        $alipayAppId = trim((string) ($all['payment_alipay_app_id'] ?? ''));
        $alipayItems = [
            [
                'id'   => 'app_id',
                'label'=> 'App ID',
                'ok'   => $alipayAppId !== '' ? 1 : 0,
                'hint' => $alipayAppId !== '' ? $alipayAppId : '未填写',
            ],
            [
                'id'   => 'private_key',
                'label'=> '应用私钥',
                'ok'   => trim((string) ($all['payment_alipay_private_key'] ?? '')) !== '' ? 1 : 0,
                'hint' => trim((string) ($all['payment_alipay_private_key'] ?? '')) !== '' ? '已加密存储' : '未上传',
            ],
            [
                'id'   => 'public_key',
                'label'=> '支付宝公钥',
                'ok'   => trim((string) ($all['payment_alipay_public_key'] ?? '')) !== '' ? 1 : 0,
                'hint' => trim((string) ($all['payment_alipay_public_key'] ?? '')) !== '' ? '已加密存储' : '未上传',
            ],
        ];

        $wechatAppId = trim((string) ($all['payment_wechat_app_id'] ?? ''));
        $wechatMchId = trim((string) ($all['payment_wechat_mch_id'] ?? ''));
        $wechatSerial = trim((string) ($all['payment_wechat_serial_no'] ?? ''));
        $wechatItems = [
            [
                'id'   => 'app_id',
                'label'=> 'App ID',
                'ok'   => $wechatAppId !== '' ? 1 : 0,
                'hint' => $wechatAppId !== '' ? $wechatAppId : '未填写',
            ],
            [
                'id'   => 'mch_id',
                'label'=> '商户号',
                'ok'   => $wechatMchId !== '' ? 1 : 0,
                'hint' => $wechatMchId !== '' ? $wechatMchId : '未填写',
            ],
            [
                'id'   => 'api_v3_key',
                'label'=> 'APIv3 密钥',
                'ok'   => trim((string) ($all['payment_wechat_api_v3_key'] ?? '')) !== '' ? 1 : 0,
                'hint' => trim((string) ($all['payment_wechat_api_v3_key'] ?? '')) !== '' ? '已加密存储' : '未填写',
            ],
            [
                'id'   => 'serial_no',
                'label'=> '证书序列号',
                'ok'   => $wechatSerial !== '' ? 1 : 0,
                'hint' => $wechatSerial !== '' ? $wechatSerial : '未填写',
            ],
            [
                'id'   => 'private_key',
                'label'=> '商户私钥',
                'ok'   => trim((string) ($all['payment_wechat_private_key'] ?? '')) !== '' ? 1 : 0,
                'hint' => trim((string) ($all['payment_wechat_private_key'] ?? '')) !== '' ? '已加密存储' : '未上传',
            ],
        ];

        return [
            'alipay' => $this->packChannelSetup($alipayItems, self::CHANNEL_ALIPAY),
            'wechat' => $this->packChannelSetup($wechatItems, self::CHANNEL_WECHAT),
        ];
    }

    /**
     * @param list<array{id:string,label:string,ok:int,hint:string}> $items
     * @return array{items:list<array{id:string,label:string,ok:int,hint:string}>,ready:int,done:int,total:int,summary:string}
     */
    private function packChannelSetup(array $items, string $channel): array
    {
        $done  = 0;
        foreach ($items as $item) {
            if ($item['ok'] === 1) {
                $done++;
            }
        }
        $total = count($items);
        $ready = $this->channelReady($channel) ? 1 : 0;
        $missing = $total - $done;
        if ($ready === 1) {
            $summary = $channel === self::CHANNEL_ALIPAY
                ? '支付宝三项均已写入服务器，正式模式可跳转收银台（还须支付宝开放平台开通「电脑网站支付」）'
                : '微信支付五项均已写入服务器，正式模式可收款';
        } elseif ($missing > 0) {
            $summary = '还须补全 ' . $missing . ' 项后才能正式收款';
        } else {
            $summary = '参数已填但校验未通过，请检查密钥格式';
        }

        return [
            'items'   => $items,
            'ready'   => $ready,
            'done'    => $done,
            'total'   => $total,
            'summary' => $summary,
        ];
    }

    /** @return list<string> */
    private static function adminSecretKeys(): array
    {
        return [
            'payment_alipay_private_key',
            'payment_alipay_public_key',
            'payment_wechat_api_v3_key',
            'payment_wechat_private_key',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function channelsForAdmin(): array
    {
        $rows = [];
        foreach (app(PaymentChannelRegistry::class)->ids('all') as $id) {
            $def     = app(PaymentChannelRegistry::class)->get($id);
            $builtin = !empty($def['builtin']);
            $kind    = (string) ($def['kind'] ?? 'online');
            if ($kind === 'balance') {
                $rows[] = [
                    'id'         => $id,
                    'title'      => app(PaymentChannelRegistry::class)->title($id),
                    'builtin'    => $builtin ? 1 : 0,
                    'owner'      => (string) ($def['owner'] ?? ''),
                    'enabled'    => $this->isBalanceOpen() ? 1 : 0,
                    'configured' => 1,
                    'demo_mode'  => 0,
                    'pay_mode'   => $this->payMode(),
                ];
                continue;
            }
            $rows[] = [
                'id'         => $id,
                'title'      => app(PaymentChannelRegistry::class)->title($id),
                'builtin'    => $builtin ? 1 : 0,
                'owner'      => (string) ($def['owner'] ?? ''),
                'enabled'    => $this->isChannelOpen($id) ? 1 : 0,
                'configured' => $this->channelReady($id) ? 1 : 0,
                'demo_mode'  => $this->shouldUseDemo($id) ? 1 : 0,
                'pay_mode'   => $this->payMode(),
            ];
        }

        return $rows;
    }

    /** @return array<string, string> 各通道异步通知 URL（含插件扩展） */
    public function notifyUrlsForAdmin(): array
    {
        $urls = [];
        foreach (app(PaymentChannelRegistry::class)->ids('online') as $id) {
            $urls[$id] = app(PaymentUrl::class)->notifyUrlForChannel($id);
        }

        return $urls;
    }

    public function defaultFor(string $key): string
    {
        if (str_starts_with($key, 'payment_channel_') && str_ends_with($key, '_open')) {
            return '0';
        }

        return match ($key) {
            'payment_open', 'payment_wechat_open', 'payment_alipay_open', 'payment_balance_open' => '1',
            'payment_pay_mode'                                                                          => self::MODE_DEMO,
            default                                                                                     => '',
        };
    }

    public function isOpen(): bool
    {
        return $this->readStoredOpenFlag('payment_open') === '1';
    }

    public function isBalanceOpen(): bool
    {
        return $this->readStoredOpenFlag('payment_balance_open') === '1';
    }

    public function isWechatOpen(): bool
    {
        return $this->readStoredOpenFlag('payment_wechat_open') === '1';
    }

    public function isAlipayOpen(): bool
    {
        return $this->readStoredOpenFlag('payment_alipay_open') === '1';
    }

    /** 在线通道是否启用（总开关 + 单通道开关） */
    public function isChannelOpen(string $channel): bool
    {
        $channel = strtolower(trim($channel));
        if ($channel === self::CHANNEL_BALANCE) {
            return $this->isBalanceOpen();
        }
        if (!$this->isOpen()) {
            return false;
        }

        if ($channel === self::CHANNEL_WECHAT) {
            return $this->isWechatOpen();
        }
        if ($channel === self::CHANNEL_ALIPAY) {
            return $this->isAlipayOpen();
        }

        $registry = app(PaymentChannelRegistry::class);
        if (!$registry->isOnline($channel)) {
            return false;
        }

        return $this->readStoredOpenFlag($registry->openConfigKey($channel)) === '1';
    }

    public function payMode(): string
    {
        $mode = (string) $this->configService->get('payment_pay_mode', self::MODE_DEMO);

        return $mode === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_DEMO;
    }

    public function isDemoMode(): bool
    {
        return $this->payMode() === self::MODE_DEMO;
    }

    public function channelReady(string $channel): bool
    {
        $channel = strtolower(trim($channel));
        if ($channel === self::CHANNEL_BALANCE) {
            return true;
        }

        return app(PaymentChannelRegistry::class)->channelReady($channel, $this);
    }

    /** 官方内置通道密钥是否配齐（Registry ready 回调用） */
    public function channelReadyBuiltin(string $channel): bool
    {
        if ($channel === self::CHANNEL_ALIPAY) {
            return trim((string) $this->configService->get('payment_alipay_app_id', '')) !== ''
                && trim((string) $this->configService->get('payment_alipay_private_key', '')) !== ''
                && trim((string) $this->configService->get('payment_alipay_public_key', '')) !== '';
        }
        if ($channel === self::CHANNEL_WECHAT) {
            $privateKey = trim((string) $this->configService->get('payment_wechat_private_key', ''));

            return trim((string) $this->configService->get('payment_wechat_app_id', '')) !== ''
                && trim((string) $this->configService->get('payment_wechat_mch_id', '')) !== ''
                && trim((string) $this->configService->get('payment_wechat_api_v3_key', '')) !== ''
                && trim((string) $this->configService->get('payment_wechat_serial_no', '')) !== ''
                && $privateKey !== ''
                && $this->wechatPrivateKeyParsable($privateKey);
        }

        return false;
    }

    public function shouldUseDemo(string $channel): bool
    {
        if ($channel === self::CHANNEL_BALANCE) {
            return false;
        }
        if (!app(PaymentChannelRegistry::class)->supportsDemo($channel)) {
            return false;
        }
        if (!$this->isDemoMode()) {
            return false;
        }

        return $this->demoAllowedInEnvironment();
    }

    /** 演示自动入账是否允许（环境 gate） */
    public function demoAllowedInEnvironment(): bool
    {
        $allow = $this->runtimeEnvString('PAYMENT_DEMO_ALLOW');
        if ($allow === '1') {
            return true;
        }
        if ($allow === '0') {
            return false;
        }
        $env = strtolower($this->runtimeEnvString('PIVARK_ENV', 'dev'));

        return in_array($env, ['dev', 'demo-local', 'local'], true)
            || filter_var($this->runtimeEnvString('APP_DEBUG', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    /** 生产环境误开演示自动入账时写业务告警（每请求一次） */
    public function warnIfDemoInProduction(): void
    {
        static $warned = false;
        if ($warned || !$this->isDemoMode() || !$this->demoAllowedInEnvironment()) {
            return;
        }
        $env = strtolower($this->runtimeEnvString('PIVARK_ENV', 'dev'));
        if (in_array($env, ['dev', 'demo-local', 'local'], true)) {
            return;
        }
        $warned = true;
        OpsLog::businessWarning('payment_demo_mode_in_production', ['pivark_env' => $env]);
    }

    /** 通道未配置时的用户提示 */
    public function channelUnavailableMessage(string $channel): string
    {
        $channel = $this->normalizeChannel($channel);

        if ($channel !== self::CHANNEL_BALANCE && !$this->isChannelOpen($channel)) {
            $title = app(PaymentChannelRegistry::class)->title($channel);

            return $title . '已关闭，请在后台「系统设置 → 支付接口」开启';
        }

        return match ($channel) {
            self::CHANNEL_WECHAT => '微信支付尚未配置完整，请在后台「系统设置 → 支付接口」填写商户参数或切换演示模式',
            self::CHANNEL_ALIPAY => '支付宝尚未配置完整，请在后台「系统设置 → 支付接口」填写应用密钥或切换演示模式',
            default              => app(PaymentChannelRegistry::class)->title($channel) . '尚未配置完整，请联系管理员',
        };
    }

    /** @return array<string, string> */
    public function gatewayConfig(string $channel): array
    {
        $channel = strtolower(trim($channel));

        return app(PaymentChannelRegistry::class)->gatewayConfig($channel, $this);
    }

    /** @return array<string, string> 内置微信/支付宝 gateway 凭据包 */
    public function legacyGatewayConfigPayload(string $channel): array
    {
        $all = $this->all();

        return [
            'channel'            => $channel,
            'pay_mode'           => $this->payMode(),
            'alipay_app_id'      => $all['payment_alipay_app_id'] ?? '',
            'alipay_private_key' => $all['payment_alipay_private_key'] ?? '',
            'alipay_public_key'  => $all['payment_alipay_public_key'] ?? '',
            'wechat_app_id'      => $all['payment_wechat_app_id'] ?? '',
            'wechat_mch_id'      => $all['payment_wechat_mch_id'] ?? '',
            'wechat_api_v3_key'  => $all['payment_wechat_api_v3_key'] ?? '',
            'wechat_serial_no'   => $all['payment_wechat_serial_no'] ?? '',
            'wechat_private_key' => $all['payment_wechat_private_key'] ?? '',
        ];
    }

    public function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if (app(PaymentChannelRegistry::class)->has($channel)) {
            return $channel;
        }

        return self::CHANNEL_ALIPAY;
    }

    /**
     * 后台插件市场购买默认通道（演示 / 微信 / 支付宝 / 线下余额）
     */
    public function resolveAdminPluginAcquireChannel(string $requested = ''): string
    {
        $requested = strtolower(trim($requested));
        if ($requested !== '' && $requested !== 'demo' && $this->isFrontChannelAllowed($requested)) {
            return $this->normalizeChannel($requested);
        }

        foreach (app(PaymentChannelRegistry::class)->ids('online') as $channel) {
            if (!$this->isChannelOpen($channel)) {
                continue;
            }
            if ($this->shouldUseDemo($channel) || $this->channelReady($channel)) {
                return $channel;
            }
        }

        if ($this->isBalanceOpen()) {
            return self::CHANNEL_BALANCE;
        }

        return self::CHANNEL_ALIPAY;
    }

    /**
     * @return array{
     *   default_channel:string,
     *   channels:list<string>,
     *   demo_mode:bool,
     *   in_site_purchase:bool
     * }
     */
    public function adminPluginPaymentMeta(): array
    {
        $channels = $this->frontOnlineChannels();
        if ($this->isBalanceOpen()) {
            $channels[] = self::CHANNEL_BALANCE;
        }

        return [
            'default_channel'   => $this->resolveAdminPluginAcquireChannel(''),
            'channels'          => array_values(array_unique($channels)),
            'demo_mode'         => $this->isDemoMode(),
            'in_site_purchase'  => (bool) config('plugin.commercial.in_site_purchase', true),
        ];
    }

    /** @return list<string> 前台可选在线通道（不含余额） */
    public function frontOnlineChannels(): array
    {
        if (!$this->isOpen()) {
            return [];
        }
        $out = [];
        foreach (app(PaymentChannelRegistry::class)->ids('online') as $ch) {
            if (!$this->isChannelOpen($ch)) {
                continue;
            }
            if ($this->shouldUseDemo($ch) || $this->channelReady($ch)) {
                $out[] = $ch;
            }
        }

        return $out;
    }

    public function isFrontChannelAllowed(string $channel): bool
    {
        $channel = $this->normalizeChannel($channel);
        if ($channel === self::CHANNEL_BALANCE) {
            return $this->isBalanceOpen();
        }
        if (!$this->isChannelOpen($channel)) {
            return false;
        }

        return $this->shouldUseDemo($channel) || $this->channelReady($channel);
    }

    /** 前台是否展示微信支付入口 */
    public function frontWechatVisible(): bool
    {
        return in_array(self::CHANNEL_WECHAT, $this->frontOnlineChannels(), true);
    }

    /** 前台是否展示支付宝支付入口 */
    public function frontAlipayVisible(): bool
    {
        return in_array(self::CHANNEL_ALIPAY, $this->frontOnlineChannels(), true);
    }

    /**
     * @return array{wechat:int,alipay:int,channels:list<string>}
     */
    public function frontPaymentFlags(): array
    {
        $channels = $this->frontOnlineChannels();

        return [
            'wechat'   => $this->frontWechatVisible() ? 1 : 0,
            'alipay'   => $this->frontAlipayVisible() ? 1 : 0,
            'channels' => $channels,
        ];
    }

    /**
     * 前台支付按钮元数据（SSOT：仅含 frontOnlineChannels() 已启用且可用的通道）
     *
     * @return list<array{channel:string,label:string,class:string}>
     */
    public function frontPayChannelOptionsFor(string $scene): array
    {
        return app(PaymentChannelRegistry::class)->frontPayChannelOptionsFor(
            $this->frontOnlineChannels(),
            $scene
        );
    }

    /** 前台支付提示文案（随已启用通道变化） */
    public function frontOnlinePayHint(string $fallback = '在线支付'): string
    {
        $channels = $this->frontOnlineChannels();
        if ($channels === []) {
            return $fallback . '未开启';
        }
        $labels = [];
        foreach ($channels as $ch) {
            $labels[] = app(PaymentChannelRegistry::class)->buttonLabel($ch, false);
        }

        return '请使用' . implode('/', $labels) . '支付';
    }

    /**
     * 解析前台下单 channel：禁止 normalize 到已关闭的 alipay
     */
    public function resolveFrontChannel(string $requested): string
    {
        $requested = strtolower(trim($requested));
        if ($requested !== '' && $this->isFrontChannelAllowed($requested)) {
            return $requested;
        }
        foreach ($this->frontOnlineChannels() as $ch) {
            return $ch;
        }

        return '';
    }

    /** 是否视为生产环境（禁用演示 OAuth 等 MVP 路径） */
    public function isProductionEnvironment(): bool
    {
        $env = strtolower(trim($this->runtimeEnvString('PIVARK_ENV')));
        if ($env !== '') {
            return in_array($env, ['production', 'prod', 'live'], true);
        }

        return !$this->demoAllowedInEnvironment();
    }

    public function wechatPrivateKeyParsable(string $pem): bool
    {
        $pem = trim($pem);
        if ($pem === '') {
            return false;
        }

        return openssl_pkey_get_private($pem) !== false;
    }

    public function alipayPrivateKeyParsable(string $key): bool
    {
        $key = trim(str_replace(["\r\n", "\r"], "\n", $key));
        if ($key === '') {
            return false;
        }
        if (!str_contains($key, 'BEGIN')) {
            $key = "-----BEGIN RSA PRIVATE KEY-----\n"
                . chunk_split(preg_replace('/\s+/', '', $key) ?: '', 64, "\n")
                . "-----END RSA PRIVATE KEY-----";
        }

        return openssl_pkey_get_private($key) !== false;
    }

    public function alipayPublicKeyReady(string $key): bool
    {
        $compact = preg_replace('/\s+/', '', trim($key)) ?: '';

        return strlen($compact) >= 100;
    }

    /** 写入支付宝 AppID + RSA2 密钥（加密存 config_secrets；允许分批写入） */
    public function storeAlipayChannel(string $appId, string $privateKey, string $publicKey): ServiceResult
    {
        $existing   = $this->all();
        $appId      = trim($appId !== '' ? $appId : (string) ($existing['payment_alipay_app_id'] ?? ''));
        $privateKey = trim(str_replace(["\r\n", "\r"], "\n", $privateKey));
        $publicKey  = trim(str_replace(["\r\n", "\r"], "\n", $publicKey));
        if ($privateKey === '') {
            $privateKey = trim((string) ($existing['payment_alipay_private_key'] ?? ''));
        }
        if ($publicKey === '') {
            $publicKey = trim((string) ($existing['payment_alipay_public_key'] ?? ''));
        }

        $storedParts = [];
        if ($appId !== '' && $appId !== trim((string) ($existing['payment_alipay_app_id'] ?? ''))) {
            $this->configService->set('payment_alipay_app_id', $appId);
            $storedParts[] = 'App ID';
        } elseif ($appId !== '') {
            // 保持已有 AppID，不重复写
        } else {
            return ServiceResult::fail('请先填写 App ID');
        }

        if ($privateKey !== '' && $privateKey !== trim((string) ($existing['payment_alipay_private_key'] ?? ''))) {
            if (!$this->alipayPrivateKeyParsable($privateKey)) {
                return ServiceResult::fail('应用私钥格式无效，请上传密钥工具生成的应用私钥文件');
            }
            app(ConfigSecretService::class)->store('payment_alipay_private_key', $privateKey);
            $storedParts[] = '应用私钥';
        }
        if ($publicKey !== '' && $publicKey !== trim((string) ($existing['payment_alipay_public_key'] ?? ''))) {
            if (!$this->alipayPublicKeyReady($publicKey)) {
                return ServiceResult::fail('支付宝公钥格式无效，请上传开放平台下载的 alipayPublicKey_RSA2.txt');
            }
            app(ConfigSecretService::class)->store('payment_alipay_public_key', $publicKey);
            $storedParts[] = '支付宝公钥';
        }

        if ($storedParts === []) {
            return ServiceResult::fail('未检测到新的密钥内容');
        }

        $this->configService->forgetRequestCache();
        $configured = $this->channelReady(self::CHANNEL_ALIPAY);
        $msg        = $configured
            ? '支付宝已配置：' . implode('、', $storedParts)
            : '已写入' . implode('、', $storedParts) . '；请继续上传另一把密钥';

        return ServiceResult::ok(['configured' => $configured ? 1 : 0, 'channel_setup' => $this->channelSetupForAdmin()], $msg);
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $existingAtStart = $this->all();
        $existing = $existingAtStart;
        $mode = array_key_exists('payment_pay_mode', $data)
            ? (string) ($data['payment_pay_mode'] ?? self::MODE_DEMO)
            : $this->payMode();
        if (!in_array($mode, [self::MODE_DEMO, self::MODE_LIVE], true)) {
            $mode = self::MODE_DEMO;
        }

        $channelSnapshotBefore = [
            $this->readStoredOpenFlag('payment_open'),
            $this->readStoredOpenFlag('payment_wechat_open'),
            $this->readStoredOpenFlag('payment_alipay_open'),
            $this->extensionChannelOpenSnapshot($existing),
        ];
        $payload  = [
            'payment_open'               => $this->resolveOpenFlagFromAdminData($data, 'payment_open'),
            'payment_wechat_open'        => $this->resolveOpenFlagFromAdminData($data, 'payment_wechat_open'),
            'payment_alipay_open'        => $this->resolveOpenFlagFromAdminData($data, 'payment_alipay_open'),
            'payment_balance_open'       => $this->resolveOpenFlagFromAdminData($data, 'payment_balance_open'),
            'payment_pay_mode'           => $mode,
            'payment_alipay_app_id'      => trim((string) ($data['payment_alipay_app_id'] ?? $existing['payment_alipay_app_id'] ?? '')),
            'payment_alipay_private_key' => $this->resolveSecretInput(
                'payment_alipay_private_key',
                $data,
                $existing
            ),
            'payment_alipay_public_key'  => $this->resolveSecretInput(
                'payment_alipay_public_key',
                $data,
                $existing
            ),
            'payment_wechat_app_id'      => trim((string) ($data['payment_wechat_app_id'] ?? $existing['payment_wechat_app_id'] ?? '')),
            'payment_wechat_mch_id'      => trim((string) ($data['payment_wechat_mch_id'] ?? $existing['payment_wechat_mch_id'] ?? '')),
            'payment_wechat_api_v3_key'  => $this->resolveSecretInput(
                'payment_wechat_api_v3_key',
                $data,
                $existing
            ),
            'payment_wechat_serial_no'   => trim((string) ($data['payment_wechat_serial_no'] ?? $existing['payment_wechat_serial_no'] ?? '')),
            'payment_wechat_private_key' => $this->resolveSecretInput(
                'payment_wechat_private_key',
                $data,
                $existing
            ),
        ];
        foreach (app(PaymentChannelRegistry::class)->extensionDefinitions() as $def) {
            $openKey = app(PaymentChannelRegistry::class)->openConfigKey((string) $def['id']);
            if ($openKey === '' || !array_key_exists($openKey, $data)) {
                continue;
            }
            $payload[$openKey] = $this->parseOpenFlag($data[$openKey]);
        }

        // 仅更新开关：请求体不含 mode/密钥时，避免把整包写回（误触敏感确认路径）
        if (!array_key_exists('payment_pay_mode', $data)) {
            unset($payload['payment_pay_mode']);
        }
        foreach ([
            'payment_alipay_app_id',
            'payment_alipay_private_key',
            'payment_alipay_public_key',
            'payment_wechat_app_id',
            'payment_wechat_mch_id',
            'payment_wechat_api_v3_key',
            'payment_wechat_serial_no',
            'payment_wechat_private_key',
        ] as $cfgKey) {
            if (!array_key_exists($cfgKey, $data)) {
                unset($payload[$cfgKey]);
            }
        }

        $toggleKeys = [
            'payment_open',
            'payment_wechat_open',
            'payment_alipay_open',
            'payment_balance_open',
        ];
        foreach (app(PaymentChannelRegistry::class)->extensionDefinitions() as $def) {
            $openKey = app(PaymentChannelRegistry::class)->openConfigKey((string) $def['id']);
            if ($openKey !== '' && array_key_exists($openKey, $payload)) {
                $toggleKeys[] = $openKey;
            }
        }
        // 请求未带的开关：从 payload 拿掉，persist 时不碰
        foreach ($toggleKeys as $tk) {
            if (!array_key_exists($tk, $data)) {
                unset($payload[$tk]);
            }
        }
        $toggleKeys = array_values(array_filter(
            $toggleKeys,
            static fn (string $k): bool => array_key_exists($k, $payload),
        ));
        $togglesChanged = $this->persistPaymentOpenToggles($payload, $existingAtStart, $toggleKeys);
        if ($togglesChanged) {
            app(FrontCacheInvalidator::class)->bumpGeneration();
            app(SiteModeService::class)->clearPageCache();
            $this->configService->refreshAllCacheFromDatabase();
            $existing = $this->all();
        }

        if (
            $togglesChanged
            && !$this->adminPayloadHasCredentialOrModeUpdates($data, $existingAtStart)
        ) {
            return ServiceResult::ok([
                'pay_mode'          => $this->payMode(),
                'alipay_configured' => $this->channelReady(self::CHANNEL_ALIPAY) ? 1 : 0,
                'wechat_configured' => $this->channelReady(self::CHANNEL_WECHAT) ? 1 : 0,
                'channel_setup'     => $this->channelSetupForAdmin(),
            ], '通道开关已保存');
        }

        if ($mode === self::MODE_LIVE) {
            $liveError = $this->validateLiveConfig($payload);
            if ($liveError !== null) {
                $suffix = $togglesChanged ? '（通道开关已保存，前台请 Ctrl+F5 刷新）' : '';

                return ServiceResult::fail($liveError . $suffix);
            }
        }

        foreach ($payload as $key => $value) {
            if (!in_array($key, $this->keys(), true)) {
                continue;
            }
            if (in_array($key, $toggleKeys, true)) {
                continue;
            }
            if (ConfigSensitiveKeys::isCredentialKey($key)) {
                $incoming = array_key_exists($key, $data) ? trim((string) ($data[$key] ?? '')) : '';
                if ($incoming === '') {
                    continue;
                }
                app(ConfigSecretService::class)->store($key, (string) $value);
                continue;
            }
            $this->configService->set($key, $value);
        }
        $this->configService->forgetRequestCache();

        $mergedForSnapshot = array_merge($existing, $payload);
        $channelSnapshotAfter = [
            (string) ($payload['payment_open'] ?? '1'),
            (string) ($payload['payment_wechat_open'] ?? '1'),
            (string) ($payload['payment_alipay_open'] ?? '1'),
            $this->extensionChannelOpenSnapshot($mergedForSnapshot),
        ];
        if ($channelSnapshotBefore !== $channelSnapshotAfter) {
            app(FrontCacheInvalidator::class)->bumpGeneration();
            app(SiteModeService::class)->clearPageCache();
        } else {
            $openKeys = ['payment_open', 'payment_wechat_open', 'payment_alipay_open', 'payment_balance_open'];
            foreach (app(PaymentChannelRegistry::class)->extensionDefinitions() as $def) {
                $openKeys[] = app(PaymentChannelRegistry::class)->openConfigKey((string) $def['id']);
            }
            foreach ($openKeys as $openKey) {
                if (!array_key_exists($openKey, $data)) {
                    continue;
                }
                $beforeVal = (string) ($existing[$openKey] ?? $this->defaultFor($openKey));
                $afterVal  = (string) ($payload[$openKey] ?? $beforeVal);
                if ($beforeVal !== $afterVal) {
                    app(FrontCacheInvalidator::class)->bumpGeneration();
                    app(SiteModeService::class)->clearPageCache();
                    break;
                }
            }
        }

        $incomingAlipayPrivate = array_key_exists('payment_alipay_private_key', $data)
            ? trim((string) ($data['payment_alipay_private_key'] ?? ''))
            : '';
        $incomingAlipayPublic = array_key_exists('payment_alipay_public_key', $data)
            ? trim((string) ($data['payment_alipay_public_key'] ?? ''))
            : '';
        if ($mode === self::MODE_LIVE && ($incomingAlipayPrivate !== '' || $incomingAlipayPublic !== '')) {
            if (!$this->channelReady(self::CHANNEL_ALIPAY)) {
                return ServiceResult::fail(
                    '支付宝密钥未能写入服务器（可能被防火墙/WAF 拦截 POST 正文）。请使用下方「上传密钥文件」导入后重试。'
                );
            }
        }

        return ServiceResult::ok([
            'pay_mode'            => $mode,
            'alipay_configured'   => $this->channelReady(self::CHANNEL_ALIPAY) ? 1 : 0,
            'wechat_configured'   => $this->channelReady(self::CHANNEL_WECHAT) ? 1 : 0,
            'channel_setup'       => $this->channelSetupForAdmin(),
        ], $mode === self::MODE_LIVE ? '已切换为正式模式' : '配置已保存');
    }

    private function parseOpenFlag(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '1';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0 ? '1' : '0';
        }
        $text = strtolower(trim((string) $value));
        if (in_array($text, ['0', 'false', 'off', 'no'], true)) {
            return '0';
        }

        return '1';
    }

    /**
     * 后台保存：请求未带开关键时保留库内值（禁缺键默认成开，误伤其它通道）
     *
     * @param array<string, mixed> $data
     */
    private function resolveOpenFlagFromAdminData(array $data, string $key): string
    {
        if (!array_key_exists($key, $data)) {
            return $this->readStoredOpenFlag($key);
        }

        return $this->parseOpenFlag($data[$key]);
    }

    /**
     * @param array<string, string> $payload
     * @param array<string, string> $existing
     * @param list<string> $toggleKeys
     */
    private function persistPaymentOpenToggles(array $payload, array $existing, array $toggleKeys): bool
    {
        $changed = false;
        foreach ($toggleKeys as $key) {
            if (!in_array($key, $this->keys(), true) || !array_key_exists($key, $payload)) {
                continue;
            }
            $next = (string) $payload[$key];
            $prev = $this->isPaymentOpenFlagKey($key)
                ? $this->readStoredOpenFlag($key)
                : (string) ($existing[$key] ?? $this->defaultFor($key));
            if ($prev === $next) {
                continue;
            }
            $this->configService->set($key, $next);
            $changed = true;
        }

        return $changed;
    }

    /** @return list<string> */
    private function paymentOpenFlagKeys(): array
    {
        $keys = [
            'payment_open',
            'payment_wechat_open',
            'payment_alipay_open',
            'payment_balance_open',
        ];
        foreach (app(PaymentChannelRegistry::class)->extensionDefinitions() as $def) {
            $openKey = app(PaymentChannelRegistry::class)->openConfigKey((string) $def['id']);
            if ($openKey !== '' && !in_array($openKey, $keys, true)) {
                $keys[] = $openKey;
            }
        }

        return $keys;
    }

    private function isPaymentOpenFlagKey(string $key): bool
    {
        return in_array($key, $this->paymentOpenFlagKeys(), true);
    }

    private function readStoredOpenFlag(string $key): string
    {
        $value = (string) $this->configService->getDirect($key, $this->defaultFor($key));

        return $value === '0' ? '0' : '1';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $existing
     */
    private function resolveSecretInput(string $key, array $data, array $existing): string
    {
        if (!array_key_exists($key, $data)) {
            return trim((string) ($existing[$key] ?? ''));
        }

        $incoming = trim((string) ($data[$key] ?? ''));
        if ($incoming === '') {
            return trim((string) ($existing[$key] ?? ''));
        }
        if (str_starts_with($incoming, 'pivenc1:')) {
            $plain = AppCipher::decrypt($incoming);
            if ($plain !== '' && $plain !== $incoming) {
                return $plain;
            }

            return trim((string) ($existing[$key] ?? ''));
        }

        return $incoming;
    }

    /**
     * @param array<string, string> $payload
     */
    private function alipayPayloadReady(array $payload): bool
    {
        return trim($payload['payment_alipay_app_id'] ?? '') !== ''
            && trim($payload['payment_alipay_private_key'] ?? '') !== ''
            && trim($payload['payment_alipay_public_key'] ?? '') !== '';
    }

    /**
     * @param array<string, string> $payload
     */
    private function wechatPayloadReady(array $payload): bool
    {
        $privateKey = trim((string) ($payload['payment_wechat_private_key'] ?? ''));

        return trim($payload['payment_wechat_app_id'] ?? '') !== ''
            && trim($payload['payment_wechat_mch_id'] ?? '') !== ''
            && trim($payload['payment_wechat_api_v3_key'] ?? '') !== ''
            && trim($payload['payment_wechat_serial_no'] ?? '') !== ''
            && $privateKey !== ''
            && $this->wechatPrivateKeyParsable($privateKey);
    }

    /**
     * @param array<string, string> $payload
     */
    private function validateLiveConfig(array $payload): ?string
    {
        if (($payload['payment_open'] ?? '1') !== '1') {
            return null;
        }

        $wechatOpen = ($payload['payment_wechat_open'] ?? '1') === '1';
        $alipayOpen = ($payload['payment_alipay_open'] ?? '1') === '1';
        if (!$wechatOpen && !$alipayOpen) {
            return '正式模式：请至少启用微信或支付宝其中一个在线通道';
        }

        $wechatFilled = trim($payload['payment_wechat_app_id'] ?? '') !== ''
            || trim($payload['payment_wechat_mch_id'] ?? '') !== ''
            || trim($payload['payment_wechat_api_v3_key'] ?? '') !== ''
            || trim($payload['payment_wechat_serial_no'] ?? '') !== ''
            || trim($payload['payment_wechat_private_key'] ?? '') !== '';
        if ($wechatOpen && $wechatFilled) {
            if (!$this->wechatPayloadReady($payload)) {
                if (trim($payload['payment_wechat_private_key'] ?? '') === '') {
                    return '正式模式：请填写微信支付商户私钥（apiclient_key.pem），或先上传证书包';
                }
                if (!$this->wechatPrivateKeyParsable((string) $payload['payment_wechat_private_key'])) {
                    return '正式模式：微信商户私钥无效或无法解密，请重新粘贴 apiclient_key.pem 完整内容后保存';
                }

                return '正式模式：微信支付参数不完整，请核对 AppID、商户号、APIv3 密钥与证书序列号';
            }
        }

        $alipayFilled = trim($payload['payment_alipay_app_id'] ?? '') !== ''
            || trim($payload['payment_alipay_private_key'] ?? '') !== ''
            || trim($payload['payment_alipay_public_key'] ?? '') !== '';
        if ($alipayOpen && $alipayFilled && !$this->alipayPayloadReady($payload)) {
            return '正式模式：支付宝参数不完整，请填写 App ID、应用私钥与支付宝公钥';
        }

        if ($wechatOpen && !$this->wechatPayloadReady($payload) && !$this->channelReady(self::CHANNEL_WECHAT)) {
            return '正式模式：微信支付已启用但参数不完整，请补全商户配置';
        }
        if ($alipayOpen && !$this->alipayPayloadReady($payload) && !$this->channelReady(self::CHANNEL_ALIPAY)) {
            return '正式模式：支付宝已启用但参数不完整，请补全应用密钥';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $existing
     */
    private function adminPayloadHasCredentialOrModeUpdates(array $data, array $existing): bool
    {
        if (array_key_exists('payment_pay_mode', $data)) {
            $mode = trim((string) ($data['payment_pay_mode'] ?? ''));
            if ($mode !== '' && $mode !== trim((string) ($existing['payment_pay_mode'] ?? self::MODE_DEMO))) {
                return true;
            }
        }

        $plainFields = [
            'payment_alipay_app_id',
            'payment_wechat_app_id',
            'payment_wechat_mch_id',
            'payment_wechat_serial_no',
        ];
        foreach ($plainFields as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $incoming = trim((string) ($data[$key] ?? ''));
            if ($incoming === '') {
                continue;
            }
            if ($incoming !== trim((string) ($existing[$key] ?? ''))) {
                return true;
            }
        }

        foreach (self::adminSecretKeys() as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if (trim((string) ($data[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $cfg */
    private function extensionChannelOpenSnapshot(array $cfg): string
    {
        $parts = [];
        foreach (app(PaymentChannelRegistry::class)->extensionDefinitions() as $def) {
            $openKey = app(PaymentChannelRegistry::class)->openConfigKey((string) $def['id']);
            if ($openKey === '') {
                continue;
            }
            $parts[] = $openKey . '=' . (string) ($cfg[$openKey] ?? $this->defaultFor($openKey));
        }

        return implode('|', $parts);
    }

    /** putenv 运行时覆盖优先于 ThinkPHP env 缓存（集成测 / 运维临时开关） */
    private function runtimeEnvString(string $key, string $default = ''): string
    {
        $fromGetenv = getenv($key);
        if ($fromGetenv !== false && $fromGetenv !== '') {
            return (string) $fromGetenv;
        }
        $fromEnv = env($key, null);
        if ($fromEnv !== null && $fromEnv !== '') {
            return (string) $fromEnv;
        }

        return $default;
    }
}
