<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);


namespace app\admin\controller\content;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\support\ServiceResult;

use app\common\support\AdminApiResponse;
use app\common\support\AdminBatchSupport;
use app\common\service\admin\AdminNavPersonaService;
use app\common\service\auth\CsrfService;
use app\common\service\export\AdminDataExportSupport;
use app\common\service\item\ItemAdminUiService;
use app\common\service\item\ItemPublicVisibilityService;
use app\common\service\item\ItemService;
use app\common\service\item\ItemVariantService;
use app\common\enum\ApiErrorCode;
use app\common\service\product\ProductCenterGateService;
use app\common\service\product\ProductConfigService;
use app\common\service\site\SiteCoreLicenseService;
use app\common\service\tag\TagService;
use think\facade\Request;
use think\facade\Session;
use think\Response;

class Item extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly ItemService $item,
        private readonly ItemAdminUiService $itemAdminUi,
        private readonly ProductCenterGateService $productCenterGate,
        private readonly AdminNavPersonaService $adminNavPersona,
        private readonly ItemPublicVisibilityService $itemPublicVisibility,
        private readonly TagService $tag,
        private readonly ItemVariantService $itemVariant,
        private readonly AdminDataExportSupport $adminDataExport,
    ) {
        parent::__construct($csrf);
    }

    /** 产品中心品项读/meta 授权闸（与 Product 同源） */
    private function productCenterGateResponse(): ?Response
    {
        if ($this->productCenterGate->allowsAdmin()) {
            return null;
        }

        return AdminApiResponse::admin(
            ServiceResult::fail(
                app(SiteCoreLicenseService::class)->proRequiredMessage(),
                ApiErrorCode::CORE_LICENSE_PRO_REQUIRED
            ),
            403
        );
    }

    public function index()
    {
        if (Request::isAjax()) {
            $blocked = $this->productCenterGateResponse();
            if ($blocked !== null) {
                return $blocked;
            }
            $result = $this->item->listAdmin([
                'keyword'      => (string) Request::get('keyword', ''),
                'item_type'    => (string) Request::get('item_type', ''),
                'status'       => (string) Request::get('status', ''),
                'tag_id'       => (int) Request::get('tag_id', 0),
                'nav_id'       => (int) Request::get('nav_id', 0),
                'nav_title'    => (string) Request::get('nav_title', ''),
                'tag'          => (string) Request::get('tag', ''),
                'catalog_line' => (string) Request::get('catalog_line', ''),
                'plugin_kind'  => (string) Request::get('plugin_kind', ''),
                'item_origin'  => (string) Request::get('item_origin', ''),
                'page'         => (int) Request::get('page', 1),
                'limit'        => (int) Request::get('limit', 20),
            ]);

            return AdminApiResponse::list(['total' => $result['total'],
                'list'     => $result['list'],
                'has_more' => (int) ($result['has_more'] ?? 0)]);
        }

        return $this->renderView('item/index');
    }

    public function meta()
    {
        $blocked = $this->productCenterGateResponse();
        if ($blocked !== null) {
            return $blocked;
        }

        $adminUser = Session::get('admin_user', []);
        $userId    = (int) ($adminUser['id'] ?? 0);
        $scope     = trim((string) Request::get('scope', ''));
        if ($scope === 'list') {
            return AdminApiResponse::fromResult(ServiceResult::ok(
                $this->itemAdminUi->listPageMetaPayload($userId),
            ));
        }

        $paramDefs = [];
        $paramGroups = [];
        if ($this->productCenterGate->allowsParams() && class_exists('\\app\\common\\service\\product\\ProductService')) {
            $paramDefs   = \app\common\service\product\ProductService::listParamDefs();
            $paramGroups = \app\common\service\product\ProductService::listParamGroupsWithDefs(['active_only' => true]);
        }

        $health = [];
        if ($this->productCenterGate->allowsParams() && class_exists('\\app\\common\\service\\product\\ProductConfigService')) {
            $health = \app\common\service\product\ProductConfigService::healthCheckAdmin();
        }

        $persona   = $this->adminNavPersona;
        $capabilityModules = $persona->filterItemCapabilityModules(
            $this->item->capabilityModules(),
            $userId,
        );

        return AdminApiResponse::fromResult(ServiceResult::ok([
                'typeLabels'          => $this->item->typeLabels(),
                'typeDescriptions'    => $this->item->typeDescriptions(),
                'typeHints'           => $this->item->typeHints(),
                'capabilityModules' => $capabilityModules,
                'formFieldHints'    => $persona->mergeItemFormFieldHints(
                    $this->item->formFieldHints(),
                    $userId,
                ),
                'uiModules'         => $this->itemAdminUi->modules($userId),
                'contentProfile'    => $persona->itemAdminProfile($userId),
                'navPersona'        => $persona->payload($userId),
                'visibilityRules'   => $this->itemPublicVisibility->rulesPayload(),
                'statusLabels'        => $this->item->statusLabels(),
                'tags'         => $this->tag->listActivePicker(),
                'paramDefs'    => $paramDefs,
                'paramGroups'  => $paramGroups,
                'productPluginEntitled' => $this->productCenterGate->allowsParams(),
                'health'       => $health,
                'officialCatalog' => ProductConfigService::officialProductCenterAdminEnabled()
                    ? (PluginOfficialProduct::dispatch('catalog_meta', [], [
                        'enabled' => false,
                        'lines'   => [],
                        'plugin_kinds' => [],
                    ]) ?: \app\common\service\product\ProductConfigService::catalogLinesMeta())
                    : ['enabled' => false, 'lines' => [], 'plugin_kinds' => []],
                'productCenterAdmin' => ProductConfigService::adminUiPayload(),
                'variantNaming'  => ProductConfigService::variantNamingPayload(),
            ]));;
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->item->saveAdmin(Request::post()));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->item->deleteAdmin((int) Request::post('id', 0)));
    }

    public function duplicate()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        $res = $this->item->duplicateAdmin((int) Request::post('id', 0));
        if ($res->isOk()
            && $this->productCenterGate->allowsParams()
            && class_exists('\\app\\common\\service\\product\\ProductItemRelationService')) {
            $fromId = (int) Request::post('id', 0);
            $toId   = (int) ($res->dataArray()['id'] ?? 0);
            if ($fromId > 0 && $toId > 0) {
                \app\common\service\product\ProductItemRelationService::copyFromParent($fromId, $toId);
            }
        }

        return AdminApiResponse::admin($res);
    }

    public function export()
    {
        if (!$this->productCenterGate->allowsParams()) {
            return AdminApiResponse::fail('需安装并启用「产品展示」插件');;
        }
        $ids = [];
        $idsRaw = trim((string) Request::get('ids', ''));
        if ($idsRaw !== '') {
            foreach (explode(',', $idsRaw) as $part) {
                $id = (int) trim($part);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $pack = \app\common\service\product\ProductCatalogService::exportCsvAdmin([
            'keyword'   => (string) Request::get('keyword', ''),
            'item_type' => (string) Request::get('item_type', ''),
            'status'    => (string) Request::get('status', ''),
            'tag_id'    => (int) Request::get('tag_id', 0),
        ], $ids);
        $scope = $ids !== [] ? 'selected' : (trim((string) Request::get('export_scope', '')) === 'filter' ? 'filter' : 'all');

        return $this->adminDataExport->respondPack(
            $pack,
            'text/csv; charset=UTF-8',
            'admin.item.export',
            ['export_scope' => $scope],
            '导出 CSV',
            'item.list',
        );
    }

    public function import()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!$this->productCenterGate->allowsParams()) {
            return AdminApiResponse::fail('需安装并启用「产品展示」插件');;
        }
        $csv = (string) Request::post('csv', '');
        if ($csv === '' && Request::file('file')) {
            $file = Request::file('file');
            $csv  = (string) file_get_contents((string) $file->getRealPath());
        }

        $onlyValid = !empty(Request::post('only_valid'));

        return AdminApiResponse::admin(\app\common\service\product\ProductCatalogService::importCsvAdmin($csv, $onlyValid));
    }

    public function bulkStatus()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        $ids = AdminBatchSupport::parsePostIds();

        return AdminApiResponse::admin($this->item->batchStatusAdmin(
            $ids,
            (string) Request::post('status', ''),
        ));
    }

    public function bulkDelete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        $ids = AdminBatchSupport::parsePostIds();

        return AdminApiResponse::admin($this->item->batchDeleteAdmin($ids));
    }

    public function importPreview()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!$this->productCenterGate->allowsParams()) {
            return AdminApiResponse::fail('需安装并启用「产品展示」插件');;
        }
        $csv = (string) Request::post('csv', '');
        if ($csv === '' && Request::file('file')) {
            $file = Request::file('file');
            $csv  = (string) file_get_contents((string) $file->getRealPath());
        }

        return AdminApiResponse::admin(\app\common\service\product\ProductCatalogService::importPreviewAdmin($csv));
    }

    public function itemsByDocument()
    {
        if (!$this->productCenterGate->allowsParams() || !class_exists('\\app\\common\\service\\product\\ProductDocumentAdminService')) {
            return AdminApiResponse::fail('需安装并启用「产品展示」插件');;
        }

        return AdminApiResponse::admin(\app\common\service\product\ProductDocumentAdminService::itemsForDocument(
            (int) Request::get('document_id', 0),
        ));
    }

    public function documentsByItem()
    {
        if (!$this->productCenterGate->allowsParams() || !class_exists('\\app\\common\\service\\product\\ProductDocumentAdminService')) {
            return AdminApiResponse::fail('需安装并启用「产品展示」插件');;
        }

        return AdminApiResponse::admin(\app\common\service\product\ProductDocumentAdminService::documentsForItem(
            (int) Request::get('item_id', 0),
        ));
    }

    public function variantsByItem()
    {
        return AdminApiResponse::admin($this->itemVariant->listAdminByItem(
            (int) Request::get('item_id', 0),
        ));
    }

    public function variantSave()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->itemVariant->saveAdmin(Request::post()));
    }

    public function variantDelete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        return AdminApiResponse::admin($this->itemVariant->deleteAdmin((int) Request::post('id', 0)));
    }

    public function ensureDetailDocument()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->item->ensurePrimaryDetailDocument(
            (int) Request::post('item_id', 0),
        ));
    }

    public function reloadPluginDocs()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->item->reloadOfficialPluginDocsAdmin(
            (int) Request::post('item_id', 0),
            (string) Request::post('identifier', ''),
        ));
    }

    public function patchListVisibility()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->item->patchListVisibilityAdmin(
            (int) Request::post('id', 0),
            (string) Request::post('field', ''),
            (int) Request::post('value', 0),
        ));
    }

    public function patchListSort()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->item->patchListSortAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('sort', 0),
        ));
    }

    public function patchMarketplaceListingPrice()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->item->patchMarketplaceListingPriceAdmin(
            (int) Request::post('id', 0),
            (float) Request::post('price', 0),
        ));
    }

    public function patchVariantSort()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->itemVariant->patchSortAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('sort', 0),
        ));
    }

    public function accessoriesByItem()
    {
        if (!class_exists('\\app\\common\\service\\product\\ProductItemRelationService')) {
            return AdminApiResponse::fail('需安装并启用「产品展示」插件');;
        }

        return AdminApiResponse::admin(\app\common\service\product\ProductItemRelationService::listForParentAdmin(
            (int) Request::get('item_id', 0),
        ));
    }

    public function syncAccessories()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        if (!class_exists('\\app\\common\\service\\product\\ProductItemRelationService')) {
            return AdminApiResponse::fail('需安装并启用「产品展示」插件');;
        }
        $rows = Request::post('rows', []);
        if (!is_array($rows)) {
            $rows = [];
        }

        return AdminApiResponse::admin(\app\common\service\product\ProductItemRelationService::syncForParent(
            (int) Request::post('parent_item_id', 0),
            $rows,
        ));
    }

    public function exportAsyncStart()
    {
        if (!$this->productCenterGate->allowsParams()) {
            return AdminApiResponse::fail('需安装并启用「产品展示」插件');;
        }
        $ids = [];
        $idsRaw = trim((string) Request::get('ids', ''));
        if ($idsRaw !== '') {
            foreach (explode(',', $idsRaw) as $part) {
                $id = (int) trim($part);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return AdminApiResponse::admin(\app\common\service\product\ProductCatalogService::exportAsyncStartAdmin([
            'keyword'   => (string) Request::get('keyword', ''),
            'item_type' => (string) Request::get('item_type', ''),
            'status'    => (string) Request::get('status', ''),
            'tag_id'    => (int) Request::get('tag_id', 0),
        ], $ids));
    }

    public function exportAsyncStep()
    {
        if (!$this->productCenterGate->allowsParams()) {
            return AdminApiResponse::fail('需安装并启用「产品展示」插件');;
        }
        $ids = [];
        $idsRaw = trim((string) Request::get('ids', ''));
        if ($idsRaw !== '') {
            foreach (explode(',', $idsRaw) as $part) {
                $id = (int) trim($part);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return AdminApiResponse::admin(\app\common\service\product\ProductCatalogService::exportAsyncStepAdmin(
            (string) Request::get('job_id', ''),
            [
                'keyword'   => (string) Request::get('keyword', ''),
                'item_type' => (string) Request::get('item_type', ''),
                'status'    => (string) Request::get('status', ''),
                'tag_id'    => (int) Request::get('tag_id', 0),
            ],
            $ids,
        ));
    }

    public function exportAsyncDownload()
    {
        if (!$this->productCenterGate->allowsParams()) {
            return AdminApiResponse::fail('需安装并启用「产品展示」插件');;
        }
        $pack = \app\common\service\product\ProductCatalogService::exportAsyncDownloadAdmin(
            (string) Request::get('job_id', ''),
        );
        if ($pack === null) {
            return AdminApiResponse::fail('导出任务无效或已过期');;
        }

        return $this->adminDataExport->respondPack(
            $pack,
            'text/csv; charset=UTF-8',
            'admin.item.export',
            ['export_scope' => 'all', 'transport' => 'async'],
            '导出 CSV',
            'item.list',
        );
    }
}
