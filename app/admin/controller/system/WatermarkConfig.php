<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\service\watermark\WatermarkConfigService;
use think\facade\Request;

/** 图片水印配置 */
class WatermarkConfig extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly WatermarkConfigService $watermarkConfig,
    ) {
        parent::__construct($csrf);
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->watermarkConfig->saveAdmin(Request::post()));
    }
}
