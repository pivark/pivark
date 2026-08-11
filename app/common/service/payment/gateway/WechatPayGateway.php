<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment\gateway;

use app\common\support\ServiceResult;

use app\common\service\config\ConfigService;
use app\common\service\infra\CurlTlsService;
use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\PaymentUrl;
use app\common\service\payment\WechatPayPlatformCertService;

class WechatPayGateway implements PaymentGatewayInterface
{
    private const API_HOST = 'https://api.mch.weixin.qq.com';
    private const TRADE_TYPE_NATIVE = 'NATIVE';
    private const TRADE_TYPE_JSAPI  = 'JSAPI';
    private const TRADE_TYPE_MWEB   = 'MWEB';

    public function channel(): string
    {
        return 'wechat';
    }

    public function createPayment(array $order, array $config): ServiceResult
    {
        $appId   = trim((string) ($config['wechat_app_id'] ?? ''));
        $mchId   = trim((string) ($config['wechat_mch_id'] ?? ''));
        $serial  = trim((string) ($config['wechat_serial_no'] ?? ''));
        $privKey = self::normalizePrivateKey((string) ($config['wechat_private_key'] ?? ''));
        if ($appId === '' || $mchId === '' || $serial === '' || $privKey === '') {
            return ServiceResult::fail('微信支付未配置');
        }

        $orderNo = (string) ($order['order_no'] ?? '');
        $amount  = (int) round(((float) ($order['amount'] ?? 0)) * 100);
        $subject = (string) ($order['subject'] ?? '在线支付');
        if ($orderNo === '' || $amount < 1) {
            return ServiceResult::fail('订单参数无效');
        }

        $payload = self::orderPayload($order);
        $openid  = trim((string) ($payload['openid'] ?? ''));
        if ($openid !== '' && self::shouldUseJsapi($payload)) {
            return $this->createJsapiPayment($appId, $mchId, $serial, $privKey, $orderNo, $amount, $subject, $openid);
        }
        if (self::shouldUseMweb($payload)) {
            return $this->createMwebPayment(
                $appId,
                $mchId,
                $serial,
                $privKey,
                $orderNo,
                $amount,
                $subject,
                trim((string) ($payload['payer_ip'] ?? ''))
            );
        }

        $path = '/v3/pay/transactions/native';
        $body = json_encode([
            'appid'        => $appId,
            'mchid'        => $mchId,
            'description'  => mb_substr($subject, 0, 127),
            'out_trade_no' => $orderNo,
            'notify_url'   => app(PaymentUrl::class)->absolute('/pay/notify/wechat'),
            'amount'       => ['total' => $amount, 'currency' => 'CNY'],
        ], JSON_UNESCAPED_UNICODE);

        $resp = self::request('POST', $path, (string) $body, $mchId, $serial, $privKey);
        if (!$resp->isOk()) {
            return ServiceResult::fail($resp->message());
        }

        $data    = $resp->dataArray();
        $codeUrl = (string) ($data['code_url'] ?? '');
        if ($codeUrl === '') {
            return ServiceResult::fail('微信未返回 code_url');
        }

        return ServiceResult::ok(['type' => 'native', 'code_url' => $codeUrl], 'ok');
    }

    /**
     * @return ServiceResult
     */
    private function createJsapiPayment(
        string $appId,
        string $mchId,
        string $serial,
        \OpenSSLAsymmetricKey|string $privateKey,
        string $orderNo,
        int $amountCents,
        string $subject,
        string $openid
    ): ServiceResult {
        $path = '/v3/pay/transactions/jsapi';
        $body = json_encode([
            'appid'        => $appId,
            'mchid'        => $mchId,
            'description'  => mb_substr($subject, 0, 127),
            'out_trade_no' => $orderNo,
            'notify_url'   => app(PaymentUrl::class)->absolute('/pay/notify/wechat'),
            'amount'       => ['total' => $amountCents, 'currency' => 'CNY'],
            'payer'        => ['openid' => $openid],
        ], JSON_UNESCAPED_UNICODE);

        $resp = self::request('POST', $path, (string) $body, $mchId, $serial, $privateKey);
        if (!$resp->isOk()) {
            return ServiceResult::fail($resp->message());
        }

        $data     = $resp->dataArray();
        $prepayId = (string) ($data['prepay_id'] ?? '');
        if ($prepayId === '') {
            return ServiceResult::fail('微信未返回 prepay_id');
        }

        $payParams = self::buildMiniProgramPayParams($appId, $prepayId, $privateKey);
        if ($payParams === null) {
            return ServiceResult::fail('生成支付签名失败');
        }

        return ServiceResult::ok(['type' => 'jsapi', 'pay_params' => $payParams], 'ok');
    }

