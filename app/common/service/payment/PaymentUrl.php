<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment;

use app\common\support\SiteUrl;

class PaymentUrl
{
    /** 支付回调等绝对 URL：跟 SiteUrl::absolute（强制 HTTPS / site_url），禁止平行拼装 */
    public function absolute(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return SiteUrl::absolute($path);
    }

    public function notifyUrlForChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if ($channel === '') {
            return '';
        }

        return $this->absolute('/pay/notify/' . rawurlencode($channel));
    }
}
