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
use app\common\service\channel\MiniprogramChannelRegistry;
use app\common\support\AdminApiResponse;
use app\common\service\channel\MiniprogramConfigService;
use app\common\support\SiteUrl;
use think\facade\Request;

/** L5 小程序渠道配置（凭据在 social-auth） */
class MiniprogramConfig extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly MiniprogramChannelRegistry $miniprogramChannelRegistry,
        private readonly MiniprogramConfigService $miniprogramConfig,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        return redirect(SiteUrl::adminSpa('/system/miniprogram/wechat'));
    }

    public function guide()
    {
        return redirect(SiteUrl::adminSpa($this->miniprogramChannelRegistry->guideRoute()));
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->miniprogramConfig->saveAdmin(Request::post()));
    }
}
