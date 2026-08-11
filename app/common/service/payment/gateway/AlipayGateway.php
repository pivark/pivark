<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment\gateway;

use app\common\support\MoneyMath;

use app\common\support\AppTime;

use app\common\support\ServiceResult;

use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\PaymentUrl;

class AlipayGateway implements PaymentGatewayInterface
{
    private const GATEWAY = 'https://openapi.alipay.com/gateway.do';

    public function channel(): string
    {
        return 'alipay';
    }

    public function createPayment(array $order, array $config): ServiceResult
    {
        $appId      = trim((string) ($config['alipay_app_id'] ?? ''));
        $privateKey = self::normalizePrivateKey((string) ($config['alipay_private_key'] ?? ''));
        if ($appId === '' || $privateKey === '') {
            return ServiceResult::fail('支付宝未配置');
        }

        $orderNo = (string) ($order['order_no'] ?? '');
        $amount  = MoneyMath::formatPlain((float) ($order['amount'] ?? 0));
        $subject = (string) ($order['subject'] ?? '在线支付');
        if ($orderNo === '' || (float) $amount <= 0) {
            return ServiceResult::fail('订单参数无效');
        }

        $biz = json_encode([
            'out_trade_no' => $orderNo,
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
            'total_amount' => $amount,
            'subject'      => mb_substr($subject, 0, 128),
        ], JSON_UNESCAPED_UNICODE);

        $params = [
            'app_id'      => $appId,
            'method'      => 'alipay.trade.page.pay',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => AppTime::now(),
            'version'     => '1.0',
            'notify_url'  => app(PaymentUrl::class)->absolute('/pay/notify/alipay'),
            'return_url'  => app(PaymentUrl::class)->absolute('/member/pay/return?order_no=' . rawurlencode($orderNo)),
            'biz_content' => (string) $biz,
        ];

        $params['sign'] = self::sign($params, $privateKey);
        $formHtml       = self::buildAutoSubmitForm(self::GATEWAY, $params);

        return ServiceResult::ok(['type' => 'form', 'form_html' => $formHtml], 'ok');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $config
     * @param array<string, string> $notifyHeaders
     */
    public function parseNotify(array $input, string $rawBody, array $config, array $notifyHeaders = []): array
    {
        unset($rawBody);
        if ($input === []) {
            return ['ok' => false, 'msg' => 'empty notify'];
        }

        $publicKey = self::normalizePublicKey((string) ($config['alipay_public_key'] ?? ''));
        if ($publicKey === '') {
            return ['ok' => false, 'msg' => 'alipay public key missing'];
        }

        $sign = (string) ($input['sign'] ?? '');
        if ($sign === '') {
            return ['ok' => false, 'msg' => 'sign missing'];
        }

        $verifyData = $input;
        unset($verifyData['sign'], $verifyData['sign_type']);
        ksort($verifyData);
        $pairs = [];
        foreach ($verifyData as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }
        $content = implode('&', $pairs);
        $ok      = openssl_verify($content, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA256) === 1;
        if (!$ok) {
            return ['ok' => false, 'msg' => 'sign invalid'];
        }

        $tradeStatus = (string) ($input['trade_status'] ?? '');
        if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return ['ok' => false, 'msg' => 'trade not success', 'raw' => $input];
        }

        return [
            'ok'       => true,
            'order_no' => (string) ($input['out_trade_no'] ?? ''),
            'txn_id'   => (string) ($input['trade_no'] ?? ''),
            'raw'      => $input,
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

        if (app(PaymentConfigService::class)->shouldUseDemo(PaymentConfigService::CHANNEL_ALIPAY)) {
            return ['ok' => true, 'paid' => false, 'order_no' => $orderNo, 'msg' => 'demo mode'];
        }

        $appId      = trim((string) ($config['alipay_app_id'] ?? ''));
        $privateKey = self::normalizePrivateKey((string) ($config['alipay_private_key'] ?? ''));
        if ($appId === '' || $privateKey === '') {
            return ['ok' => false, 'paid' => false, 'msg' => '支付宝未配置'];
        }

        $biz = json_encode(['out_trade_no' => $orderNo], JSON_UNESCAPED_UNICODE);
        $params = [
            'app_id'      => $appId,
            'method'      => 'alipay.trade.query',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => AppTime::now(),
            'version'     => '1.0',
            'biz_content' => (string) $biz,
        ];
        $params['sign'] = self::sign($params, $privateKey);

        $resp = self::gatewayRequest($params);
        if (!$resp->isOk()) {
            return ['ok' => false, 'paid' => false, 'msg' => $resp->message()];
        }

        $data = $resp->dataArray();
        $sub  = is_array($data['alipay_trade_query_response'] ?? null) ? $data['alipay_trade_query_response'] : [];
        if ((string) ($sub['code'] ?? '') !== '10000') {
            return [
                'ok'    => false,
                'paid'  => false,
                'msg'   => (string) ($sub['sub_msg'] ?? $sub['msg'] ?? '支付宝查单失败'),
                'raw'   => $sub,
            ];
        }

        $tradeStatus = (string) ($sub['trade_status'] ?? '');
        $paid        = in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true);

        return [
            'ok'       => true,
            'paid'     => $paid,
            'order_no' => (string) ($sub['out_trade_no'] ?? $orderNo),
            'txn_id'   => (string) ($sub['trade_no'] ?? ''),
            'raw'      => $sub,
        ];
    }

