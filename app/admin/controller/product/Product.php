<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\product;

use app\admin\controller\Base;
use app\common\service\auth\CsrfService;
use app\common\service\product\ProductCenterGateService;
use app\common\service\product\ProductConfigService;
use app\common\service\product\ProductService;
use app\common\support\AdminApiResponse;
use app\common\support\ApiResponse;
use think\facade\Request;

/** 产品中心后台 API */
class Product extends Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly ProductCenterGateService $productGate,
    ) {
        parent::__construct($csrf);
    }

    private function gate()
    {
        if (!$this->productGate->allowsAdmin()) {
            $msg = app(\app\common\service\site\SiteCoreLicenseService::class)->proRequiredMessage();

            return AdminApiResponse::admin(
                \app\common\support\ServiceResult::fail(
                    $msg,
                    \app\common\enum\ApiErrorCode::CORE_LICENSE_PRO_REQUIRED
                ),
                403
            );
        }

        return null;
    }

    public function index()
    {
        $blocked = $this->gate();
        if ($blocked !== null) {
            return $blocked;
        }

        return ApiResponse::success([
            'paramGroups' => ProductService::listParamGroupsWithDefs(),
            'params'      => ProductService::listParamDefs(),
        ]);
    }

    public function groupSave()
    {
        $blocked = $this->gate();
        if ($blocked !== null) {
            return $blocked;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin(ProductService::saveParamGroup(Request::post()));
    }

    public function groupDelete()
    {
        $blocked = $this->gate();
        if ($blocked !== null) {
            return $blocked;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin(ProductService::deleteParamGroup((int) Request::post('id', 0)));
    }

    public function groupBatchStatus()
    {
        $blocked = $this->gate();
        if ($blocked !== null) {
            return $blocked;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $ids = Request::post('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }

        return AdminApiResponse::admin(ProductService::batchUpdateParamGroupStatus(
            array_values(array_map('intval', $ids)),
            (int) Request::post('status', 1),
        ));
    }

    public function groupBatchDelete()
    {
        $blocked = $this->gate();
        if ($blocked !== null) {
            return $blocked;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $ids = Request::post('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }

        return AdminApiResponse::admin(ProductService::batchDeleteParamGroups(
            array_values(array_map('intval', $ids)),
        ));
    }

    public function paramSave()
    {
        $blocked = $this->gate();
        if ($blocked !== null) {
            return $blocked;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $post = Request::post();
        // options：JSON 字符串（[{value,label}]）或旧版逗号分隔纯文本；禁再吃 options[]→[object Object]
        if (isset($post['options'])) {
            $post['options'] = self::decodeParamOptionsPost($post['options']);
        }

        return AdminApiResponse::admin(ProductService::saveParamDef($post));
    }

    /**
     * @param mixed $raw
     * @return list<mixed>
     */
    private static function decodeParamOptionsPost(mixed $raw): array
    {
        if (is_array($raw)) {
            // form options[] 若已被 String 成「[object Object]」则丢弃，避免污染库
            $clean = [];
            foreach ($raw as $item) {
                if (is_array($item)) {
                    $clean[] = $item;
                    continue;
                }
                if (!is_string($item) && !is_numeric($item)) {
                    continue;
                }
                $s = trim((string) $item);
                if ($s === '' || $s === '[object Object]') {
                    continue;
                }
                if ($s[0] === '{') {
                    $decoded = json_decode($s, true);
                    if (is_array($decoded)) {
                        $clean[] = $decoded;
                        continue;
                    }
                }
                $clean[] = $s;
            }

            return $clean;
        }
        if (!is_string($raw)) {
            return [];
        }
        $trim = trim($raw);
        if ($trim === '') {
            return [];
        }
        if ($trim[0] === '[') {
            $decoded = json_decode($trim, true);

            return is_array($decoded) ? $decoded : [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\n,，;；]+/u', $trim) ?: [])));
    }

    public function paramDelete()
    {
        $blocked = $this->gate();
        if ($blocked !== null) {
            return $blocked;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin(ProductService::deleteParamDef((int) Request::post('id', 0)));
    }

    public function paramSort()
    {
        $blocked = $this->gate();
        if ($blocked !== null) {
            return $blocked;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $orders = Request::post('orders', []);
        if (!is_array($orders)) {
            $orders = [];
        }

        return AdminApiResponse::admin(ProductService::sortParamDefs($orders));
    }

    public function config()
    {
        $blocked = $this->gate();
        if ($blocked !== null) {
            return $blocked;
        }

        return ApiResponse::success([
            'cfg'            => ProductConfigService::all(),
            'variantNaming'  => ProductConfigService::variantNamingPayload(),
            'stats'          => ProductConfigService::statsAdmin(),
            'health'         => ProductConfigService::healthCheckAdmin(),
            'hostOps'        => ProductConfigService::hostOpsMeta(),
        ]);
    }

    public function configSave()
    {
        $blocked = $this->gate();
        if ($blocked !== null) {
            return $blocked;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin(ProductConfigService::saveAdmin(Request::post()));
    }
}
