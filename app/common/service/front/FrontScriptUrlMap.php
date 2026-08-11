<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\front;

use app\common\service\member\MemberCenterPageRegistry;
use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\support\SiteUrl;

/** 前台 JS 统一 URL 表（注入 window.PV.urls / PV_URLS） */
class FrontScriptUrlMap
{
    public function __construct(
        private readonly FrontScriptUrlRegistry $frontScriptUrlRegistry,
        private readonly PluginOfferBridgeRegistry $pluginOfferBridgeRegistry,
        private readonly MemberCenterPageRegistry $memberCenterPageRegistry,
    ) {
    }

    /** @return array<string, string> */
    public function coreMap(): array
    {
        $core = [
            'home'                   => SiteUrl::home(),
            'search'                 => SiteUrl::search(),
            'contact'                => SiteUrl::pageByTpl('contact'),
            'memberLogin'            => SiteUrl::memberApi('login'),
            'memberRegister'         => SiteUrl::memberApi('register'),
            'memberProfile'          => SiteUrl::memberApi('profile'),
            'memberSignin'           => SiteUrl::memberApi('signin'),
            'memberPassword'         => SiteUrl::memberApi('password'),
            'memberCancel'           => SiteUrl::memberApi('cancel'),
            'memberDocumentSave'     => SiteUrl::memberApi('document/save'),
            'memberRechargeBalance' => SiteUrl::memberApi('recharge/balance'),
            'memberUploadImage'      => SiteUrl::memberApi('upload/image'),
            'memberPayStatus'        => SiteUrl::memberApi('pay/status'),
            'memberPayReturn'        => '/member/pay/return',
            'apiSearchSuggest'       => '/api/v1/search/suggest',
            'apiSearchClick'         => '/api/v1/search/click',
            'apiFormsSubmit'         => '/api/v1/forms/submit',
        ];
        foreach ($this->frontScriptUrlRegistry->coreEntries() as $key => $url) {
            $core[$key] = $url;
        }

        return $core;
    }

    /** @return array<string, array<string, string>> */
    public function moduleMaps(): array
    {
        $maps = [
            'favorite' => [
                'apiFavoriteCollect' => '/api/v1/favorite/collect',
                'apiFavoriteLike'    => '/api/v1/favorite/like',
            ],
            'commerce' => $this->commerceModuleMap(),
        ];
        foreach ($this->frontScriptUrlRegistry->allModules() as $identifier => $urls) {
            if ($urls !== []) {
                $maps[$identifier] = $urls;
            }
        }

        return $maps;
    }

    /** @return array<string, string> */
    public function map(): array
    {
        $out = $this->coreMap();
        foreach ($this->moduleMaps() as $slice) {
            $out = array_merge($out, $slice);
        }

        return $out;
    }

    /**
     * @param list<string> $modules 额外模块（不含 core，core 始终合并）
     *
     * @return array<string, string>
     */
    public function mapForModules(array $modules): array
    {
        $out    = $this->coreMap();
        $lookup = $this->moduleMaps();
        foreach ($modules as $module) {
            if (isset($lookup[$module])) {
                $out = array_merge($out, $lookup[$module]);
            }
        }

        return $out;
    }

    public function jsonForTemplate(): string
    {
        return $this->encodeJson($this->coreMap());
    }

    /**
     * @param list<string> $modules
     */
    public function jsonForModules(array $modules): string
    {
        return $this->encodeJson($this->mapForModules($modules));
    }

    /** @return array<string, string> */
    private function commerceModuleMap(): array
    {
        $id = $this->pluginOfferBridgeRegistry->identifier();
        if ($id === null || $id === '') {
            return [];
        }
        $apiBase = '/api/v1/plugins/' . $id;
        $member  = $this->memberCenterPageRegistry->templateMemberUrlVars();
        $ordersUrl     = '';
        $addressesUrl  = '';
        foreach ($member as $key => $url) {
            if (str_ends_with($key, '_orders_url')) {
                $ordersUrl = (string) $url;
            }
            if (str_ends_with($key, '_addresses_url')) {
                $addressesUrl = (string) $url;
            }
        }

        return [
            'mall'               => SiteUrl::commerceMall(),
            'cart'               => SiteUrl::commerceCart(),
            'checkout'           => SiteUrl::commerceCheckout(),
            'market'             => SiteUrl::commerceMarket(),
            'memberOrders'       => $ordersUrl,
            'memberAddresses'    => $addressesUrl,
            'apiBase'            => $apiBase,
            'apiCancel'          => $apiBase . '/cancel',
            'apiOrderConfirm'    => $apiBase . '/order/confirm',
            'apiRefundApply'     => $apiBase . '/refund/apply',
            'apiRefundReturnShip'=> $apiBase . '/refund/return-ship',
            'apiLogisticsTrace'  => $apiBase . '/logistics/trace',
            'apiCheckout'        => $apiBase . '/checkout',
            'apiMeta'            => $apiBase . '/meta',
            'apiItemsCatalog'    => '/api/v1/catalog/items',
        ];
    }

    /** @param array<string, string> $map */
    private function encodeJson(array $map): string
    {
        $json = json_encode(
            $map,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return is_string($json) ? $json : '{}';
    }
}