    public function refundPayment(array $order, array $config, string $reason = ''): ServiceResult
    {
        if (app(PaymentConfigService::class)->shouldUseDemo(PaymentConfigService::CHANNEL_ALIPAY)) {
            return ServiceResult::ok(['gateway' => 0], '演示模式：跳过网关退款');
        }

        $appId      = trim((string) ($config['alipay_app_id'] ?? ''));
        $privateKey = self::normalizePrivateKey((string) ($config['alipay_private_key'] ?? ''));
        if ($appId === '' || $privateKey === '') {
            return ServiceResult::fail('支付宝未配置');
        }

        $orderNo = (string) ($order['order_no'] ?? '');
        $amount  = MoneyMath::formatPlain((float) ($order['amount'] ?? 0));
        if ($orderNo === '' || (float) $amount <= 0) {
            return ServiceResult::fail('订单参数无效');
        }

        $biz = json_encode([
            'out_trade_no'   => $orderNo,
            'refund_amount'  => $amount,
            'refund_reason'  => mb_substr($reason !== '' ? $reason : '用户申请退款', 0, 256),
        ], JSON_UNESCAPED_UNICODE);

        $params = [
            'app_id'      => $appId,
            'method'      => 'alipay.trade.refund',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => AppTime::now(),
            'version'     => '1.0',
            'biz_content' => (string) $biz,
        ];
        $params['sign'] = self::sign($params, $privateKey);

        $resp = self::gatewayRequest($params);
        if (!$resp->isOk()) {
            return ServiceResult::fail($resp->message());
        }

        $data = $resp->dataArray();
        $sub  = is_array($data['alipay_trade_refund_response'] ?? null) ? $data['alipay_trade_refund_response'] : [];
        if ((string) ($sub['code'] ?? '') !== '10000') {
            return ServiceResult::fail((string) ($sub['sub_msg'] ?? $sub['msg'] ?? '支付宝退款失败'));
        }

        return ServiceResult::ok(['refund_no' => (string) ($sub['out_trade_no'] ?? $orderNo), 'gateway' => 1], '支付宝退款成功');
    }

    /** @param array<string, mixed> $params */
    private static function gatewayRequest(array $params): ServiceResult
    {
        $ch = curl_init(self::GATEWAY);
        if ($ch === false) {
            return ServiceResult::fail('curl init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ServiceResult::fail($err !== '' ? $err : 'curl error');
        }
        $data = json_decode((string) $body, true);

        return ServiceResult::ok(is_array($data) ? $data : [], 'ok');
    }

    /** @param array<string, mixed> $params @param \OpenSSLAsymmetricKey|resource|string $privateKey */
    private static function sign(array $params, \OpenSSLAsymmetricKey|string $privateKey): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }
        $content = implode('&', $pairs);
        $sign    = '';
        openssl_sign($content, $sign, $privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($sign);
    }

    /** @param array<string, mixed> $params */
    private static function buildAutoSubmitForm(string $action, array $params): string
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>跳转支付宝</title></head><body>';
        $html .= '<form id="alipay-submit" method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($params as $k => $v) {
            $html .= '<input type="hidden" name="' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '" value="'
                . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '">';
        }
        $html .= '</form><script>document.getElementById("alipay-submit").submit();</script></body></html>';

        return $html;
    }

    private static function normalizePrivateKey(string $key): \OpenSSLAsymmetricKey|string
    {
        $key = trim(str_replace(["\r\n", "\r"], "\n", $key));
        if ($key === '') {
            return '';
        }
        if (!str_contains($key, 'BEGIN')) {
            $key = "-----BEGIN RSA PRIVATE KEY-----\n"
                . chunk_split(preg_replace('/\s+/', '', $key) ?: '', 64, "\n")
                . "-----END RSA PRIVATE KEY-----";
        }
        $pk = openssl_pkey_get_private($key);

        return $pk instanceof \OpenSSLAsymmetricKey ? $pk : $key;
    }

    private static function normalizePublicKey(string $key): \OpenSSLAsymmetricKey|string
    {
        $key = trim(str_replace(["\r\n", "\r"], "\n", $key));
        if ($key === '') {
            return '';
        }
        if (!str_contains($key, 'BEGIN')) {
            $key = "-----BEGIN PUBLIC KEY-----\n"
                . chunk_split(preg_replace('/\s+/', '', $key) ?: '', 64, "\n")
                . "-----END PUBLIC KEY-----";
        }
        $pk = openssl_pkey_get_public($key);

        return $pk instanceof \OpenSSLAsymmetricKey ? $pk : $key;
    }
}
