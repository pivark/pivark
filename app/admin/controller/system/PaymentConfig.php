<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\support\SiteUrl;

/** 系统内置支付配置页 */
class PaymentConfig extends \app\admin\controller\Base
{
    public function index()
    {
        return redirect(SiteUrl::adminSpa('/system/channels?pane=payment'));
    }
}
