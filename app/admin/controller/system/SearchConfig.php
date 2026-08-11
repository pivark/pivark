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
use app\common\service\search\SearchConfigService;
use app\common\service\search\SmartSearchAdminService;
use think\facade\Request;

/** 搜索管理（全文引擎 + 超级搜索智能化） */
class SearchConfig extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly SearchConfigService $searchConfig,
        private readonly SmartSearchAdminService $smartSearchAdmin,
    ) {
        parent::__construct($csrf);
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $data   = Request::post();
        $result = $this->searchConfig->saveAdmin($data);
        if (!$result->isOk()) {
            return AdminApiResponse::admin($result);
        }

        return AdminApiResponse::admin($this->smartSearchAdmin->saveAdmin($data));
    }

    public function index()
    {
        return redirect(\app\common\support\SiteUrl::adminSpa('/system/search'));
    }
}
