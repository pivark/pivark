<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\site;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\service\favorite\FavoriteConfigService;
use app\common\service\favorite\FavoriteService;
use think\facade\Request;
use think\Response;

/** 点赞收藏（内核）后台 */
class Favorite extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly FavoriteService $favorite,
        private readonly FavoriteConfigService $favoriteConfig,
    ) {
        parent::__construct($csrf);
    }

    public function index(): Response
    {
        if (!Request::isAjax() && !Request::isGet()) {
            return AdminApiResponse::fail('');
        }

        $page  = max(1, (int) Request::get('page', 1));
        $limit = 20;
        $pack  = $this->favorite->listAdminRanked($page, $limit);

        return AdminApiResponse::list(['total' => $pack['total'],
            'list'  => $pack['list'],
            'cfg'   => $this->favoriteConfig->adminCfg()]);
    }

    public function configSave(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->favoriteConfig->saveAdmin(Request::post()));
    }
}
