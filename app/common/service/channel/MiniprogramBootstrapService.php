<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */

declare(strict_types=1);



namespace app\common\service\channel;
use app\common\service\channel\MiniprogramFeatureService;
use app\common\service\channel\MiniprogramConfigService;
use app\common\service\channel\MiniprogramPageConfigService;

use app\common\service\config\ConfigService;
use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\PaymentUrl;



/** 小程序启动一次拉齐：渠道 + 站点 + 首页装修 + 能力开关 */

final class MiniprogramBootstrapService
{

    public function __construct(
        private readonly MiniprogramPageConfigService $miniprogramPageConfigService,
        private readonly ConfigService $configService,
        private readonly MiniprogramConfigService $miniprogramConfigService,
        private readonly MiniprogramFeatureService $miniprogramFeatureService,
        private readonly PaymentUrl $paymentUrl,
        private readonly PaymentConfigService $paymentConfigService,
    ) {
    }

    /** @return array<string, mixed> */

    public function payload(): array

    {

        $home = $this->miniprogramPageConfigService->publicHomePayload();

        $site = [];

        foreach (

            [

                'site_name',

                'site_title',

                'site_url',

                'site_logo',

                'site_copyright',

                'site_icp',

                'site_keywords',

                'site_description',

            ] as $key

        ) {

            $site[$key] = (string) $this->configService->get($key, '');

        }



        $tags = $this->miniprogramPageConfigService->publicTagsPayload();

        unset($tags['tabbar']);

        $products = $this->miniprogramPageConfigService->publicProductsPayload();

        unset($products['tabbar']);

        $mine = $this->miniprogramPageConfigService->publicMinePayload();

        unset($mine['tabbar']);



        return [

            'channel'  => $this->miniprogramConfigService->publicWechatPayload(),

            'site'     => $site,

            'home'     => $home,

            'tags'     => $tags,

            'products' => $products,

            'mine'     => $mine,

            'features' => $this->miniprogramFeatureService->forClient(),

            'delivery' => $this->deliveryHints(),

        ];

    }



    /** @return array<string, mixed> */

    private function deliveryHints(): array

    {

        $notify = $this->paymentUrl->absolute('/pay/notify/wechat');



        return [

            'notify_url'        => $notify,

            'notify_https'      => str_starts_with($notify, 'https://') ? 1 : 0,

            'wechat_pay_ready'  => $this->paymentConfigService->channelReady(

                \app\common\service\payment\PaymentConfigService::CHANNEL_WECHAT

            ) ? 1 : 0,

            'payment_live'      => $this->paymentConfigService->payMode() === \app\common\service\payment\PaymentConfigService::MODE_LIVE ? 1 : 0,

            'security_checklist'=> [

                '微信公众平台 IP 白名单需包含服务器公网 IP（网页 OAuth）',

                'AppSecret / API 密钥勿提交 git；泄露后应在微信/商户平台重置',

                '生产环境 notify 须 HTTPS 公网可达',

            ],

        ];

    }

}