    /**
     * @return ServiceResult
     */
    private function createMwebPayment(
        string $appId,
        string $mchId,
        string $serial,
        \OpenSSLAsymmetricKey|string $privateKey,
        string $orderNo,
        int $amountCents,
        string $subject,
        string $payerIp
    ): ServiceResult {
        $appUrl  = app(PaymentUrl::class)->absolute('/');
        $appName = mb_substr(trim((string) app(ConfigService::class)->get('site_name', 'PivArk')), 0, 64);
        if ($appName === '') {
            $appName = 'PivArk';
        }
        if ($payerIp === '' || !filter_var($payerIp, FILTER_VALIDATE_IP)) {
            $payerIp = '127.0.0.1';
        }

        $h5Info = ['type' => 'Wap', 'app_name' => $appName];
        if ($appUrl !== '') {
            $h5Info['app_url'] = mb_substr($appUrl, 0, 128);
        }

        $path = '/v3/pay/transactions/h5';
        $body = json_encode([
            'appid'        => $appId,
            'mchid'        => $mchId,
            'description'  => mb_substr($subject, 0, 127),
            'out_trade_no' => $orderNo,
            'notify_url'   => app(PaymentUrl::class)->absolute('/pay/notify/wechat'),
            'amount'       => ['total' => $amountCents, 'currency' => 'CNY'],
            'scene_info'   => [
                'payer_client_ip' => $payerIp,
                'h5_info'         => $h5Info,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $resp = self::request('POST', $path, (string) $body, $mchId, $serial, $privateKey);
        if (!$resp->isOk()) {
            return ServiceResult::fail($resp->message());
        }

        $data   = $resp->dataArray();
        $h5Url  = (string) ($data['h5_url'] ?? '');
        if ($h5Url === '') {
            return ServiceResult::fail('微信未返回 h5_url');
        }

        return ServiceResult::ok(['type' => 'mweb', 'h5_url' => $h5Url, 'redirect' => $h5Url], 'ok');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $config
     * @param array<string, string> $notifyHeaders
     */
    public function parseNotify(array $input, string $rawBody, array $config, array $notifyHeaders = []): array
    {
        if (!app(PaymentConfigService::class)->shouldUseDemo(PaymentConfigService::CHANNEL_WECHAT)) {
            if ($rawBody === '') {
                return ['ok' => false, 'msg' => 'notify body empty'];
            }
            $verified = app(WechatPayPlatformCertService::class)->verifyNotify($notifyHeaders, $rawBody, $config);
            if (!$verified['ok']) {
                return ['ok' => false, 'msg' => isset($verified['msg']) ? (string) $verified['msg'] : 'signature invalid'];
            }
        }

        $apiV3Key = (string) ($config['wechat_api_v3_key'] ?? '');
        if ($apiV3Key === '') {
            return ['ok' => false, 'msg' => 'api v3 key missing'];
        }

        $resource = $input['resource'] ?? null;
        if (!is_array($resource)) {
            $decoded  = json_decode($rawBody, true);
            $resource = is_array($decoded) ? ($decoded['resource'] ?? null) : null;
        }
        if (!is_array($resource)) {
            return ['ok' => false, 'msg' => 'resource missing'];
        }

        $plain = self::decryptResource($resource, $apiV3Key);
        if ($plain === '') {
            return ['ok' => false, 'msg' => 'decrypt failed'];
        }

        $payload = json_decode($plain, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'msg' => 'payload invalid'];
        }

        $tradeState = (string) ($payload['trade_state'] ?? '');
        if ($tradeState !== '' && $tradeState !== 'SUCCESS') {
            return ['ok' => false, 'msg' => 'trade not success', 'raw' => $payload];
        }

        $tradeType = strtoupper((string) ($payload['trade_type'] ?? ''));
        if ($tradeType !== '' && !in_array($tradeType, [self::TRADE_TYPE_NATIVE, self::TRADE_TYPE_JSAPI, self::TRADE_TYPE_MWEB], true)) {
            return ['ok' => false, 'msg' => 'unexpected trade_type', 'raw' => $payload];
        }

        return [
            'ok'       => true,
            'order_no' => (string) ($payload['out_trade_no'] ?? ''),
            'txn_id'   => (string) ($payload['transaction_id'] ?? ''),
            'raw'      => $payload,
        ];
    }

    /**
     * @param array<string, string> $config
     * @return array{ok:bool,paid:bool,order_no?:string,txn_id?:string,msg?:string,raw?:array<string,mixed>}
     */
    public function queryPayment(string $orderNo, array $config): array
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return ['ok' => false, 'paid' => false, 'msg' => 'order_no empty'];
        }

        if (app(PaymentConfigService::class)->shouldUseDemo(PaymentConfigService::CHANNEL_WECHAT)) {
            return ['ok' => true, 'paid' => false, 'order_no' => $orderNo, 'msg' => 'demo mode'];
        }

        $mchId   = trim((string) ($config['wechat_mch_id'] ?? ''));
        $serial  = trim((string) ($config['wechat_serial_no'] ?? ''));
        $privKey = self::normalizePrivateKey((string) ($config['wechat_private_key'] ?? ''));
        if ($mchId === '' || $serial === '' || $privKey === '') {
            return ['ok' => false, 'paid' => false, 'msg' => '微信支付未配置'];
        }

        $path = '/v3/pay/transactions/out-trade-no/' . rawurlencode($orderNo)
            . '?mchid=' . rawurlencode($mchId);
        $resp = self::request('GET', $path, '', $mchId, $serial, $privKey);
        if (!$resp->isOk()) {
            return ['ok' => false, 'paid' => false, 'msg' => $resp->message()];
        }

        $data       = $resp->dataArray();
        $tradeState = (string) ($data['trade_state'] ?? '');
        $paid       = $tradeState === 'SUCCESS';

        return [
            'ok'       => true,
            'paid'     => $paid,
            'order_no' => (string) ($data['out_trade_no'] ?? $orderNo),
            'txn_id'   => (string) ($data['transaction_id'] ?? ''),
            'raw'      => $data,
            'msg'      => $paid ? 'paid' : ($tradeState !== '' ? 'trade_state:' . $tradeState : 'not paid'),
        ];
    }

    public function refundPayment(array $order, array $config, string $reason = ''): ServiceResult
    {
        if (app(PaymentConfigService::class)->shouldUseDemo(PaymentConfigService::CHANNEL_WECHAT)) {
            return ServiceResult::ok(['gateway' => 0], '演示模式：跳过网关退款');
        }

        $mchId   = trim((string) ($config['wechat_mch_id'] ?? ''));
        $serial  = trim((string) ($config['wechat_serial_no'] ?? ''));
        $privKey = self::normalizePrivateKey((string) ($config['wechat_private_key'] ?? ''));
        if ($mchId === '' || $serial === '' || $privKey === '') {
            return ServiceResult::fail('微信支付未配置');
        }

        $orderNo    = (string) ($order['order_no'] ?? '');
        $refundNo   = 'REF' . $orderNo;
        $totalCents = (int) round(((float) ($order['amount'] ?? 0)) * 100);
        if ($orderNo === '' || $totalCents < 1) {
            return ServiceResult::fail('订单参数无效');
        }

        $path = '/v3/refund/domestic/refunds';
        $body = json_encode([
            'out_trade_no'  => $orderNo,
            'out_refund_no' => $refundNo,
            'reason'        => mb_substr($reason !== '' ? $reason : '用户申请退款', 0, 80),
            'amount'        => [
                'refund'   => $totalCents,
                'total'    => $totalCents,
                'currency' => 'CNY',
            ],
        ], JSON_UNESCAPED_UNICODE);

        $resp = self::request('POST', $path, (string) $body, $mchId, $serial, $privKey);
        if (!$resp->isOk()) {
            return ServiceResult::fail($resp->message());
        }

        return ServiceResult::ok(['refund_no' => $refundNo, 'gateway' => 1], '微信退款已受理');
    }

    /**
     * 平台证书拉取 / AEAD 解密（供 WechatPayPlatformCertService 等调用）。
     *
     * @param array<string, mixed> $resource
     */
    public static function decryptAeadResource(array $resource, string $apiV3Key): string
    {
        return self::decryptResource($resource, $apiV3Key);
    }

    /** @param array<string, mixed> $resource */
    private static function decryptResource(array $resource, string $apiV3Key): string
    {
        $ciphertext = (string) ($resource['ciphertext'] ?? '');
        $nonce      = (string) ($resource['nonce'] ?? '');
        $aad        = (string) ($resource['associated_data'] ?? '');
        if ($ciphertext === '' || $nonce === '') {
            return '';
        }

        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || strlen($decoded) < 17) {
            return '';
        }

        $tag   = substr($decoded, -16);
        $data  = substr($decoded, 0, -16);
        $plain = openssl_decrypt($data, 'aes-256-gcm', $apiV3Key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

        return is_string($plain) ? $plain : '';
    }

    /**
     * 带商户签名的 V3 API 请求（供平台证书刷新等外部调用）。
     *
     * @return ServiceResult
     */
    public static function authorizedRequest(
        string $method,
        string $path,
        string $body,
        string $mchId,
        string $serial,
        \OpenSSLAsymmetricKey|string $privateKey
    ): ServiceResult {
        return self::request($method, $path, $body, $mchId, $serial, $privateKey);
    }

    /** @return ServiceResult */
    private static function request(
        string $method,
        string $path,
        string $body,
        string $mchId,
        string $serial,
        \OpenSSLAsymmetricKey|string $privateKey
    ): ServiceResult {
        $method = strtoupper(trim($method));
        if ($method === '') {
            return ServiceResult::fail('http method required');
        }

        $url       = self::API_HOST . $path;
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $message   = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $signature = '';
        $signed    = openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            return ServiceResult::fail('微信商户私钥无效，请在后台重新导入 apiclient_key.pem');
        }
        $auth = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
            $mchId,
            $nonce,
            base64_encode($signature),
            $timestamp,
            $serial
        );

