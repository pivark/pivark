<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\support\AdminSpa;

/** 接口与提醒（支付 / 邮件 / 短信 / 提醒设置）合并页 */
class ChannelsConfig extends \app\admin\controller\Base
{
    /** Vue History 深链：须回 HTML 壳，禁止 redirect 回同 path（302 死循环） */
    public function index()
    {
        return AdminSpa::respond();
    }
}
