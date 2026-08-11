<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\home\controller;

use app\common\service\payment\PaymentChannelRegistry;
use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\PaymentPublicGateway;
use app\common\support\AppService;
use think\facade\Request;
use think\Response;

class PayNotify
{
    private function payments(): PaymentPublicGateway
    {
        /** @var PaymentPublicGateway $gw */
        $gw = AppService::make(PaymentPublicGateway::class);

        return $gw;
    }

    public function alipay(): Response
    {
        if (!Request::isPost()) {
            return response('fail', 405);
        }
        $input  = Request::post();
        $result = $this->payments()->handleNotify(PaymentConfigService::CHANNEL_ALIPAY, $input);

        return response((string) ($result['response'] ?? 'fail'), 200);
    }

    public function wechat(): Response
    {
        $rawBody = (string) file_get_contents('php://input');
        $input   = Request::post();
        if ($input === [] && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            $input   = is_array($decoded) ? $decoded : [];
        }
        $headers = self::wechatNotifyHeaders();
        $result  = $this->payments()->handleNotify(
            PaymentConfigService::CHANNEL_WECHAT,
            $input,
            $rawBody,
            $headers,
        );

        return response((string) ($result['response'] ?? self::wechatFail()), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    /** 扩展通道异步通知（官方 wechat/alipay 仍走专用 action，此处供插件注册通道） */
    public function channel(string $channel): Response
    {
        $channel = strtolower(trim($channel));
        if ($channel === PaymentConfigService::CHANNEL_WECHAT) {
            return $this->wechat();
        }
        if ($channel === PaymentConfigService::CHANNEL_ALIPAY) {
            return $this->alipay();
        }
        if (!app(PaymentChannelRegistry::class)->isOnline($channel)) {
            return response('fail', 404);
        }

        $rawBody = (string) file_get_contents('php://input');
        $input   = Request::post();
        if ($input === [] && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $input = $decoded;
            } else {
                parse_str($rawBody, $parsed);
                $input = is_array($parsed) && $parsed !== [] ? $parsed : ['raw' => $rawBody];
            }
        }

        $result = $this->payments()->handleNotify($channel, $input, $rawBody);

        return response(
            (string) ($result['response'] ?? app(PaymentChannelRegistry::class)->notifySuccessBody($channel)),
            200
        );
    }

    /** @return array<string, string> */
    private static function wechatNotifyHeaders(): array
    {
        $names = [
            'Wechatpay-Signature',
            'Wechatpay-Timestamp',
            'Wechatpay-Nonce',
            'Wechatpay-Serial',
        ];
        $out = [];
        foreach ($names as $name) {
            $value = Request::header($name);
            if ($value !== null && $value !== '') {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }

    private static function wechatFail(): string
    {
        return json_encode(['code' => 'FAIL', 'message' => '失败'], JSON_UNESCAPED_UNICODE);
    }
}
