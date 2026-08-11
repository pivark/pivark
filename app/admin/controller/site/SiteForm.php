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
use app\common\service\export\AdminDataExportSupport;
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\support\AdminBatchSupport;
use app\common\service\site\SiteFormService;
use think\facade\Request;

class SiteForm extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly SiteFormService $siteForm,
        private readonly AdminDataExportSupport $adminDataExport,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            $formId  = (int) Request::get('form_id', 0);
            $slug    = trim((string) Request::get('slug', ''));
            if ($formId < 1 && $slug !== '') {
                $formId = $this->siteForm->findIdBySlug($slug);
            }
            $page    = max(1, (int) Request::get('page', 1));
            $limit   = min(max((int) Request::get('limit', 20), 1), 100);
            $status  = (int) Request::get('status', -1);
            $keyword = trim((string) Request::get('keyword', Request::get('q', '')));
            $wantSubs = $formId > 0 && Request::has('page');

            if ($wantSubs) {
                return AdminApiResponse::list(['list' => $this->siteForm->listAdminPaged([
                        'id'    => $formId,
                        'page'  => 1,
                        'limit' => 1,
                    ])['list'],
                    'subs' => $this->siteForm->listSubmissionsAdmin($formId, $page, $limit, $status, $keyword)]);
            }

            $forms = $this->siteForm->listAdminPaged([
                'page'    => $page,
                'limit'   => $limit,
                'keyword' => $keyword,
                'id'      => $formId,
                'slug'    => $slug,
            ]);

            return AdminApiResponse::list(['list'  => $forms['list'],
                'total' => $forms['total'],
                'page'  => $forms['page'],
                'limit' => $forms['limit']]);
        }

        return $this->renderView('site_form/index');
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteForm->saveAdmin(Request::post()));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteForm->deleteFormAdmin((int) Request::post('id', 0)));
    }

    public function batchDelete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteForm->batchDeleteFormsAdmin(
            AdminBatchSupport::parsePostIds()
        ));
    }

    public function submissionStatus()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteForm->updateSubmissionStatus(
            (int) Request::post('id', 0),
            (int) Request::post('status', 0)
        ));
    }

    public function submissionBatchStatus()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $ids = AdminBatchSupport::parsePostIds();

        return AdminApiResponse::admin($this->siteForm->batchUpdateSubmissionStatusAdmin(
            $ids,
            (int) Request::post('status', 0)
        ));
    }

    public function submissionDelete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->siteForm->deleteSubmissionAdmin((int) Request::post('id', 0)));
    }

    public function submissionDetail()
    {
        $id  = (int) Request::get('id', 0);
        $row = $this->siteForm->getSubmissionAdmin($id);
        if ($row === null) {
            return AdminApiResponse::fail('提交记录不存在');
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($row));
    }

    public function submissionExport()
    {
        $formId = (int) Request::get('form_id', 0);
        if ($formId < 1) {
            $slug = trim((string) Request::get('slug', ''));
            if ($slug !== '') {
                $formId = $this->siteForm->findIdBySlug($slug);
            }
        }
        if ($formId < 1) {
            return AdminApiResponse::fail('请指定表单');
        }
        $status  = (int) Request::get('status', -1);
        $keyword = trim((string) Request::get('keyword', Request::get('q', '')));
        $ids     = [];
        $idsRaw  = trim((string) Request::get('ids', ''));
        if ($idsRaw !== '') {
            foreach (explode(',', $idsRaw) as $part) {
                $id = (int) trim($part);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $pack = $this->siteForm->exportSubmissionsCsvAdmin($formId, $status, $keyword, $ids);
        $scope = $ids !== [] ? 'selected' : (trim((string) Request::get('export_scope', '')) === 'filter' ? 'filter' : 'all');

        return $this->adminDataExport->respondPack(
            $pack,
            'text/csv; charset=UTF-8',
            'admin.form.list',
            ['export_scope' => $scope, 'form_id' => $formId],
            '导出 CSV',
            'form.submissions',
        );
    }

    public function submissionExportAsyncStart()
    {
        [$formId, $status, $keyword, $ids] = $this->parseSubmissionExportParams();

        return AdminApiResponse::admin($this->siteForm->exportSubmissionsAsyncStart($formId, $status, $keyword, $ids));
    }

    public function submissionExportAsyncStep()
    {
        [$formId, $status, $keyword, $ids] = $this->parseSubmissionExportParams();

        return AdminApiResponse::admin($this->siteForm->exportSubmissionsAsyncStep(
            (string) Request::get('job_id', ''),
            $formId,
            $status,
            $keyword,
            $ids,
        ));
    }

    public function submissionExportAsyncDownload()
    {
        $pack = $this->siteForm->exportSubmissionsAsyncDownload((string) Request::get('job_id', ''));
        if ($pack === null) {
            return AdminApiResponse::fail('导出任务无效或已过期');
        }

        return $this->adminDataExport->respondPack(
            $pack,
            'text/csv; charset=UTF-8',
            'admin.form.list',
            ['export_scope' => 'filter', 'transport' => 'async'],
            '导出 CSV',
            'form.submissions',
        );
    }

    /** @return array{0:int,1:int,2:string,3:list<int>} */
    private function parseSubmissionExportParams(): array
    {
        $formId = (int) Request::param('form_id', 0);
        if ($formId < 1) {
            $slug = trim((string) Request::param('slug', ''));
            if ($slug !== '') {
                $formId = $this->siteForm->findIdBySlug($slug);
            }
        }
        $status  = (int) Request::param('status', -1);
        $keyword = trim((string) Request::param('keyword', Request::param('q', '')));
        $ids     = [];
        $idsRaw  = trim((string) Request::param('ids', ''));
        if ($idsRaw !== '') {
            foreach (explode(',', $idsRaw) as $part) {
                $id = (int) trim($part);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return [$formId, $status, $keyword, $ids];
    }
}
