<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\content;

use app\common\service\auth\CsrfService;
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\service\admin\AdminSpaMetaService;
use app\common\service\member\MemberLevelService;
use app\common\service\tag\TagGroupService;
use app\common\service\tag\TagService;
use app\common\service\theme\ThemeTemplateCatalogService;
use app\common\support\AdminBatchSupport;
use think\facade\Request;
use think\Response;

class Tag extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly TagService $tag,
        private readonly TagGroupService $tagGroup,
        private readonly ThemeTemplateCatalogService $themeTemplateCatalog,
        private readonly MemberLevelService $memberLevel,
        private readonly AdminSpaMetaService $spaMeta,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            $params = Request::get();
            if ((string) ($params['tree'] ?? '') === '1') {
                $params['tree'] = 1;
            }
            $result = $this->tag->listAdmin($params);

            return AdminApiResponse::list(['total' => $result['total'],
                'list'  => $result['list'],
                'data'  => $result['list']]);
        }

        return $this->renderView('tag/index', [
            'tagGroups' => $this->tagGroup->listForSelect(),
        ]);
    }

    public function create()
    {
        return $this->renderView('tag/form', [
            'tag'                 => null,
            'isEdit'              => false,
            'tagTemplates'        => $this->tag->listTagTemplates(),
            'tagTemplateOptions'  => $this->themeTemplateCatalog->listOptions(ThemeTemplateCatalogService::SCOPE_TAG),
            'tagGroups'           => $this->tagGroup->listForSelect(),
            'parentOptions'       => $this->tag->listParentOptions(0, 0),
            'memberLevels'        => $this->memberLevel->listActive(),
        ]);
    }

    public function edit()
    {
        $id  = (int) Request::get('id', 0);
        $tag = $this->tag->findAdmin($id);
        if (!$tag) {
            return redirect('/admin/tag/index');
        }

        return $this->renderView('tag/form', [
            'tag'                 => $tag,
            'isEdit'              => true,
            'tagTemplates'        => $this->tag->listTagTemplates(),
            'tagTemplateOptions'  => $this->themeTemplateCatalog->listOptions(ThemeTemplateCatalogService::SCOPE_TAG),
            'tagGroups'           => $this->tagGroup->listForSelect(),
            'parentOptions'       => $this->tag->listParentOptions($id, (int) ($tag['group_id'] ?? 0)),
            'memberLevels'        => $this->memberLevel->listActive(),
        ]);
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $res = $this->tag->saveAdmin(Request::post());
        if ($res->isOk()) {
            $tagId = (int) ($res->dataArray()['id'] ?? Request::post('id', 0));
            $url   = $tagId > 0 ? '/admin/tag/edit?id=' . $tagId : '/admin/tag/index';

            return AdminApiResponse::fromResult(ServiceResult::ok(
                array_merge($res->dataArray(), ['url' => $url]),
                $res->message(),
                $res->meta(),
            ));
        }

        return AdminApiResponse::fromResult($res);
    }

    public function sort()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->tag->updateSortAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('sort', 0)
        ));
    }

    public function status()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->tag->updateStatusAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('status', 0)
        ));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->tag->deleteAdmin((int) Request::post('id', 0)));
    }

    public function merge()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->tag->mergeAdmin(
            (int) Request::post('source_id', 0),
            (int) Request::post('target_id', 0)
        ));
    }

    public function reconcile()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->tag->reconcileUseCount((int) Request::post('id', 0)));
    }

    public function batchDelete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->tag->batchDeleteAdmin(
            AdminBatchSupport::parsePostIds()
        ));
    }

    public function batchStatus()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->tag->batchSetStatusAdmin(
            AdminBatchSupport::parsePostIds(),
            (int) Request::post('status', 0)
        ));
    }

    public function batchReconcile()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->tag->batchReconcileAdmin(
            AdminBatchSupport::parsePostIds()
        ));
    }

    /** GET — 上级栏目下拉（切换分组时刷新） */
    public function parentOptions()
    {
        return AdminApiResponse::fromResult(ServiceResult::ok($this->tag->listParentOptions(
                (int) Request::get('exclude_id', 0),
                (int) Request::get('group_id', 0)
            )));
    }

    /** GET — 合并目标下拉 / 表单联想 */
    public function options()
    {
        $keyword = trim((string) Request::get('keyword', ''));
        $exclude = (int) Request::get('exclude_id', 0);
        $result  = $this->tag->listAdmin(['keyword' => $keyword, 'limit' => 50, 'status' => 1]);
        $list    = [];
        foreach ($result['list'] as $row) {
            if ($exclude > 0 && (int) $row['id'] === $exclude) {
                continue;
            }
            $list[] = $row;
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($list));
    }

    /** GET — 标签详情（REST · 原 Spa::tagDetail） */
    public function detail(): Response
    {
        $row = $this->spaMeta->tagDetail((int) Request::get('id', 0));
        if ($row === null) {
            return AdminApiResponse::fromResult(ServiceResult::notFound('标签不存在'));
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($row));
    }
}