        $ch = curl_init($url);
        if ($ch === false) {
            return ServiceResult::fail('curl init failed');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: PivArk-Payment/1.0',
            'Authorization: ' . $auth,
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        app(CurlTlsService::class)->applyPaymentOutbound($ch);
        $respBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($respBody === false) {
            return ServiceResult::fail(CurlTlsService::friendlyOutboundError($err !== '' ? $err : 'curl error'));
        }

        $data = json_decode((string) $respBody, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = is_array($data) ? (string) ($data['message'] ?? $respBody) : (string) $respBody;

            return ServiceResult::fail($msg);
        }

        return ServiceResult::ok(is_array($data) ? $data : [], 'ok');
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private static function orderPayload(array $order): array
    {
        $raw = $order['payload'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        $json = (string) ($order['payload_json'] ?? '');
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private static function shouldUseJsapi(array $payload): bool
    {
        $client = strtolower(trim((string) ($payload['client'] ?? '')));
        if (in_array($client, [
            'miniprogram',
            'jsapi',
            'mp_wechat',
            'mp-wechat',
            'wechat_h5',
            'wechat',
            'mp',
        ], true)) {
            return true;
        }

        return (bool) ($payload['jsapi'] ?? false);
    }

    /** @param array<string, mixed> $payload */
    private static function shouldUseMweb(array $payload): bool
    {
        $client = strtolower(trim((string) ($payload['client'] ?? '')));
        if (!in_array($client, ['mweb', 'h5'], true)) {
            return false;
        }

        return trim((string) ($payload['openid'] ?? '')) === '';
    }

    /**
     * @return array<string, string>|null 小程序 wx.requestPayment 参数
     */
    private static function buildMiniProgramPayParams(string $appId, string $prepayId, \OpenSSLAsymmetricKey|string $privateKey): ?array
    {
        $timeStamp = (string) time();
        $nonceStr  = bin2hex(random_bytes(16));
        $package   = 'prepay_id=' . $prepayId;
        $message   = $appId . "\n" . $timeStamp . "\n" . $nonceStr . "\n" . $package . "\n";
        $signature = '';
        if (!openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return [
            'appId'     => $appId,
            'timeStamp' => $timeStamp,
            'nonceStr'  => $nonceStr,
            'package'   => $package,
            'signType'  => 'RSA',
            'paySign'   => base64_encode($signature),
        ];
    }

    /** @return \OpenSSLAsymmetricKey|string */
    public static function normalizePrivateKeyForRequest(string $key): \OpenSSLAsymmetricKey|string
    {
        return self::normalizePrivateKey($key);
    }

    private static function normalizePrivateKey(string $key): \OpenSSLAsymmetricKey|string
    {
        $key = trim(str_replace(["\r\n", "\r"], "\n", $key));
        if ($key === '') {
            return '';
        }
        if (!str_contains($key, 'BEGIN')) {
            $key = "-----BEGIN PRIVATE KEY-----\n"
                . chunk_split(preg_replace('/\s+/', '', $key) ?: '', 64, "\n")
                . "-----END PRIVATE KEY-----";
        }
        $pk = openssl_pkey_get_private($key);

        return $pk instanceof \OpenSSLAsymmetricKey ? $pk : $key;
    }
}
