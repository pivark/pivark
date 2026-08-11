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
use app\common\support\ServiceResult;

use app\common\support\AdminApiResponse;
use app\common\service\search\SearchQueryLogService;
use app\common\service\search\SmartSearchSynonymService;
use think\facade\Request;

/** 搜索词日志（无结果运营） */
class SearchQueryLog extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly SearchQueryLogService $searchQueryLog,
        private readonly SmartSearchSynonymService $smartSearchSynonym,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        $page     = max(1, (int) Request::get('page', 1));
        $limit    = max(1, min(100, (int) Request::get('limit', 20)));
        $zeroOnly = (int) Request::get('zero_only', 0) === 1;

        return AdminApiResponse::fromResult(ServiceResult::ok($this->searchQueryLog->listAdmin($page, $limit, $zeroOnly)));
    }

    public function appendSynonym()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->smartSearchSynonym->append(
            (string) Request::post('from', ''),
            (string) Request::post('to', ''),
            (string) Request::post('param', ''),
        ));
    }
}
