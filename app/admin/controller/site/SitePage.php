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
use app\common\service\content\ContentEditorService;
use app\common\service\site\SitePageService;
use app\common\service\theme\ThemeTemplateCatalogService;
use think\facade\Request;

class SitePage extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly SitePageService $sitePage,
        private readonly ContentEditorService $contentEditor,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            $paged = $this->sitePage->listAdminPaged(Request::get());

            return AdminApiResponse::list(['total' => $paged['total'],
                'list'  => $paged['list'],
                'page'  => $paged['page'],
                'limit' => $paged['limit']]);
        }

        return $this->renderView('site_page/index', [
            'templates' => $this->sitePage->listAdminLayoutTemplates(),
        ]);
    }

    public function form()
    {
        $id   = max(0, (int) Request::get('id', 0));
        $page = $id > 0 ? $this->sitePage->findAdmin($id) : null;
        if ($id > 0 && $page === null) {
            return $this->jsonFail('单页不存在');
        }

        $templates = $this->sitePage->listAdminLayoutTemplates();
        $pageRow     = $page ?? [
            'id'              => 0,
            'title'           => '',
            'path'            => '',
            'tpl_name'        => ThemeTemplateCatalogService::TPL_LIST_PAGE,
            'content'         => '',
            'seo_title'       => '',
            'seo_keywords'    => '',
            'seo_description' => '',
            'status'          => 1,
        ];
        $currentTpl = (string) ($pageRow['tpl_name'] ?? '');
        if ($currentTpl !== '' && !in_array($currentTpl, $templates, true)) {
            $templates[] = $currentTpl;
            sort($templates);
        }

        return $this->renderView('site_page/form', [
            'page'           => $pageRow,
            'templates'      => $templates,
            'isEdit'         => $id > 0,
            'contentEditor'  => $this->contentEditor->current(),
        ]);
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->sitePage->saveAdmin(Request::post()));
    }

    public function status()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->sitePage->updateStatusAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('status', 0)
        ));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->sitePage->deleteAdmin((int) Request::post('id', 0)));
    }
}
