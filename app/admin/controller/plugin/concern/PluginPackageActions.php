<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\plugin\concern;

use app\common\service\auth\CsrfService;
use app\common\service\audit\AuditLogService;
use app\common\service\payment\PaymentConfigService;
use app\common\service\plugin\registry\PluginCapabilityService;
use app\common\service\plugin\commerce\PluginCommercialPricingService;
use app\common\service\plugin\gateway\PluginGatewayAuditService;
use app\common\service\plugin\package\PluginInstallBackupService;


use app\common\service\plugin\package\PluginPackageAuditService;
use app\common\service\plugin\commerce\PluginWalletService;
use app\common\service\plugin\commerce\PluginCommerceReportService;
use app\common\support\ServiceResult;
use app\common\enum\ApiErrorCode;
use app\common\support\AdminApiResponse;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\commerce\PluginCommerceService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\service\plugin\commerce\PluginCommercialPackageService;
use app\common\support\LocalFile;
use app\common\service\plugin\manifest\PluginLicenseFileService;
use app\common\service\plugin\package\PluginInstallPreflightService;
use app\common\service\plugin\package\PluginPackageService;
use app\common\service\plugin\security\PluginSecurityPolicyService;
use app\common\service\plugin\scaffold\PluginScaffoldService;

use app\common\service\plugin\commerce\PluginRefundRequestService;
use app\common\service\plugin\scaffold\PluginDeveloperWorkbenchService;
use app\common\service\plugin\weapp\PluginNav;
use app\common\service\plugin\extension\PluginPreflightExtensionAccess;
use app\common\service\release\PivarkEditionService;
use app\common\service\site\SiteCoreLicenseService;
use app\common\support\AppService;
use app\common\support\AdminSpa;
use think\facade\Request;
use think\facade\Session;
use think\Response;

trait PluginPackageActions
{
    public function upload()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $file = Request::file('file');
        if ($file === null) {
            return AdminApiResponse::fail('请选择插件 zip 包');
        }

        $replace = in_array(strtolower(trim((string) Request::post('replace', ''))), ['1', 'true', 'yes'], true);
        $adminId = (int) (Session::get('admin_user.id') ?? 0);

        return AdminApiResponse::admin($this->pluginPackage->installUpload(
            $file->getPathname(),
            (string) $file->getOriginalName(),
            $replace,
            $adminId,
            self::commercialPricingFormFromRequest()
        ));
    }

    public function auditPackage()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $file = Request::file('file');
        if ($file === null) {
            return AdminApiResponse::fail('请选择插件 zip 包');
        }

        $audit = $this->pluginPackageAudit->auditZipFile($file->getPathname());
        $adminId = (int) (Session::get('admin_user.id') ?? 0);
        $verbose = $this->pluginSecurityPolicy->canViewTechnicalAudit($adminId);
        $audit = $this->pluginSecurityPolicy->sanitizeAuditReport($audit, $verbose);

        return AdminApiResponse::fromResult(ServiceResult::ok($audit));
    }

    /** 打包前扫描（文件数 / 体积 / 样例路径），供打包弹窗进度 UI */
    public function packStats()
    {
        $identifier = $this->pluginParamIdentifier(false);

        return AdminApiResponse::admin($this->pluginPackage->packStats($identifier));
    }

    public function export()
    {
        $identifier = $this->pluginParamIdentifier(false);
        $result     = $this->pluginPackage->exportZip($identifier);
        if (!$result->isOk()) {
            return AdminApiResponse::admin($result);
        }
        $path = (string) ($result['file'] ?? '');
        $name = (string) ($result['filename'] ?? ($identifier . '.zip'));
        if ($path === '' || !is_readable($path)) {
            return AdminApiResponse::fail('打包文件不可用');
        }

        // ThinkPHP File 默认把 data 当「磁盘路径」；传二进制会判 is_file 失败 → 前端只见「下载失败」
        return $this->pluginZipFileResponse($path, $name);
    }

    public function exportEncoded()
    {
        $identifier = $this->pluginParamIdentifier(false);
        $result     = $this->pluginCommercialPackage->buildCommercialZip($identifier);
        if (!$result->isOk()) {
            return AdminApiResponse::admin($result);
        }
        $path = (string) ($result['file'] ?? '');
        $name = (string) ($result['filename'] ?? ($identifier . '-encoded.zip'));
        if ($path === '' || !is_readable($path)) {
            return AdminApiResponse::fail('打包文件不可用');
        }

        return $this->pluginZipFileResponse($path, $name);
    }

    private function pluginZipFileResponse(string $path, string $filename): Response
    {
        register_shutdown_function(static function () use ($path): void {
            LocalFile::unlinkIfExists($path);
        });

        return Response::create($path, 'file', 200)
            ->name($filename, false)
            ->mimeType('application/zip');
    }

    public function exportLicense()
    {
        $raw = trim((string) Request::param('identifiers', ''));
        $ids = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($ids === [] && Request::param('bundle', '') === '1') {
            $ids = $this->pluginCommercialPackage->listEncodedIdentifiers();
        }
        $result = $this->pluginLicenseFile->export($ids, [
            'perpetual'    => Request::param('perpetual', '1') !== '0',
            'site_bind'    => Request::param('site_bind', '0') === '1',
            'license_type' => trim((string) Request::param('license_type', 'bundled')),
        ]);
        if (!$result->isOk()) {
            return AdminApiResponse::admin($result);
        }

        return Response::create((string) ($result['content'] ?? ''), 'json', 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . ($result['filename'] ?? 'license.json') . '"',
        ]);
    }

    public function importLicense()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $file = Request::file('file');
        if ($file === null) {
            $body = (string) Request::post('content', '');
            if ($body === '') {
                return AdminApiResponse::fail('请上传 .json 授权文件或粘贴内容');
            }

            return AdminApiResponse::admin($this->pluginLicenseFile->import($body));
        }
        $json = (string) file_get_contents($file->getPathname());
        if ($json === '') {
            return AdminApiResponse::fail('文件为空');
        }

        return AdminApiResponse::admin($this->pluginLicenseFile->import($json));
    }

}
