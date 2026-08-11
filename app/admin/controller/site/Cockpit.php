<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\site;

use app\common\service\admin\AdminCockpitService;
use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use think\facade\Request;

/** 站点运营驾驶舱 */
class Cockpit extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly AdminCockpitService $cockpit,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            return AdminApiResponse::fromResult(ServiceResult::ok($this->cockpit->snapshot(), ''));
        }

        return $this->renderView('cockpit/index');
    }
}
