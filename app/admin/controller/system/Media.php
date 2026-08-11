<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\support\SiteUrl;

use app\common\support\ServiceResult;

use app\common\support\AdminApiResponse;
use app\common\support\AdminBatchSupport;
use app\common\service\export\AdminDataExportSupport;
use app\common\service\auth\CsrfService;
use app\common\service\enterprise\EnterpriseResourceService;
use app\common\service\kernel\KernelModuleRegistry;
use app\common\service\media\MediaLibraryService;
use app\common\service\media\MediaPurgeBatchService;
use app\common\support\UploadGate;
use app\common\support\UploadGateException;
use think\facade\Request;

// app/admin/controller/Media.php — 素材库（扫描本地上传目录）

class Media extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly EnterpriseResourceService $enterpriseResource,
        private readonly KernelModuleRegistry $kernelModuleRegistry,
        private readonly AdminDataExportSupport $adminDataExport,
        private readonly MediaLibraryService $mediaLibrary,
        private readonly MediaPurgeBatchService $mediaPurgeBatch,
    ) {
        parent::__construct($csrf);
    }

    /** GET /admin/media/index — 素材库管理页 */
    public function index()
    {
        return $this->renderView('media/index');
    }

    /** GET /admin/media/meta — 素材库分组与 L1 模块状态 */
    public function meta()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        $tabs = [
            ['id' => 'web', 'label' => '网站图片', 'kind' => 'image'],
            ['id' => 'files', 'label' => '附件文件', 'kind' => 'software'],
        ];
        if ($this->enterpriseResource->isActive()) {
            $tabs[] = [
                'id'     => 'enterprise',
                'label'  => '企业经营资料',
                'kind'   => 'enterprise',
                'module' => 'enterprise_resource',
            ];
        }

        return AdminApiResponse::fromResult(ServiceResult::ok([
                'tabs'    => $tabs,
                'modules' => [
                    'enterprise_resource' => $this->enterpriseResource->isActive(),
                ],
                'active_modules' => $this->kernelModuleRegistry->activeModules(),
            ]));;
    }

    /** GET /admin/media/enterpriseMeta */
    public function enterpriseMeta()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!$this->enterpriseResource->isActive()) {
            // 读接口友好空态：菜单可达但模块未开时勿 422+Toast，避免控制台假红
            return AdminApiResponse::fromResult(ServiceResult::ok([
                'module_disabled' => true,
                'stats' => ['total' => 0, 'expiring' => 0, 'entities' => 0],
                'type_labels' => [],
            ]));
        }

        $scope = trim((string) Request::get('scope', ''));

        return AdminApiResponse::fromResult(ServiceResult::ok($this->enterpriseResource->meta($scope)));;
    }

    /** POST /admin/media/enterpriseEntitySave */
    public function enterpriseEntitySave()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');;
        }

        return AdminApiResponse::admin($this->enterpriseResource->saveEntity(Request::post()));
    }

    /** POST /admin/media/enterpriseEntityArchive */
    public function enterpriseEntityArchive()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');;
        }

        return AdminApiResponse::admin($this->enterpriseResource->archiveEntity((int) Request::post('id', 0)));
    }

    /** GET /admin/media/enterpriseEntityList */
    public function enterpriseEntityList()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');;
        }

        $view = trim((string) Request::get('view', 'active'));

        return AdminApiResponse::fromResult(ServiceResult::ok([
            'list' => $this->enterpriseResource->listEntities($view),
        ]));;
    }

    /** POST /admin/media/enterpriseEntityRestore */
    public function enterpriseEntityRestore()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');;
        }

        return AdminApiResponse::admin($this->enterpriseResource->restoreEntity((int) Request::post('id', 0)));
    }

    /** POST /admin/media/enterpriseEntityDelete */
    public function enterpriseEntityDelete()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');;
        }

        $assetMode = trim((string) Request::post('asset_mode', 'keep_shared'));

        return AdminApiResponse::admin($this->enterpriseResource->deleteEntity(
            (int) Request::post('id', 0),
            $assetMode,
        ));
    }

    /** GET /admin/media/enterpriseList */
    public function enterpriseList()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fromResult(ServiceResult::ok([
                'module_disabled' => true,
                'list' => [],
                'total' => 0,
            ]));
        }

        $page      = max(1, (int) Request::get('page', 1));
        $limit     = min(max((int) Request::get('limit', 20), 1), 100);
        $keyword   = trim((string) Request::get('keyword', ''));
        $entityId  = (int) Request::get('entity_id', 0);
        $assetType = trim((string) Request::get('asset_type', ''));
        $scope     = trim((string) Request::get('scope', ''));
        $expiringOnly = trim((string) Request::get('expiring_only', '')) === '1';

        return AdminApiResponse::fromResult(ServiceResult::ok($this->enterpriseResource->listAdmin(
                $page,
                $limit,
                $keyword,
                $entityId,
                $assetType,
                $scope,
                $expiringOnly,
            )));;
    }

    /** POST /admin/media/enterpriseSave */
    public function enterpriseSave()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');;
        }

        return AdminApiResponse::admin($this->enterpriseResource->save(Request::post()));
    }

    /** POST /admin/media/enterpriseArchive */
    public function enterpriseArchive()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');;
        }

        return AdminApiResponse::admin($this->enterpriseResource->archive((int) Request::post('id', 0)));
    }

    /** POST /admin/media/enterpriseDelete */
    public function enterpriseDelete()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');;
        }

        return AdminApiResponse::admin($this->enterpriseResource->purge((int) Request::post('id', 0)));
    }

    /** POST /admin/media/enterpriseBatchArchive */
    public function enterpriseBatchArchive()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');;
        }

        return AdminApiResponse::admin(
            $this->enterpriseResource->archiveBatch(AdminBatchSupport::parsePostIds()),
        );
    }

    /** POST /admin/media/enterpriseBatchDelete */
    public function enterpriseBatchDelete()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');;
        }

        return AdminApiResponse::admin(
            $this->enterpriseResource->purgeBatch(AdminBatchSupport::parsePostIds()),
        );
    }

    /** GET /admin/media/enterpriseExport */
    public function enterpriseExport()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');
        }

        $pack = $this->enterpriseResource->exportAdminCsv(Request::get());

        return $this->adminDataExport->respondPack(
            $pack,
            'text/csv; charset=UTF-8',
            'admin.media.list',
            ['export_scope' => 'filter'],
            '导出 CSV',
            'enterprise_resource.list',
        );
    }

    /** POST /admin/media/enterpriseEntityConsolidate */
    public function enterpriseEntityConsolidate()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        if (!$this->enterpriseResource->isActive()) {
            return AdminApiResponse::fail('企业经营资料模块未启用');
        }

        return AdminApiResponse::admin($this->enterpriseResource->consolidateDuplicateEntities());
    }

    /** GET /admin/media/list — 图片列表（分页、搜索、目录筛选） */
    public function list()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        $kind = trim((string) Request::get('kind', 'image'));
        $params = [
            'page'        => Request::get('page', 1),
            'limit'       => Request::get('limit', 20),
            'keyword'     => Request::get('keyword', ''),
            'folder'      => Request::get('folder', ''),
            'upload_date' => Request::get('upload_date', ''),
        ];
        $memberId = UploadGate::memberUserId();
        if ($kind === 'software') {
            $data = $memberId > 0
                ? ['total' => 0, 'list' => [], 'folders' => [['id' => '', 'name' => '全部附件', 'count' => 0]], 'page' => 1, 'limit' => (int) $params['limit']]
                : $this->mediaLibrary->listSoftware($params);
        } elseif ($memberId > 0) {
            $data = $this->mediaLibrary->listImagesForMember($memberId, $params);
        } else {
            $data = $this->mediaLibrary->listImages($params);
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($data));;
    }

    /** POST /admin/media/delete — 删除本地图片（path 或 url） */
    public function delete()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        $path = trim((string) Request::post('path', ''));
        if ($path === '') {
            $path = trim((string) Request::post('url', ''));
        }
        if ($path === '') {
            return AdminApiResponse::fail('请指定要删除的文件');;
        }

        $memberId = UploadGate::memberUserId();
        if ($memberId > 0 && !$this->mediaLibrary->memberOwnsUploadPath($memberId, $path)) {
            return AdminApiResponse::fail('无权删除该文件');
        }

        return AdminApiResponse::admin($this->mediaLibrary->deleteImage($path));
    }

    /** POST /admin/media/batchDelete — 批量彻底删除 */
    public function batchDelete()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        $paths = AdminBatchSupport::parsePostStringList('paths');

        return AdminApiResponse::admin($this->mediaLibrary->batchDeleteImages($paths));
    }

    /** POST /admin/media/move — 批量移动到目录 */
    public function move()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        $paths  = AdminBatchSupport::parsePostStringList('paths');
        $target = trim((string) Request::post('target', ''));

        return AdminApiResponse::admin($this->mediaLibrary->moveImages($paths, $target));
    }

    /** GET /admin/media/invalidPreview — 无效资源扫描预览 */
    public function invalidPreview()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        $kind = trim((string) Request::get('kind', 'image'));

        return AdminApiResponse::fromResult(ServiceResult::ok($this->mediaLibrary->scanInvalidResources([
                'kind'   => $kind,
                'folder' => Request::get('folder', ''),
                'mode'   => Request::get('mode', ''),
            ])));;
    }

    /** POST /admin/media/purgeInvalid — 一键清理无效资源 */
    public function purgeInvalid()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        $kind = trim((string) Request::post('kind', 'image'));

        return AdminApiResponse::admin($this->mediaLibrary->purgeInvalidResources([
            'kind'   => $kind,
            'folder' => Request::post('folder', ''),
            'mode'   => Request::post('mode', ''),
        ]));
    }

    /** POST /admin/media/purgeInvalidBatchStart — 分批清理无效资源（启动） */
    public function purgeInvalidBatchStart()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        $kind = trim((string) Request::post('kind', 'image'));

        return AdminApiResponse::admin($this->mediaPurgeBatch->start([
            'kind'   => $kind,
            'folder' => Request::post('folder', ''),
            'mode'   => Request::post('mode', ''),
        ]));
    }

    /** POST /admin/media/purgeInvalidBatchStep — 分批清理无效资源（步进） */
    public function purgeInvalidBatchStep()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->mediaPurgeBatch->step((string) Request::post('job_id', '')));
    }

    /** GET /admin/media/deletePreview — 删除前引用检查 */
    public function deletePreview()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        $path = trim((string) Request::get('path', ''));
        if ($path === '') {
            $path = trim((string) Request::get('url', ''));
        }
        if ($path === '') {
            return AdminApiResponse::fail('请指定文件');;
        }

        $memberId = UploadGate::memberUserId();
        if ($memberId > 0 && !$this->mediaLibrary->memberOwnsUploadPath($memberId, $path)) {
            return AdminApiResponse::fail('无权删除该文件');
        }

        $kind = trim((string) Request::get('kind', 'image'));

        return AdminApiResponse::fromResult(ServiceResult::ok($this->mediaLibrary->previewDelete($path, $kind)));;
    }

    /** POST /admin/media/deletePreviewBatch — 批量删除前引用检查 */
    public function deletePreviewBatch()
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        $paths = AdminBatchSupport::parsePostStringList('paths');

        return AdminApiResponse::fromResult(ServiceResult::ok($this->mediaLibrary->previewDeletes($paths)));;
    }
}
